<?php

namespace App\Services\Translation\Contracts;

/**
 * Interface for translation service providers
 *
 * All translation providers must implement this interface to ensure
 * compatibility with the TranslationFactory and TranslationService.
 */
interface TranslationProviderInterface
{
    /**
     * Translate text from source language to target language
     *
     * @param  string  $text  Text to translate (max length provider-dependent)
     * @param  string|null  $sourceLang  Source language code (null for auto-detect)
     * @param  string  $targetLang  Target language code (ISO 639-1 format)
     * @return array{text: string, detected_source_language: string|null}
     *
     * @throws \App\Services\Translation\Exceptions\TranslationFailedException
     * @throws \App\Services\Translation\Exceptions\InvalidLanguageException
     * @throws \App\Services\Translation\Exceptions\QuotaExceededException
     */
    public function translate(string|array $text, ?string $sourceLang, string $targetLang, int|array|null $glossaryId = null, ?string $formality = null): array;

    /**
     * Get list of supported languages
     *
     * @return array<string, string> Array of language codes and names
     */
    public function getSupportedLanguages(): array;

    /**
     * Check if the provider is available and properly configured
     *
     * @return bool True if provider is ready to use
     */
    public function isAvailable(): bool;

    /**
     * Get the provider name/identifier
     *
     * @return string Provider name (e.g., 'deepl', 'google')
     */
    public function getName(): string;
}
