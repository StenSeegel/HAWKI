<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\Tools\ToolCallAccumulator;
use PHPUnit\Framework\TestCase;

class ToolCallAccumulatorTest extends TestCase
{
    private function delta(array $toolCalls, ?string $finishReason = null): array
    {
        $choice = ['delta' => ['tool_calls' => $toolCalls]];
        if ($finishReason !== null) {
            $choice['finish_reason'] = $finishReason;
        }

        return ['choices' => [$choice]];
    }

    public function test_joins_argument_fragments_split_across_deltas(): void
    {
        // Shape observed on the ki@JLU gateway: the name comes first, then the
        // argument string arrives in pieces across further deltas.
        $accumulator = new ToolCallAccumulator();

        $accumulator->addChunk($this->delta([
            ['index' => 0, 'id' => 'chatcmpl-tool-abc', 'function' => ['name' => 'web_search', 'arguments' => '']],
        ]));
        $accumulator->addChunk($this->delta([['index' => 0, 'function' => ['arguments' => '{"query": "current ']]]));
        $accumulator->addChunk($this->delta([['index' => 0, 'function' => ['arguments' => 'weather in ']]]));
        $accumulator->addChunk($this->delta([['index' => 0, 'function' => ['arguments' => 'Giessen"}']]]));
        $accumulator->addChunk(['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]]);

        $this->assertTrue($accumulator->hasToolCalls());
        $this->assertSame('tool_calls', $accumulator->finishReason());

        $calls = $accumulator->toolCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('chatcmpl-tool-abc', $calls[0]['id']);
        $this->assertSame('web_search', $calls[0]['name']);
        $this->assertSame('{"query": "current weather in Giessen"}', $calls[0]['arguments']);
    }

    public function test_keeps_parallel_calls_apart_by_index(): void
    {
        $accumulator = new ToolCallAccumulator();

        $accumulator->addChunk($this->delta([
            ['index' => 0, 'id' => 'a', 'function' => ['name' => 'web_search', 'arguments' => '{"query":']],
            ['index' => 1, 'id' => 'b', 'function' => ['name' => 'fetch_url', 'arguments' => '{"url":']],
        ]));
        $accumulator->addChunk($this->delta([
            ['index' => 1, 'function' => ['arguments' => '"https://example.com"}']],
            ['index' => 0, 'function' => ['arguments' => '"php"}']],
        ]));

        $calls = $accumulator->toolCalls();

        $this->assertCount(2, $calls);
        $this->assertSame('web_search', $calls[0]['name']);
        $this->assertSame('{"query":"php"}', $calls[0]['arguments']);
        $this->assertSame('fetch_url', $calls[1]['name']);
        $this->assertSame('{"url":"https://example.com"}', $calls[1]['arguments']);
    }

    public function test_falls_back_to_the_delta_position_when_index_is_missing(): void
    {
        $accumulator = new ToolCallAccumulator();

        $accumulator->addChunk($this->delta([
            ['id' => 'a', 'function' => ['name' => 'web_search', 'arguments' => '{"query":"php"}']],
        ]));

        $this->assertCount(1, $accumulator->toolCalls());
        $this->assertSame('web_search', $accumulator->toolCalls()[0]['name']);
    }

    public function test_a_plain_content_stream_has_no_tool_calls(): void
    {
        $accumulator = new ToolCallAccumulator();

        $accumulator->addChunk(['choices' => [['delta' => ['content' => 'Hello']]]]);
        $accumulator->addChunk(['choices' => [['delta' => ['content' => ' world'], 'finish_reason' => 'stop']]]);

        $this->assertFalse($accumulator->hasToolCalls());
        $this->assertSame('stop', $accumulator->finishReason());
        $this->assertSame([], $accumulator->toolCalls());
    }

    public function test_fragments_without_a_name_are_dropped(): void
    {
        $accumulator = new ToolCallAccumulator();

        $accumulator->addChunk($this->delta([['index' => 0, 'function' => ['arguments' => '{"query":"php"}']]]));

        $this->assertFalse($accumulator->hasToolCalls());
    }

    public function test_reads_a_complete_non_streaming_message(): void
    {
        $accumulator = new ToolCallAccumulator();

        $accumulator->addMessage([
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'web_search', 'arguments' => '{"query":"php"}']],
            ],
        ], 'tool_calls');

        $this->assertTrue($accumulator->hasToolCalls());
        $this->assertSame('call_1', $accumulator->toolCalls()[0]['id']);
        $this->assertSame('tool_calls', $accumulator->finishReason());
    }

    public function test_synthesises_an_id_when_the_provider_sent_none(): void
    {
        $accumulator = new ToolCallAccumulator();

        $accumulator->addChunk($this->delta([
            ['index' => 0, 'function' => ['name' => 'web_search', 'arguments' => '{}']],
        ]));

        $this->assertSame('call_0', $accumulator->toolCalls()[0]['id']);
    }

    public function test_assistant_message_carries_the_calls_back_in_api_shape(): void
    {
        $accumulator = new ToolCallAccumulator();
        $accumulator->addChunk($this->delta([
            ['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'web_search', 'arguments' => '{"query":"php"}']],
        ]));

        $message = $accumulator->assistantMessage('thinking out loud');

        $this->assertSame('assistant', $message['role']);
        $this->assertSame('thinking out loud', $message['content']);
        $this->assertSame([
            [
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'web_search', 'arguments' => '{"query":"php"}'],
            ],
        ], $message['tool_calls']);
    }

    public function test_reset_clears_state_between_rounds(): void
    {
        $accumulator = new ToolCallAccumulator();
        $accumulator->addChunk($this->delta([
            ['index' => 0, 'id' => 'a', 'function' => ['name' => 'web_search', 'arguments' => '{}']],
        ], 'tool_calls'));

        $accumulator->reset();

        $this->assertFalse($accumulator->hasToolCalls());
        $this->assertNull($accumulator->finishReason());
    }
}
