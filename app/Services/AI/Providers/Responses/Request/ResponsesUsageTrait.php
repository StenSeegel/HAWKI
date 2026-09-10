<?php
declare(strict_types=1);

namespace App\Services\AI\Providers\Responses\Request;

use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\TokenUsage;

trait ResponsesUsageTrait
{
    /**
     * Extract usage information from Responses API response
     * 
     * Responses API uses 'input_tokens' and 'output_tokens'
     * (unlike Chat Completions which uses 'prompt_tokens' and 'completion_tokens')
     * 
     * Supports extended token types:
     * - input_tokens_details: cached_tokens, text_tokens, audio_tokens, image_tokens
     * - output_tokens_details: reasoning_tokens, text_tokens, audio_tokens
     *
     * @param AiModel $model
     * @param array $data
     * @return TokenUsage|null
     */
    protected function extractUsage(AiModel $model, array $data): ?TokenUsage
    {
        if (empty($data['usage'])) {
            return null;
        }

        $usage = $data['usage'];

        // Extract base token counts
        $inputTokens = (int)($usage['input_tokens'] ?? 0);
        $outputTokens = (int)($usage['output_tokens'] ?? 0);

        // Extract input_tokens_details (cache + audio)
        $inputDetails = $usage['input_tokens_details'] ?? [];
        $cacheReadInputTokens = (int)($inputDetails['cached_tokens'] ?? 0);
        $audioInputTokens = (int)($inputDetails['audio_tokens'] ?? 0);
        
        // Note: cache_creation_input_tokens might be a separate field
        $cacheCreationInputTokens = (int)($inputDetails['cache_creation_input_tokens'] ?? 0);

        // Extract output_tokens_details (reasoning + audio)
        $outputDetails = $usage['output_tokens_details'] ?? [];
        $reasoningTokens = (int)($outputDetails['reasoning_tokens'] ?? 0);
        $audioOutputTokens = (int)($outputDetails['audio_tokens'] ?? 0);

        return new TokenUsage(
            model: $model,
            promptTokens: $inputTokens,
            completionTokens: $outputTokens,
            totalTokens: (int)($usage['total_tokens'] ?? null),
            cacheReadInputTokens: $cacheReadInputTokens,
            cacheCreationInputTokens: $cacheCreationInputTokens,
            reasoningTokens: $reasoningTokens,
            audioInputTokens: $audioInputTokens,
            audioOutputTokens: $audioOutputTokens,
        );
    }

    /**
     * The usage with the provider side tool calls of this response attached, so
     * they reach the usage record - and the tool use per provider on the
     * requests dashboard - the same way HAWKI side tool executions do.
     *
     * @param  array<string, int>  $calls  tool key => number of calls; zeros are dropped
     */
    protected function withServerToolUse(?TokenUsage $usage, array $calls): ?TokenUsage
    {
        $calls = array_filter($calls, static fn ($count): bool => (int) $count > 0);

        if ($usage === null || $calls === []) {
            return $usage;
        }

        return new TokenUsage(
            model: $usage->model,
            promptTokens: $usage->promptTokens,
            completionTokens: $usage->completionTokens,
            totalTokens: $usage->totalTokens,
            cacheReadInputTokens: $usage->cacheReadInputTokens,
            cacheCreationInputTokens: $usage->cacheCreationInputTokens,
            reasoningTokens: $usage->reasoningTokens,
            audioInputTokens: $usage->audioInputTokens,
            audioOutputTokens: $usage->audioOutputTokens,
            serverToolUse: array_map('intval', $calls),
        );
    }

    /**
     * How often each provider side tool was called, counted over a response's
     * output items and keyed the way the usage records name the tools.
     *
     * @param  array<int, array<string, mixed>>  $output
     * @return array<string, int>
     */
    protected function countToolCalls(array $output): array
    {
        $counts = [];

        foreach ($output as $item) {
            $key = match ($item['type'] ?? '') {
                'web_search_call' => 'web_search',
                'code_interpreter_call' => 'code_interpreter',
                'image_generation_call' => 'image_generation',
                default => null,
            };

            if ($key !== null) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
