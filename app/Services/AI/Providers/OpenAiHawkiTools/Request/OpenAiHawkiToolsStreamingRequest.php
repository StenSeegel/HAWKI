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
                'content' => $result,
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
        if (! $response->isDone) {
            return $response;
        }

        // Another round follows, so this is not the end of the message yet. In
        // the final round the tools are gone, and a model that still asked for
        // one gets no further round - the sources of the rounds that did run
        // would be lost with it, so they are emitted anyway.
        $toolsWithdrawn = ! isset($this->loopPayload['tools']);
        if ($this->accumulator->hasToolCalls() && ! $toolsWithdrawn) {
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

    protected function chunkToResponse(AiModel $model, string $chunk): AiResponse
    {
        // Collect tool call fragments before the parent turns the chunk into text.
        // Arguments arrive split across several deltas, so this has to see them all.
        $decoded = json_decode($chunk, true);
        if (is_array($decoded)) {
            $this->accumulator->addChunk($decoded);
        }

        $response = parent::chunkToResponse($model, $chunk);

        if (is_string($response->content['text'] ?? null)) {
            $this->answerText .= $response->content['text'];
        }

        $response = $this->withCitations($response);

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
