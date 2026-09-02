<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * Collects the tool calls of one model turn.
 *
 * Streaming responses do not deliver a tool call in one piece: the name arrives in
 * the first delta and the arguments follow as a string split across further deltas
 * (3 to 10 of them, depending on the model). Deltas address their call by index,
 * so fragments are joined per index here.
 *
 * The same accumulator takes a complete non-streaming message, so both paths share
 * one representation of "what did the model want to call".
 */
class ToolCallAccumulator
{
    /**
     * index => ['id' => string, 'name' => string, 'arguments' => string]
     *
     * @var array<int, array{id: string, name: string, arguments: string}>
     */
    private array $calls = [];

    private ?string $finishReason = null;

    /**
     * Feed one decoded streaming chunk.
     */
    public function addChunk(array $chunk): void
    {
        $choice = $chunk['choices'][0] ?? [];

        foreach ($choice['delta']['tool_calls'] ?? [] as $position => $fragment) {
            // Providers may omit the index; fall back to the position in the delta.
            $index = $fragment['index'] ?? $position;

            if (! isset($this->calls[$index])) {
                $this->calls[$index] = ['id' => '', 'name' => '', 'arguments' => ''];
            }

            if (! empty($fragment['id'])) {
                $this->calls[$index]['id'] = (string) $fragment['id'];
            }

            if (! empty($fragment['function']['name'])) {
                $this->calls[$index]['name'] = (string) $fragment['function']['name'];
            }

            if (isset($fragment['function']['arguments']) && is_string($fragment['function']['arguments'])) {
                $this->calls[$index]['arguments'] .= $fragment['function']['arguments'];
            }
        }

        if (! empty($choice['finish_reason'])) {
            $this->finishReason = (string) $choice['finish_reason'];
        }
    }

    /**
     * Feed a complete non-streaming message.
     */
    public function addMessage(array $message, ?string $finishReason = null): void
    {
        foreach ($message['tool_calls'] ?? [] as $position => $call) {
            $index = $call['index'] ?? $position;

            $this->calls[$index] = [
                'id' => (string) ($call['id'] ?? ''),
                'name' => (string) ($call['function']['name'] ?? ''),
                'arguments' => (string) ($call['function']['arguments'] ?? ''),
            ];
        }

        if ($finishReason !== null) {
            $this->finishReason = $finishReason;
        }
    }

    public function finishReason(): ?string
    {
        return $this->finishReason;
    }

    /**
     * Whether the model asked for at least one usable tool call.
     */
    public function hasToolCalls(): bool
    {
        return $this->toolCalls() !== [];
    }

    /**
     * The completed tool calls, in call order, dropping fragments that never got a name.
     *
     * @return array<int, array{id: string, name: string, arguments: string}>
     */
    public function toolCalls(): array
    {
        $calls = array_filter($this->calls, fn (array $call) => $call['name'] !== '');
        ksort($calls);

        return array_values(array_map(
            fn (array $call, int $position) => [
                'id' => $call['id'] !== '' ? $call['id'] : 'call_'.$position,
                'name' => $call['name'],
                'arguments' => $call['arguments'],
            ],
            $calls,
            array_keys($calls)
        ));
    }

    /**
     * The assistant message to append to the conversation before the tool results,
     * in the shape the API expects it back.
     */
    public function assistantMessage(?string $content = null): array
    {
        return [
            'role' => 'assistant',
            'content' => $content,
            'tool_calls' => array_map(
                fn (array $call) => [
                    'id' => $call['id'],
                    'type' => 'function',
                    'function' => ['name' => $call['name'], 'arguments' => $call['arguments']],
                ],
                $this->toolCalls()
            ),
        ];
    }

    public function reset(): void
    {
        $this->calls = [];
        $this->finishReason = null;
    }
}
