<?php

namespace App\Services\Translation;

use App\Models\ApiProvider;
use App\Services\Translation\Contracts\TranslationProviderInterface;
use App\Services\Translation\Providers\DeeplLibraryProvider;
use Exception;

/**
 * Factory for creating translation provider instances
 * 
 * Similar to FileConverterFactory, this factory creates the appropriate
 * translation provider based on configuration.
 * 
 * @package App\Services\Translation
 */
class TranslationFactory
{
    /**
     * Create a translation provider instance
     * 
     * @param string|null $provider Provider name (null = use default from config)
     * @return TranslationProviderInterface
     * @throws Exception
     */
    public static function create(?string $preferredModel = null): TranslationProviderInterface
    {
        // 1. Determine Driver based on Preferred Model
        if ($preferredModel && $preferredModel !== 'deepl') {
            return self::createAiProvider($preferredModel);
        }

        // 2. Fallback to Configured Driver if no specific model requested (or 'deepl' explicitly requested)
        $driver = config('translation.driver', 'deepl');

        if ($driver === 'ai') {
            return self::createAiProvider();
        }

        // Default to DeepL logic
        return self::createDeeplProvider();
    }

    protected static function createAiProvider(?string $specificModelId = null): TranslationProviderInterface
    {
        $aiService = app(\App\Services\AI\AiService::class);
        
        // Use specific model if provided, otherwise fallback to config
        $modelId = $specificModelId ?? config('translation.ai_model');

        if (empty($modelId)) {
            // Fallback to default AI model if not strictly set
             $modelId = config('model_providers.default_models.default_model');
        }

        if (empty($modelId)) {
             throw new Exception("AI Translation enabled but no model ID configured.");
        }

        return new \App\Services\Translation\Providers\AiModelTranslationProvider($aiService, $modelId);
    }

    protected static function createDeeplProvider(): TranslationProviderInterface
    {
        $providerName = $provider ?? config('translation.default', 'deepl');
        $apiProvider = ApiProvider::where('unique_name', $providerName)->first();
        
        // Check if provider is active
        if (!$apiProvider || !$apiProvider->is_active || empty($apiProvider->api_key)) {
            $fallback = config('translation.fallback', 'deepl');
            if (!empty($fallback) && $fallback !== $providerName) {
                $apiProvider = ApiProvider::where('unique_name', $fallback)->first();
                if ($apiProvider && $apiProvider->is_active && !empty($apiProvider->api_key)) {
                    $providerName = $fallback;
                } else {
                    throw new Exception("No active translation provider available. Tried {$providerName}");
                }
            } else {
                throw new Exception("No active translation provider available. Tried {$providerName}");
            }
        }
        
        return match ($providerName) {
            'deepl' => new \App\Services\Translation\Providers\DeeplLibraryProvider($apiProvider->api_key),
            default => throw new Exception("Unknown translation provider: {$providerName}"),
        };
    }
    
    /**
     * Check if a translation provider is active and configured
     * 
     * @param string $provider Provider name
     * @return bool
     */
    public static function isActive(string $provider): bool
    {
        $apiProvider = ApiProvider::where('unique_name', $provider)->first();
        
        return $apiProvider && $apiProvider->is_active && !empty($apiProvider->api_key);
    }
    
    /**
     * Check if any translation provider is configured and active
     * 
     * @return bool
     */
    public static function providerActive(): bool
    {
        $default = config('translation.default', 'deepl');
        $fallback = config('translation.fallback', 'deepl');
        
        if (self::isActive($default)) {
            return true;
        }
        
        if (self::isActive($fallback)) {
            return true;
        }
        
        \Log::warning("No translation provider is accessible.");
        return false;
    }
}
