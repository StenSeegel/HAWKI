<?php

namespace App\Services\Translation;

use App\Services\Translation\Contracts\TranslationProviderInterface;

/**
 * Translation Service - Main entry point for translation functionality
 * 
 * This service provides a simple interface for translation operations,
 * using the TranslationFactory internally to get the appropriate provider.
 * 
 * @package App\Services\Translation
 */
class TranslationService
{
    private ?TranslationProviderInterface $provider = null;
    
    /**
     * Get the translation provider instance
     * 
     * @return TranslationProviderInterface
     */
    protected function getProvider(): TranslationProviderInterface
    {
        if ($this->provider === null) {
            $this->provider = TranslationFactory::create();
        }
        
        return $this->provider;
    }
    
    /**
     * Translate text from source to target language
     * 
     * @param string $text Text to translate
     * @param string|null $sourceLang Source language (null for auto-detect)
     * @param string $targetLang Target language
     * @return array{text: string, detected_source_language: string|null}
     * 
     * @throws \App\Services\Translation\Exceptions\TranslationFailedException
     * @throws \App\Services\Translation\Exceptions\InvalidLanguageException
     * @throws \App\Services\Translation\Exceptions\QuotaExceededException
     */
    public function translate(string $text, ?string $sourceLang, string $targetLang): array
    {
        return $this->getProvider()->translate($text, $sourceLang, $targetLang);
    }
    
    /**
     * Improve text using provider's improvement functionality
     * 
     * Note: Only DeepL provider currently supports this via write() method
     * 
     * @param string $text Text to improve
     * @param string|null $targetLang Target language
     * @return array{text: string}
     * 
     * @throws \App\Services\Translation\Exceptions\TranslationFailedException
     */
    public function write(string $text, ?string $targetLang = null): array
    {
        $provider = $this->getProvider();
        
        // Check if provider supports write method (DeepL specific for now)
        if (method_exists($provider, 'write')) {
            return $provider->write($text, $targetLang);
        }
        
        throw new \Exception("Current provider does not support text improvement");
    }
    
    /**
     * Get supported languages from the current provider
     * 
     * @return array<string, string>
     */
    public function getSupportedLanguages(): array
    {
        return $this->getProvider()->getSupportedLanguages();
    }
    
    /**
     * Check if translation service is available
     * 
     * @return bool
     */
    public function isAvailable(): bool
    {
        try {
            return $this->getProvider()->isAvailable();
        } catch (\Exception $e) {
            return false;
        }
    }
}
