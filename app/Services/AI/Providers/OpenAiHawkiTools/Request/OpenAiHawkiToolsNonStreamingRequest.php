<?php

declare(strict_types=1);

namespace App\Services\AI\Providers\OpenAiHawkiTools\Request;

use App\Services\AI\Providers\AbstractRequest;
use App\Services\AI\Providers\OpenAi\Request\OpenAiUsageTrait;
use App\Services\AI\Tools\HawkiToolInterface;
use App\Services\AI\Tools\ToolCallAccumulator;
use App\Services\AI\Tools\ToolCallRunner;
use App\Services\AI\Utils\TokenUsageAggregator;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiResponse;
use Illuminate\Support\Facades\Log;

/**
 * A non streaming chat completions request that runs HAWKI tools when the model
 * asks for them, and keeps asking the model until it answers without a tool call.
 */
class OpenAiHawkiToolsNonStreamingRequest extends AbstractRequest
{
    use OpenAiUsageTrait;

    /**
     * Upper bound of tool rounds per request. Every round is a full upstream
     * request, so this also bounds the time and the cost of a single message.
     */
    public const MAX_TOOL_ROUNDS = 3;

    public function __construct(
        private array $payload,
        /** @var array<string, HawkiToolInterface> */
        private readonly array $tools,
        private readonly ToolCallRunner $runner,
        private readonly ?string $serverBinding = null,
    ) {}

    public function execute(AiModel $model): AiResponse
    {
        $this->payload['stream'] = false;

        $usage = new TokenUsageAggregator();
        $text = '';

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            // The last round is answered without tools, so the model has to conclude
            // instead of asking for yet another call it will not get.
            if ($round === self::MAX_TOOL_ROUNDS) {
                unset($this->payload['tools'], $this->payload['tool_choice']);
            }

            $data = null;
            $response = $this->executeNonStreamingRequest(
                model: $model,
                payload: $this->payload,
                dataToResponse: function (array $payload) use ($model, &$data) {
                    $data = $payload;

                    return new AiResponse(
                        content: ['text' => $payload['choices'][0]['message']['content'] ?? ''],
                        usage: $this->extractUsage($model, $payload)
                    );
                }
            );

            $usage->add($response->usage);

            if ($response->error !== null || $data === null) {
                return new AiResponse(
                    content: $response->content,
                    usage: $usage->toTokenUsage($model),
                    error: $response->error
                );
            }

            $message = $data['choices'][0]['message'] ?? [];
            $text = (string) ($message['content'] ?? '');

            $accumulator = new ToolCallAccumulator();
            $accumulator->addMessage($message, $data['choices'][0]['finish_reason'] ?? null);

            if ($this->tools === [] || ! $accumulator->hasToolCalls()) {
                break;
            }

            // No further request follows the final round, so a tool result could not
            // be handed back to the model. Running the tool anyway would cost time
            // and money for an answer nobody reads.
            if ($round === self::MAX_TOOL_ROUNDS) {
                Log::warning('[OpenAiHawkiTools] Model still requested tools in the final round', [
                    'model' => $model->getId(),
                    'requested' => array_column($accumulator->toolCalls(), 'name'),
                ]);
                break;
            }

            $this->payload['messages'][] = $accumulator->assistantMessage($text === '' ? null : $text);

            foreach ($accumulator->toolCalls() as $call) {
                Log::info('[OpenAiHawkiTools] Running tool', [
                    'round' => $round + 1,
                    'tool' => $call['name'],
                ]);

                $result = $this->runner->run($this->tools, $call['name'], $call['arguments'], $this->serverBinding);
                $usage->countToolUse($call['name']);

                $this->payload['messages'][] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'name' => $call['name'],
                    'content' => $result,
                ];
            }
        }

        return new AiResponse(
            content: ['text' => $text],
            usage: $usage->toTokenUsage($model)
        );
    }
}
