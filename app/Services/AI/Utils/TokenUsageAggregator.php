<?php

declare(strict_types=1);

namespace App\Services\AI\Utils;

use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\TokenUsage;

/**
 * Sums the token usage of the single rounds a tool loop needs.
 *
 * A tool call turns one user message into several upstream requests, each
 * reporting its own usage. Consumers record usage per response
 * ({@see \App\Http\Controllers\StreamController}), so handing them the raw
 * per round numbers would let the last round overwrite the earlier ones and
 * undercount the request. The loop therefore collects here and reports once.
 */
class TokenUsageAggregator
{
    private int $promptTokens = 0;

    private int $completionTokens = 0;

    private int $totalTokens = 0;

    private int $cacheReadInputTokens = 0;

    private int $cacheCreationInputTokens = 0;

    private int $reasoningTokens = 0;

    private int $audioInputTokens = 0;

    private int $audioOutputTokens = 0;

    private array $serverToolUse = [];

    private bool $empty = true;

    public function add(?TokenUsage $usage): void
    {
        if ($usage === null) {
            return;
        }

        $this->empty = false;
        $this->promptTokens += $usage->promptTokens;
        $this->completionTokens += $usage->completionTokens;
        $this->totalTokens += $usage->totalTokens;
        $this->cacheReadInputTokens += $usage->cacheReadInputTokens;
        $this->cacheCreationInputTokens += $usage->cacheCreationInputTokens;
        $this->reasoningTokens += $usage->reasoningTokens;
        $this->audioInputTokens += $usage->audioInputTokens;
        $this->audioOutputTokens += $usage->audioOutputTokens;

        foreach ($usage->serverToolUse ?? [] as $tool => $count) {
            $this->serverToolUse[$tool] = ($this->serverToolUse[$tool] ?? 0) + $count;
        }
    }

    /**
     * Count one HAWKI side tool execution, so tool use shows up in the usage record
     * the same way provider side tool use does.
     */
    public function countToolUse(string $tool): void
    {
        $this->empty = false;
        $this->serverToolUse[$tool] = ($this->serverToolUse[$tool] ?? 0) + 1;
    }

    public function isEmpty(): bool
    {
        return $this->empty;
    }

    public function toTokenUsage(AiModel $model): ?TokenUsage
    {
        if ($this->empty) {
            return null;
        }

        return new TokenUsage(
            model: $model,
            promptTokens: $this->promptTokens,
            completionTokens: $this->completionTokens,
            totalTokens: $this->totalTokens > 0 ? $this->totalTokens : null,
            cacheReadInputTokens: $this->cacheReadInputTokens,
            cacheCreationInputTokens: $this->cacheCreationInputTokens,
            reasoningTokens: $this->reasoningTokens,
            audioInputTokens: $this->audioInputTokens,
            audioOutputTokens: $this->audioOutputTokens,
            serverToolUse: $this->serverToolUse === [] ? null : $this->serverToolUse,
        );
    }
}
