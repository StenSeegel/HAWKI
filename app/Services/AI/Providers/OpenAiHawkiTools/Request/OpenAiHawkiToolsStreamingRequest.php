<?php

declare(strict_types=1);

namespace App\Services\AI\Providers\OpenAiHawkiTools\Request;

use App\Services\AI\Providers\OpenAi\Request\OpenAiStreamingRequest;
use App\Services\AI\Tools\HawkiToolInterface;
use App\Services\AI\Tools\ToolCallAccumulator;
use App\Services\AI\Tools\ToolCallRunner;
use App\Services\AI\Utils\TokenUsageAggregator;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiResponse;
use Illuminate\Support\Facades\Log;

/**
 * A streaming chat completions request that can run HAWKI tools mid stream.
 *
 * A tool call cannot be answered inside the running stream: the tool result has
 * to go back to the model as a new message, which means a new upstream request.
 * One round therefore is one upstream stream, and the rounds feed the same
 * callback, so the client sees a single continuous message.
 *
 * Everything that is not about tools (reasoning blocks, status log, content
 * deltas, usage extraction) stays with the plain OpenAI streaming request.
 */
class OpenAiHawkiToolsStreamingRequest extends OpenAiStreamingRequest
{
    public const MAX_TOOL_ROUNDS = OpenAiHawkiToolsNonStreamingRequest::MAX_TOOL_ROUNDS;

    private array $loopPayload;

    private ToolCallAccumulator $accumulator;

    /**
     * Everything the model said across every round, kept so the sources can be
     * completed with the pages the answer itself links. The deltas are gone by
     * the time the finishing chunk arrives, so they are collected on the way.
     */
    private string $answerText = '';

    /** What the model said in the current round only; see withNoAnswerFlag(). */
    private string $roundText = '';

    /**
     * Whether the code interpreter ran for this message. Read at the end: an
     * answer that carries an output block or a sandbox: link without a run is a
     * model that wrote its tool result itself (see fabricationFlag).
     */
    private bool $codeInterpreterRan = false;

    /**
     * Set once the answer has been checked for a tool result the model wrote
     * itself - on the chunk that finishes the message, or after the last round
     * when no chunk did.
     */
    private bool $fabricationChecked = false;

    private TokenUsageAggregator $usageAggregator;

    /**
     * Numbers the generated images of this message. The frontend keys the image
     * container by it, so two images in one message need two indices or the
     * second overwrites the first.
     */
    private int $generatedImageIndex = 0;

    public function __construct(
        array $payload,
        private readonly \Closure $streamCallback,
        /** @var array<string, HawkiToolInterface> */
        private readonly array $tools,
        private readonly ToolCallRunner $runner,
        /**
         * The MCP server the provider pinned per tool. One entry per tool: a
         * single server for the whole request misrouted a call whenever two
         * tools were active.
         *
         * @var array<string, string|null>
         */
        private readonly array $serverBindings = [],
    ) {
        parent::__construct($payload, $streamCallback);

        $this->loopPayload = $payload;
        $this->accumulator = new ToolCallAccumulator();
        $this->usageAggregator = new TokenUsageAggregator();
    }

    public function execute(AiModel $model): void
    {
        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            // In the final round the tools are withdrawn, so the model answers
            // instead of asking for a call that would no longer be executed.
            if ($round === self::MAX_TOOL_ROUNDS) {
                unset($this->loopPayload['tools'], $this->loopPayload['tool_choice']);
            }

            $this->accumulator->reset();
            $this->roundText = '';

            $this->loopPayload['stream'] = true;
            $this->loopPayload['stream_options'] = ['include_usage' => true];

            $this->executeStreamingRequest(
                model: $model,
                payload: $this->loopPayload,
                onData: $this->streamCallback,
                chunkToResponse: [$this, 'chunkToResponse']
            );

            if ($this->tools === [] || ! $this->accumulator->hasToolCalls()) {
                break;
            }

            if ($this->wasConnectionAborted()) {
                break;
            }

            // No further request follows the final round, so a tool result could not
            // be handed back to the model. Running the tool anyway would cost time
            // and money for an answer nobody reads.
            if ($round === self::MAX_TOOL_ROUNDS) {
                Log::warning('[OpenAiHawkiTools] Model still requested tools in the final round', [
                    'model' => $model->getId(),
                    'requested' => array_column($this->accumulator->toolCalls(), 'name'),
                ]);
                break;
            }

            $this->runToolRound($round);
        }

