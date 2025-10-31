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
        
        // fix duplicate usage log entries
        if (!empty($data['candidates'][0]['finishReason']) && $data['candidates'][0]['finishReason'] === "STOP") {
            $promptTokens = (int)($data['usageMetadata']['promptTokenCount'] ?? 0);
            $candidatesTokens = (int)($data['usageMetadata']['candidatesTokenCount'] ?? 0);
            $totalTokens = (int)($data['usageMetadata']['totalTokenCount'] ?? 0);
            
            // Log usage data if trigger is enabled
            if (config('logging.triggers.usage')) {
                \Log::info('Token Usage - Google (Final Chunk)', [
                    'model' => $model->getId(),
                    'finishReason' => $data['candidates'][0]['finishReason'],
                    'promptTokenCount' => $promptTokens,
                    'candidatesTokenCount' => $candidatesTokens,
                    'totalTokenCount' => $totalTokens
                ]);
            }
            
            return new TokenUsage(
                model: $model,
                promptTokens: $promptTokens,
                completionTokens: $candidatesTokens,
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
