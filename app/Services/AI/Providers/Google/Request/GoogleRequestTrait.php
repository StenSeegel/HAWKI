<?php
declare(strict_types=1);


namespace App\Services\AI\Providers\Google\Request;


use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\TokenUsage;

trait GoogleRequestTrait
{
    /**
     * Extract usage information from Google response
     *
     * @param AiModel $model
     * @param array $data
     * @return TokenUsage|null
     */
    protected function extractUsage(AiModel $model, array $data): ?TokenUsage
    {
        if (empty($data['usageMetadata'])) {
            return null;
        }
        
        // Only extract usage when we have a finishReason (final chunk)
        // This prevents duplicate usage log entries
        if (!empty($data['candidates'][0]['finishReason']) && 
            $data['candidates'][0]['finishReason'] !== 'FINISH_REASON_UNSPECIFIED') {
            
            $usage = $data['usageMetadata'];
            
            // Extract token counts
            $promptTokens = (int)($usage['promptTokenCount'] ?? 0);
            $totalTokens = (int)($usage['totalTokenCount'] ?? 0);
            
            // For Google: completion_tokens = total - prompt
            // This ensures all tokens (grounding, reasoning, cached content, etc.) are included
            $completionTokens = $totalTokens - $promptTokens;
            
            // Extract additional token details for logging
            $candidatesTokenCount = (int)($usage['candidatesTokenCount'] ?? 0);
            $toolUsePromptTokenCount = 0;
            $thoughtsTokenCount = 0;
            $cachedContentTokenCount = 0;
            
            // Tool use tokens (for web search and other tools)
            if (isset($usage['toolUsePromptTokenCount'])) {
                $toolUsePromptTokenCount = (int)$usage['toolUsePromptTokenCount'];
            }
            
            // Thoughts tokens (for reasoning models)
            if (isset($usage['thoughtsTokenCount'])) {
                $thoughtsTokenCount = (int)$usage['thoughtsTokenCount'];
            }
            
            // Cached content tokens
            if (isset($usage['cachedContentTokenCount'])) {
                $cachedContentTokenCount = (int)$usage['cachedContentTokenCount'];
            }
            
            // Log usage data if trigger is enabled
            if (config('logging.triggers.usage')) {
                $logData = [
                    'model' => $model->getId(),
                    'finishReason' => $data['candidates'][0]['finishReason'],
                    'promptTokenCount' => $promptTokens,
                    'candidatesTokenCount' => $candidatesTokenCount,
                    'totalTokenCount' => $totalTokens,
                    'completionTokens_calculated' => $completionTokens,
                ];
                
                // Add optional fields only if they exist
                if ($toolUsePromptTokenCount > 0) {
                    $logData['toolUsePromptTokenCount'] = $toolUsePromptTokenCount;
                }
                if ($thoughtsTokenCount > 0) {
                    $logData['thoughtsTokenCount'] = $thoughtsTokenCount;
                }
                if ($cachedContentTokenCount > 0) {
                    $logData['cachedContentTokenCount'] = $cachedContentTokenCount;
                }
                
                \Log::info('Token Usage - Google (Final Chunk)', $logData);
            }
            
            // Create TokenUsage with calculated completion tokens
            // completion_tokens = total - prompt (includes all Google's additional tokens)
            return new TokenUsage(
                model: $model,
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                totalTokens: $totalTokens
            );
        }
        return null;
    }

    protected function buildApiUrl(AiModel $model, bool $stream): string
    {
        $config = $model->getProvider()->getConfig();
        $apiUrl = $config->getApiUrl();
        $apiKey = $config->getApiKey();
        if($stream){
            return $apiUrl . $model->getId() . ':streamGenerateContent?key=' . $apiKey;
        }
        else {
            return $apiUrl . $model->getId() . ':generateContent?key=' . $apiKey;
        }
    }

    protected function preparePayload(array $payload): array
    {

        // Extract just the necessary parts for Google's API
        $requestPayload = [
            'system_instruction' => $payload['system_instruction'],
            'contents' => $payload['contents']
        ];

        // Add aditional config parameters if present
        if (isset($payload['safetySettings'])) {
            $requestPayload['safetySettings'] = $payload['safetySettings'];
        }
        if (isset($payload['generationConfig'])) {
            $requestPayload['generationConfig'] = $payload['generationConfig'];
        }
        if (isset($payload['tools'])) {
            $requestPayload['tools'] = $payload['tools'];
        }

        return $requestPayload;
    }
}