        $flag = $this->fabricationFlagAfterStream($model);
        if ($flag !== null) {
            ($this->streamCallback)($flag);
        }
    }

    /**
     * A model that links a sandbox: file without a code interpreter call made
     * the file up. gemma-4 answered "Du kannst die fertige Datei hier
     * herunterladen" eleven seconds after the prompt, and a regenerate once
     * reproduced the previous turn verbatim - python block, output block,
     * delivery note and link - without a single tool call. The link points at
     * nothing, and the rest of such an answer is no more trustworthy than the link.
     *
     * Such an answer is marked with a 'fabricated_tool_result' auxiliary, and the
     * frontend replaces it as a whole with a failure message before it is saved.
     * Only a Markdown link outside code triggers that: it names a file this
     * message had to produce, while a sandbox: path in an explanation or a code
     * example, or an output block, is something a model also writes for good
     * reasons. Every finding is logged.
     *
     * @return array{type: string, content: string}|null
     */
    private function fabricationFlag(AiModel $model): ?array
    {
        $this->fabricationChecked = true;

        if ($this->codeInterpreterRan) {
            return null;
        }

        $prose = (string) preg_replace(['/```.*?(?:```|\z)/s', '/`[^`\n]*`/'], '', $this->answerText);

        $fabricated = [];
        if (str_contains($this->answerText, '```output')) {
            $fabricated[] = 'output block';
        }
        if (preg_match('/\]\(\s*<?sandbox:\/tmp\//', $prose) === 1) {
            $fabricated[] = 'sandbox link';
        }
        if (preg_match('/\[(?:file|image) \d+ was produced/', $this->answerText) === 1) {
            $fabricated[] = 'delivery note';
        }

        if ($fabricated === []) {
            return null;
        }

        Log::warning('[OpenAiHawkiTools] Answer contains a tool result the model wrote itself - no code interpreter call ran', [
            'model' => $model->getId(),
            'found' => $fabricated,
        ]);

        if (! in_array('sandbox link', $fabricated, true)) {
            return null;
        }

        return [
            'type' => 'fabricated_tool_result',
            'content' => json_encode(['found' => $fabricated]),
        ];
    }

    /**
     * The flag rides the chunk that finishes the message: the client stops
     * reading at the first chunk marked done, so anything sent after it is
     * never seen. The answer text is complete by then.
     */
    private function withFabricationFlag(AiModel $model, AiResponse $response): AiResponse
    {
        if ($this->fabricationChecked || ! $this->isFinishingChunk($response)) {
            return $response;
        }

        $flag = $this->fabricationFlag($model);
        if ($flag === null) {
            return $response;
        }

        $content = $response->content;
        $content['auxiliaries'][] = $flag;

        return new AiResponse(
            content: $content,
            usage: $response->usage,
            isDone: $response->isDone,
            error: $response->error
        );
    }

    /**
     * A stream that ended without a finishing chunk - at the token limit, say -
     * gets the flag as a chunk of its own. The client reads such a stream until
     * the connection closes, so this one arrives.
     */
    protected function fabricationFlagAfterStream(AiModel $model): ?AiResponse
    {
        if ($this->fabricationChecked) {
            return null;
        }

        $flag = $this->fabricationFlag($model);
        if ($flag === null) {
            return null;
        }

        return new AiResponse(
            content: ['text' => '', 'auxiliaries' => [$flag]],
            isDone: false
        );
    }

    /**
     * A final round that ends without a word of text is marked with a
     * 'no_answer' auxiliary, and the frontend shows its failure message in place
     * of the empty answer, which would otherwise be saved as a blank message
     * under the sources of the searches that did run.
     *
     * Only the final round counts: there the tools are withdrawn and the model
     * has nothing left to do but answer. Text from earlier rounds ("let me
     * search for ...") is no answer either, so the current round's text decides.
     */
    private function withNoAnswerFlag(AiModel $model, AiResponse $response): AiResponse
    {
        $toolsWithdrawn = ! isset($this->loopPayload['tools']);

        if (! $toolsWithdrawn || ! $this->isFinishingChunk($response) || trim($this->roundText) !== '') {
            return $response;
        }

        Log::warning('[OpenAiHawkiTools] The final round ended without an answer', [
            'model' => $model->getId(),
        ]);

        $content = $response->content;
        $content['auxiliaries'][] = [
            'type' => 'no_answer',
            'content' => '{}',
        ];

        return new AiResponse(
            content: $content,
            usage: $response->usage,
            isDone: $response->isDone,
            error: $response->error
        );
    }

    /**
     * Whether this chunk ends the message, not just a round. Another round
     * follows a tool call - except in the final round, where the tools are gone
     * and a model that still asked for one gets no further round.
     */
    private function isFinishingChunk(AiResponse $response): bool
    {
        if (! $response->isDone) {
            return false;
        }

        $toolsWithdrawn = ! isset($this->loopPayload['tools']);

        return ! $this->accumulator->hasToolCalls() || $toolsWithdrawn;
    }

    /**
     * Execute the tool calls of one round and append their results to the conversation.
     */
    private function runToolRound(int $round): void
    {
        $this->loopPayload['messages'][] = $this->accumulator->assistantMessage();

        foreach ($this->accumulator->toolCalls() as $call) {
            Log::info('[OpenAiHawkiTools] Running tool mid stream', [
                'round' => $round + 1,
                'tool' => $call['name'],
            ]);

            $query = $this->describeCall($call['arguments']);

            // Logged as well as sent, so the step survives a reload the way the
            // provider side tools' steps do.
            $this->addStatusToLog($call['name'], 'in_progress', $query, 0);
            $this->emitToolStatus($call['name'], 'in_progress', $query);

            $result = $this->runner->run(
                $this->tools,
                $call['name'],
                $call['arguments'],
                $this->serverBindings[$call['name']] ?? null
            );
            $this->usageAggregator->countToolUse($call['name']);
            if ($call['name'] === 'code_interpreter') {
                $this->codeInterpreterRan = true;
            }

            $this->addStatusToLog($call['name'], 'completed', $query, 0);
            $this->emitToolStatus($call['name'], 'completed', $query);

            // The executed code and what it printed, written into the message -
            // the same thing the native code interpreter does, so a model on a
            // gateway without its own sandbox shows the user the same evidence.
            $this->emitCodeInterpreterCall($call['name'], $call['arguments'], $result);

            // The images the tool produced - a sandbox plot or a generated
            // picture. The tool kept the base64 away from the model; this is what
            // puts the image in front of the user.
            $this->emitToolImages($call['name']);

            $this->loopPayload['messages'][] = [
                'role' => 'tool',
                'tool_call_id' => $call['id'],
                'name' => $call['name'],
                'content' => OpenAiHawkiToolsNonStreamingRequest::withToolBudgetNote($result, $round),
            ];
        }
    }

    /**
     * Tell the client that a tool is running, so the UI can show the step instead
     * of an idle stream while the tool call is on the wire.
     *
     * The status types match the ones the frontend already labels for provider side
     * tools ('web_search' with 'in_progress' / 'completed'), so no client change is
     * needed to render a HAWKI executed tool.
     */
    private function emitToolStatus(string $tool, string $status, ?string $query = null): void
    {
        $payload = [
            'status' => $status,
            'type' => $tool,
            'output_index' => 0,
        ];

        if ($query !== null) {
            $payload['query'] = $query;
        }

        ($this->streamCallback)(new AiResponse(
            content: [
                'text' => '',
                'auxiliaries' => [[
                    'type' => 'status',
                    'content' => json_encode($payload),
                ]],
            ],
            isDone: false
        ));
    }

    /**
     * Renders a finished code interpreter call into the message: a fenced python
     * block with the code, and one with whatever the sandbox printed.
     *
     * The model already has the result - it comes back as the tool message - so
     * this is for the user, and for the next turn, which carries the message text
     * rather than the tool exchange.
     */
    private function emitCodeInterpreterCall(string $tool, string $rawArguments, string $result): void
    {
        if ($tool !== 'code_interpreter') {
            return;
        }

        $decoded = json_decode($rawArguments, true);
        $code = is_array($decoded) ? rtrim((string) ($decoded['code'] ?? '')) : '';

        if ($code === '') {
            return;
        }

        $text = "\n\n```python\n".$code."\n```";

        $output = trim($result);
        if ($output !== '') {
            $text .= "\n\n```output\n".$output."\n```";
        }

        ($this->streamCallback)(new AiResponse(
            content: ['text' => $text."\n\n"],
            isDone: false
        ));
    }

    /**
     * Forwards the images a tool collected, in the shape the tool's own kind of
     * picture is rendered in.
     *
     * A sandbox plot is written into the message as markdown, right after the code
     * and output blocks the call just produced, because that is where a chart
     * belongs. Its 'generated_image' auxiliary carries 'inline' so the frontend
     * does not ALSO draw its container - which is inserted above the message
     * content, and would show the plot a second time above the code that drew it.
     *
     * A generated image is the opposite case: it belongs in the image container,
     * with the frame, the prompt caption, the download button and the offer to
     * attach it to the next message - the same component the provider side image
     * tool renders into. So it goes out without 'inline' and without markdown,
     * exactly as the Responses provider emits its images.
     *
     * A file the sandbox built - a deck, a spreadsheet - is neither. It goes out
     * as a 'container_file', the auxiliary the native code interpreter's files
     * arrive as: the frontend remembers it by name and points the model's
     * sandbox:/tmp/<name> link at the stored file, as a download.
     */
    private function emitToolImages(string $tool): void
    {
        $images = app(\App\Services\AI\Tools\SandboxImages::class)->drain();

        if ($images === []) {
            return;
        }

        $inline = $tool !== \App\Services\AI\Tools\ImageGenerationTool::KEY;

        $auxiliaries = [];
        $text = '';

        foreach ($images as $image) {
            if (($image['kind'] ?? null) === 'file') {
                unset($image['kind']);
                $image['output_index'] = $this->generatedImageIndex++;

                $auxiliaries[] = [
                    'type' => 'container_file',
                    'content' => json_encode($image),
                ];

                continue;
            }

            $image['output_index'] = $this->generatedImageIndex++;

            if ($inline) {
                $image['inline'] = true;
                $text .= '!['.$image['prompt'].']('.$image['url'].")\n\n";
            }

            $auxiliaries[] = [
                'type' => 'generated_image',
                'content' => json_encode($image),
            ];
        }

        ($this->streamCallback)(new AiResponse(
            content: ['text' => $text, 'auxiliaries' => $auxiliaries],
            isDone: false
        ));
    }

    /**
     * Attaches the sources a web search used to the response that finishes the
     * message, as the 'hawkiToolsCitations' auxiliary: the frontend draws the
     * numbered indices the way Google's grounding does and the source list the
     * way Anthropic's does.
     *
     * Attached to the finishing chunk and nowhere else. The client keeps every
     * auxiliary whose content differs from one it already has, and reads the
     * first citations auxiliary it finds: emitting the growing list per round
     * would persist the first, partial one and show that after a reload.
     */
    private function withCitations(AiResponse $response): AiResponse
    {
        // In the final round a model that still asked for a tool gets no further
        // round - the sources of the rounds that did run would be lost with it,
        // so they are emitted anyway.
        if (! $this->isFinishingChunk($response)) {
            return $response;
        }

        $sources = app(\App\Services\AI\Tools\WebSearchSources::class);
        $sources->collectFromAnswer($this->answerText);
        $citations = $sources->drain();

        if ($citations === []) {
            return $response;
        }

        $content = $response->content;
        $content['auxiliaries'][] = [
            'type' => 'hawkiToolsCitations',
            'content' => json_encode(['citations' => $citations]),
        ];

        return new AiResponse(
            content: $content,
            usage: $response->usage,
            isDone: $response->isDone,
            error: $response->error
        );
    }

    /**
     * A short, human readable description of what the tool was asked to do, shown
     * next to the status step. Best effort only: the raw arguments may be malformed,
     * in which case the status simply carries no query.
     */
    private function describeCall(string $rawArguments): ?string
    {
        $decoded = json_decode($rawArguments, true);
        if (! is_array($decoded)) {
            return null;
        }

        foreach (['query', 'url', 'topic'] as $key) {
            if (! empty($decoded[$key]) && is_string($decoded[$key])) {
                // Strip chat template artifacts so they never reach the UI.
                $value = trim(preg_replace('/<\|[^|>]*\|>/u', '', $decoded[$key]) ?? '');

                return $value === '' ? null : mb_substr($value, 0, 120);
            }
        }

        // A batch read of several pages carries no query of its own; the pages
        // it reads are what the step should name.
        if (! empty($decoded['urls']) && is_array($decoded['urls'])) {
            $urls = array_filter($decoded['urls'], 'is_string');

            return $urls === [] ? null : mb_substr(implode(', ', $urls), 0, 120);
        }

        return null;
    }

    /**
     * Removes a chat template's end-of-turn marker that reached the text.
     *
     * gemma-4 behind the gateway ended an answer with a literal "<turn|>" after
     * a turn whose history carried a rebuilt tool exchange: the model wrote its
     * template's own delimiter as text, and the user saw it. Such a marker is
     * never something the model means to say, so it is dropped wherever it
     * appears in a delta.
     */
    public static function stripLeakedTurnMarkers(string $text): string
    {
        if (! str_contains($text, '<')) {
            return $text;
        }

        return (string) preg_replace(
            '/<\|?(?:turn|end_of_turn|start_of_turn|im_end|im_start|eot_id|eom_id)\|?>/',
            '',
            $text
        );
    }

    protected function chunkToResponse(AiModel $model, string $chunk): AiResponse
    {
        // Collect tool call fragments before the parent turns the chunk into text.
        // Arguments arrive split across several deltas, so this has to see them all.
        $decoded = json_decode($chunk, true);
        if (is_array($decoded)) {
            $this->accumulator->addChunk($decoded);
        }

        $response = parent::chunkToResponse($model, $chunk);

        $text = $response->content['text'] ?? null;
        if (is_string($text)) {
            $cleaned = self::stripLeakedTurnMarkers($text);
            if ($cleaned !== $text) {
                $response = new AiResponse(
                    content: ['text' => $cleaned] + $response->content,
                    usage: $response->usage,
                    isDone: $response->isDone,
                    error: $response->error,
                );
            }
            $this->answerText .= $cleaned;
            $this->roundText .= $cleaned;
        }

        $response = $this->withCitations($response);
        $response = $this->withFabricationFlag($model, $response);
        $response = $this->withNoAnswerFlag($model, $response);

        if ($response->usage === null) {
            return $response;
        }

        $this->usageAggregator->add($response->usage);

        // Usage is reported per upstream request, and consumers overwrite the usage
        // record with whatever they last received. While further rounds are coming,
        // the numbers are therefore held back and only the running total is passed
        // on once the model stops asking for tools.
        return new AiResponse(
            content: $response->content,
            usage: $this->accumulator->hasToolCalls()
                ? null
                : $this->usageAggregator->toTokenUsage($model),
            isDone: $response->isDone,
            error: $response->error
        );
    }
}
