<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\Utils\TokenUsageAggregator;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\TokenUsage;
use PHPUnit\Framework\TestCase;

class TokenUsageAggregatorTest extends TestCase
{
    private AiModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = $this->createMock(AiModel::class);
        $this->model->method('getId')->willReturn('jlu/gemma-4-26b-it');
    }

    private function usage(int $prompt, int $completion, ?array $serverToolUse = null): TokenUsage
    {
        return new TokenUsage(
            model: $this->model,
            promptTokens: $prompt,
            completionTokens: $completion,
            serverToolUse: $serverToolUse,
        );
    }

    public function test_nothing_added_reports_no_usage(): void
    {
        $aggregator = new TokenUsageAggregator();

        $this->assertTrue($aggregator->isEmpty());
        $this->assertNull($aggregator->toTokenUsage($this->model));
    }

    public function test_null_usage_is_ignored(): void
    {
        $aggregator = new TokenUsageAggregator();
        $aggregator->add(null);

        $this->assertTrue($aggregator->isEmpty());
    }

    public function test_sums_the_rounds_of_a_tool_loop(): void
    {
        // Two upstream requests: the tool call turn and the answering turn.
        $aggregator = new TokenUsageAggregator();
        $aggregator->add($this->usage(120, 20));
        $aggregator->add($this->usage(900, 180));

        $total = $aggregator->toTokenUsage($this->model);

        $this->assertNotNull($total);
        $this->assertSame(1020, $total->promptTokens);
        $this->assertSame(200, $total->completionTokens);
        $this->assertSame(1220, $total->totalTokens);
    }

    public function test_sums_extended_token_types(): void
    {
        $aggregator = new TokenUsageAggregator();
        $aggregator->add(new TokenUsage(
            model: $this->model,
            promptTokens: 10,
            completionTokens: 5,
            cacheReadInputTokens: 3,
            reasoningTokens: 7,
        ));
        $aggregator->add(new TokenUsage(
            model: $this->model,
            promptTokens: 20,
            completionTokens: 10,
            cacheReadInputTokens: 4,
            reasoningTokens: 1,
        ));

        $total = $aggregator->toTokenUsage($this->model);

        $this->assertSame(7, $total->cacheReadInputTokens);
        $this->assertSame(8, $total->reasoningTokens);
    }

    public function test_counts_hawki_tool_executions(): void
    {
        $aggregator = new TokenUsageAggregator();
        $aggregator->add($this->usage(10, 5));
        $aggregator->countToolUse('web_search');
        $aggregator->countToolUse('web_search');

        $total = $aggregator->toTokenUsage($this->model);

        $this->assertSame(['web_search' => 2], $total->serverToolUse);
    }

    public function test_merges_provider_tool_use_with_hawki_tool_use(): void
    {
        $aggregator = new TokenUsageAggregator();
        $aggregator->add($this->usage(10, 5, ['web_search_preview' => 2]));
        $aggregator->countToolUse('web_search');

        $total = $aggregator->toTokenUsage($this->model);

        $this->assertSame(['web_search_preview' => 2, 'web_search' => 1], $total->serverToolUse);
    }

    public function test_tool_use_alone_still_reports_usage(): void
    {
        $aggregator = new TokenUsageAggregator();
        $aggregator->countToolUse('web_search');

        $total = $aggregator->toTokenUsage($this->model);

        $this->assertNotNull($total);
        $this->assertSame(0, $total->promptTokens);
        $this->assertSame(['web_search' => 1], $total->serverToolUse);
    }
}
