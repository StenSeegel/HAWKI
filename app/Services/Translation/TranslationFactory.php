<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Models\TranslateSetting;
use App\Services\Translation\Contracts\TranslationProviderInterface;
use App\Services\Translation\Providers\AiModelTranslationProvider;
use App\Services\Translation\Providers\DeeplLibraryProvider;
use Exception;

/**
 * Factory for creating translation provider instances.
 *
 * Provider selection is model-driven:
 *  - explicit AI model ID  → AI provider
 *  - 'deepl' or no model   → DeepL provider (reads API key from translate_settings)
 */
class TranslationFactory
{
    /**
     * @throws Exception
     */
    public static function create(?string $preferredModel = null): TranslationProviderInterface
    {
        if ($preferredModel && $preferredModel !== 'deepl') {
            return self::createAiProvider($preferredModel);
        }

        return self::createDeeplProvider();
    }

    protected static function createAiProvider(?string $specificModelId = null): TranslationProviderInterface
    {
        $aiService = app(\App\Services\AI\AiService::class);

        $modelId = $specificModelId
            ?? config('translation.ai_model')
            ?? config('model_providers.default_models.default_model');

        if (empty($modelId)) {
            throw new Exception('AI Translation enabled but no model ID configured.');
        }

        return new AiModelTranslationProvider($aiService, $modelId);
    }

    protected static function createDeeplProvider(): TranslationProviderInterface
    {
        // DeeplLibraryProvider self-resolves the API key from translate_settings
        return new DeeplLibraryProvider;
    }

    /**
     * Check if a provider is active and configured.
     */
    public static function isActive(string $provider): bool
    {
        if ($provider === 'deepl') {
            $setting = TranslateSetting::where('key', 'deepl_api_key')->first();

            return ! empty($setting?->value);
        }

        try {
            $aiService = app(\App\Services\AI\AiService::class);
            $models = $aiService->getAvailableModels();

            return ! empty($models->models);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if any translation provider is configured and active.
     */
    public static function providerActive(): bool
    {
        if (self::isActive('deepl') || self::isActive('ai')) {
            return true;
        }

        \Log::warning('No translation provider is accessible.');

        return false;
    }
}
