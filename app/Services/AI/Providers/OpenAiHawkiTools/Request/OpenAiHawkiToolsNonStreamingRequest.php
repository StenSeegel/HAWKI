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
     *
     * Four, because a researched answer needs search, then a batch read of the
     * hits, then the one page that carries the answer - three calls, which left
     * nothing for a follow-up search when the first one missed. The alternative
     * would be a single deep research call over 8 to 10 sources, and that is the
     * thing that runs into the tool timeout.
     */
    public const MAX_TOOL_ROUNDS = 4;

    /** Numbers the images of this message; see {@see collectToolImages()}. */
    private int $imageIndex = 0;

    public function __construct(
        private array $payload,
        /** @var array<string, HawkiToolInterface> */
        private readonly array $tools,
        private readonly ToolCallRunner $runner,
        /**
         * The MCP server the provider pinned per tool; see the streaming request.
         *
         * @var array<string, string|null>
         */
        private readonly array $serverBindings = [],
    ) {}

    public function execute(AiModel $model): AiResponse
    {
        $this->payload['stream'] = false;

        $usage = new TokenUsageAggregator();
        $text = '';

        // Fenced blocks for every code interpreter call of every round.
        $codeBlocks = [];

        /** @var array<int,array{type: string, content: string}> */
        $imageAuxiliaries = [];

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

                $result = $this->runner->run(
                    $this->tools,
                    $call['name'],
                    $call['arguments'],
                    $this->serverBindings[$call['name']] ?? null
                );
                $usage->countToolUse($call['name']);

                // The executed code and its output, so a non-streamed answer shows
                // the run the same way the streamed one does.
                $codeBlocks = array_merge(
                    $codeBlocks,
                    $this->renderCodeInterpreterCall($call['name'], $call['arguments'], $result)
                );

                // The pictures the call produced, in the same two shapes the
                // streamed request emits them in.
                foreach ($this->collectToolImages($call['name']) as $image) {
                    if ($image['inline'] ?? false) {
                        $codeBlocks[] = '!['.$image['prompt'].']('.$image['url'].')';
                    }

                    $imageAuxiliaries[] = [
                        'type' => 'generated_image',
                        'content' => json_encode($image),
                    ];
                }

                $this->payload['messages'][] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'name' => $call['name'],
                    'content' => $result,
                ];
            }
        }

        if ($codeBlocks !== []) {
            // In front of the answer, which is the order it happened in.
            $text = implode("\n\n", $codeBlocks)."\n\n".$text;
        }

        $content = ['text' => $text];

        if ($imageAuxiliaries !== []) {
            $content['auxiliaries'] = $imageAuxiliaries;
        }

        // The sources a web search used, plus the pages the answer itself links.
        $sources = app(\App\Services\AI\Tools\WebSearchSources::class);
        $sources->collectFromAnswer($text);
        $citations = $sources->drain();
        if ($citations !== []) {
            $content['auxiliaries'][] = [
                'type' => 'hawkiToolsCitations',
                'content' => json_encode(['citations' => $citations]),
            ];
        }

        return new AiResponse(
            content: $content,
            usage: $usage->toTokenUsage($model)
        );
    }

    /**
     * The images one finished tool call produced, described the way the frontend
     * needs them.
     *
     * A sandbox plot gets 'inline': it is written into the message as markdown
     * next to the code that drew it, and the frontend must not draw its image
     * container as well. A generated image gets none, so it lands in that
     * container - the component the provider side image tool renders into.
     *
     * @return array<int,array<string,mixed>>
     */
    private function collectToolImages(string $tool): array
    {
        $images = app(\App\Services\AI\Tools\SandboxImages::class)->drain();
        $inline = $tool !== \App\Services\AI\Tools\ImageGenerationTool::KEY;

        $described = [];

        foreach ($images as $image) {
            // The frontend keys the image container by this, so every image of
            // the message needs its own index across all rounds.
            $image['output_index'] = $this->imageIndex++;

            if ($inline) {
                $image['inline'] = true;
            }

            $described[] = $image;
        }

        return $described;
    }

    /**
     * The fenced blocks for one finished code interpreter call: the code, and what
     * the sandbox printed. Empty for every other tool.
     *
     * @return array<int,string>
     */
    private function renderCodeInterpreterCall(string $tool, string $rawArguments, string $result): array
    {
        if ($tool !== 'code_interpreter') {
            return [];
        }

        $decoded = json_decode($rawArguments, true);
        $code = is_array($decoded) ? rtrim((string) ($decoded['code'] ?? '')) : '';

        if ($code === '') {
            return [];
        }

        $blocks = ["```python\n".$code."\n```"];

        $output = trim($result);
        if ($output !== '') {
            $blocks[] = "```output\n".$output."\n```";
        }

        return $blocks;
    }
}
