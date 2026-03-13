<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Models\Records\UsageRecord;
use App\Services\AI\Value\TokenUsage;
use App\Services\QuotaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class TranslationUsageLogger
{
    public function __construct(
        protected \App\Services\AI\AiService $aiService,
        protected QuotaService $quotaService
    ) {}

    /**
     * Log a translation request
     */
    public function logTranslation(
        string $providerName,
        string $model,
        int $promptChars,
        int $completionChars,
        ?TokenUsage $aiUsage = null,
        string $status = 'success'
    ): void {
        $this->submitRecord(
            type: 'translation',
            providerName: $providerName,
            model: $model,
            promptChars: $promptChars,
            completionChars: $completionChars,
            aiUsage: $aiUsage,
            status: $status
        );
    }

    /**
     * Log a text improvement (write) request
     */
    public function logImprovement(
        string $providerName,
        string $model,
        int $promptChars,
        int $completionChars,
        ?TokenUsage $aiUsage = null,
        string $status = 'success'
    ): void {
        $this->submitRecord(
            type: 'rephrase', // Custom type for Rephrase feature
            providerName: $providerName,
            model: $model,
            promptChars: $promptChars,
            completionChars: $completionChars,
            aiUsage: $aiUsage,
            status: $status
        );
    }

    /**
     * Log a document translation request
     */
    public function logDocumentTranslation(
        string $providerName,
        string $model,
        int $billedChars,
        string $status = 'success'
    ): void {
        $this->submitRecord(
            type: 'translation',
            providerName: $providerName,
            model: $model,
            promptChars: 0,
            completionChars: 0,
            status: $status,
            serverToolUse: ['document_characters' => $billedChars]
        );
    }

    /**
     * Internal helper to submit the record
     */
    private function submitRecord(
        string $type,
        string $providerName,
        string $model,
        int $promptChars,
        int $completionChars,
        ?TokenUsage $aiUsage = null,
        string $status = 'success',
        ?array $serverToolUse = null
    ): void {
        try {
            $user = Auth::user();
            if (! $user) {
                return;
            }

            // Resolve API Provider correctly
            $resolvedProvider = $providerName;

            if ($providerName === 'deepl-library') {
                $resolvedProvider = 'deepl';
            } else {
                try {
                    $aiModel = $this->aiService->getModel($model);
                    if ($aiModel) {
                        $resolvedProvider = $aiModel->getProvider()->getConfig()->getId();
                    }
                } catch (\Exception $e) {
                    // Fallback to providerName if resolution fails or model not found in AiService
                }
            }

            // If we have AI usage (from AiModelTranslationProvider), use that.
            // Otherwise (DeepL), estimate tokens from characters (approx 4 chars = 1 token)
            $promptTokens = $aiUsage ? $aiUsage->promptTokens : (int) ceil($promptChars / 4);
            $completionTokens = $aiUsage ? $aiUsage->completionTokens : (int) ceil($completionChars / 4);

            $record = UsageRecord::create([
                'user_id' => $user->id,
                'type' => $type,
                'api_provider' => $resolvedProvider,
                'model' => $model,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'status' => $status,
                'server_tool_use' => $serverToolUse,
            ]);

            // Record in daily aggregation for quota
            $this->quotaService->recordUsage($record);
        } catch (\Exception $e) {
            Log::error('Failed to log translation usage', [
                'error' => $e->getMessage(),
                'provider' => $providerName,
                'model' => $model,
            ]);
        }
    }
}
