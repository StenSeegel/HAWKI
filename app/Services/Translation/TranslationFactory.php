<?php

namespace App\Services\Translation;

use App\Models\ApiProvider;
use App\Services\Translation\Contracts\TranslationProviderInterface;
use App\Services\Translation\Providers\DeeplTranslationProvider;
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
    public static function create(?string $provider = null): TranslationProviderInterface
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
            'deepl' => new DeeplTranslationProvider($apiProvider->api_key, $apiProvider->base_url),
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
