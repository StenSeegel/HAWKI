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

    private TokenUsageAggregator $usageAggregator;

    public function __construct(
        array $payload,
        private readonly \Closure $streamCallback,
        /** @var array<string, HawkiToolInterface> */
        private readonly array $tools,
        private readonly ToolCallRunner $runner,
        private readonly ?string $serverBinding = null,
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

            $this->emitToolStatus($call['name'], 'in_progress');

            $result = $this->runner->run($this->tools, $call['name'], $call['arguments'], $this->serverBinding);
            $this->usageAggregator->countToolUse($call['name']);

            $this->emitToolStatus($call['name'], 'completed');

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
     */
    private function emitToolStatus(string $tool, string $status): void
    {
        ($this->streamCallback)(new AiResponse(
            content: [
                'text' => '',
                'auxiliaries' => [[
                    'type' => 'status',
                    'content' => json_encode([
                        'status' => $status,
                        'type' => $tool,
                        'output_index' => 0,
                    ]),
                ]],
            ],
            isDone: false
        ));
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
