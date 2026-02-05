<?php

namespace App\Services\Translation;

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
        $provider = $provider ?? config('translation.default', 'deepl');
        
        // Check if provider is active
        if (!self::isActive($provider)) {
            $fallback = config('translation.fallback', 'deepl');
            if (!empty($fallback) && self::isActive($fallback)) {
                $provider = $fallback;
            } else {
                throw new Exception("No active translation provider available. Tried {$provider}");
            }
        }
        
        return match ($provider) {
            'deepl' => new DeeplTranslationProvider(),
            default => throw new Exception("Unknown translation provider: {$provider}"),
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
        $config = config("services.{$provider}");
        
        if (!$config) {
            return false;
        }
        
        // Check if API key is configured
        return !empty($config['api_key']) && $config['api_key'] !== '';
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
