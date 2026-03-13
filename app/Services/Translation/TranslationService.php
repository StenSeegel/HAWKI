<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Models\TranslateSetting;
use App\Services\AI\AiService;
use App\Services\Translation\Contracts\TranslationProviderInterface;
use Illuminate\Support\Facades\Log;

/**
 * Translation Service - Main entry point for translation functionality
 *
 * This service provides a simple interface for translation operations,
 * using the TranslationFactory internally to get the appropriate provider.
 */
class TranslationService
{
    private ?TranslationProviderInterface $provider = null;

    public function __construct(
        private AiService $aiService,
        private TranslationUsageLogger $usageLogger
    ) {}

    /**
     * Get available translation and improvement models
     *
     * @return array{models: list<array<string,mixed>>, default_model: string|null}
     */
    public function getAvailableModels(): array
    {
        try {
            $availableModels = $this->aiService->getAvailableModels();

            // Load the admin-configured allowed model list (system_ids)
            $allowedSetting = TranslateSetting::where('key', 'allowed_models')->first();
            $allowedSystemIds = json_decode($allowedSetting?->value ?? '[]', true) ?? [];
            $filterByAllowlist = ! empty($allowedSystemIds);

            // Load admin-configured default model (stored as 'deepl' or a system_id)
            $configuredDefault = TranslateSetting::where('key', 'default_model')->value('value');

            $models = [];

            // Add DeepL as first option when its API key is configured
            if (TranslationFactory::isActive('deepl')) {
                $models[] = [
                    'id' => 'deepl',
                    'label' => 'DeepL API Pro',
                    'provider' => 'deepl',
                    'provider_name' => 'DeepL',
                    'provider_display_order' => 0,
                    'status' => 'online',
                    'visible' => true,
                ];
            }

            // Add AI models, optionally filtered by the admin allowlist
            $aiModelsArray = $availableModels->toArray();
            foreach ($aiModelsArray['models'] as $model) {
                if ($filterByAllowlist && ! in_array($model['system_id'] ?? null, $allowedSystemIds, true)) {
                    continue;
                }

                $models[] = $model;
            }

            // Fallback when nothing is available
            if (empty($models)) {
                Log::warning('No AI models available, using fallback');
                $models[] = [
                    'id' => '',
                    'label' => 'Standard Modell (Default)',
                    'provider' => 'default',
                    'provider_name' => 'Default',
                    'provider_display_order' => 9999,
                    'status' => 'online',
                    'visible' => true,
                ];
            }

            // Resolve the configured default (stored as 'deepl' or a system_id UUID)
            // to the model id that the frontend uses (model.id).
            $defaultModelId = null;

            if (! empty($configuredDefault)) {
                // DeepL is stored and used as the literal string 'deepl'
                if ($configuredDefault === 'deepl') {
                    foreach ($models as $model) {
                        if ($model['id'] === 'deepl') {
                            $defaultModelId = 'deepl';
                            break;
                        }
                    }
                } else {
                    // AI models are stored by system_id; resolve to model.id
                    foreach ($models as $model) {
                        if (($model['system_id'] ?? null) === $configuredDefault) {
                            $defaultModelId = $model['id'];
                            break;
                        }
                    }
                }
            }

            // Fall back to the first available model
            $defaultModelId ??= ($models[0]['id'] ?? null);

            return [
                'models' => $models,
                'default_model' => $defaultModelId,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to get available models in TranslationService', ['error' => $e->getMessage()]);

            return ['models' => [], 'default_model' => null];
        }
    }

    /**
     * Get the translation provider instance
     */
    protected function getProvider(?string $model = null): TranslationProviderInterface
    {
        // If a specific model is requested, we bypass caching or check if cached provider matches (simplified: always create new if model provided)
        if ($model) {
            return TranslationFactory::create($model);
        }

        if ($this->provider === null) {
            $this->provider = TranslationFactory::create();
        }

        return $this->provider;
    }

    /**
     * Translate text from source to target language
     *
     * @param  string  $text  Text to translate
     * @param  string|null  $sourceLang  Source language (null for auto-detect)
     * @param  string  $targetLang  Target language
     * @return array{text: string, detected_source_language: string|null}
     *
     * @throws \App\Services\Translation\Exceptions\TranslationFailedException
     * @throws \App\Services\Translation\Exceptions\InvalidLanguageException
     * @throws \App\Services\Translation\Exceptions\QuotaExceededException
     */
    public function translate(string $text, ?string $sourceLang, string $targetLang, int|array|null $glossaryId = null, ?string $model = null, ?string $formality = null): array
    {
        $provider = $this->getProvider($model);

        $showDebug = \Illuminate\Support\Facades\Cache::remember('translate_settings_show_debug_infos', now()->addHours(1), function () {
            return \App\Models\TranslateSetting::where('key', 'show_debug_infos')->first()?->typed_value ?? false;
        });

        if ($showDebug) {
            \Illuminate\Support\Facades\Log::info('Translation requested', [
                'provider' => get_class($provider),
                'model' => $model,
                'source_lang' => $sourceLang,
                'target_lang' => $targetLang,
                'text_length' => strlen($text),
            ]);

            \Illuminate\Support\Facades\Log::debug('Translation Request Payload', [
                'service' => $provider->getName(),
                'payload' => [
                    'text' => $text,
                    'source_lang' => $sourceLang,
                    'target_lang' => $targetLang,
                    'glossary_id' => $glossaryId,
                    'formality' => $formality,
                ],
            ]);
        }

        if (method_exists($provider, 'translate')) {
            $result = $provider->translate($text, $sourceLang, $targetLang, $glossaryId, $formality);
        } else {
            // Fallback if provider doesn't support formality/translate interface yet
            $result = $provider->translate($text, $sourceLang, $targetLang, $glossaryId);
        }

        // Log usage
        $this->usageLogger->logTranslation(
            providerName: $provider->getName(),
            model: $model ?? 'deepl', // Default translation is DeepL
            promptChars: strlen($text),
            completionChars: strlen($result['text'] ?? ''),
            aiUsage: $result['usage'] ?? null
        );

        if ($showDebug) {
            \Illuminate\Support\Facades\Log::info('Translation completed', [
                'provider' => get_class($provider),
                'result_length' => strlen($result['text'] ?? ''),
                'detected_lang' => $result['detected_source_language'] ?? null,
            ]);

            \Illuminate\Support\Facades\Log::debug('Translation Result Payload', [
                'result' => $result,
            ]);
        }

        return $result;
    }

    /**
     * Improve text using provider's improvement functionality
     *
     * Note: Only DeepL provider currently supports this via write() method
     *
     * @param  string  $text  Text to improve
     * @param  string|null  $targetLang  Target language
     * @return array{text: string}
     *
     * @throws \App\Services\Translation\Exceptions\TranslationFailedException
     */
    public function write(string $text, ?string $targetLang = null, ?string $style = null, ?string $tone = null): array
    {
        $provider = $this->getProvider();

        // Check if provider supports write method (DeepL specific for now)
        if (method_exists($provider, 'write')) {
            \Illuminate\Support\Facades\Log::info('Translation/Improvement requested (Write API)', [
                'provider' => get_class($provider),
                'target_lang' => $targetLang,
                'style' => $style,
                'tone' => $tone,
                'text_length' => strlen($text),
            ]);

            if (config('logging.triggers.curl_request_object')) {
                \Illuminate\Support\Facades\Log::debug('Translation/Improvement Request Payload', [
                    'service' => $provider->getName(),
                    'payload' => [
                        'text' => $text,
                        'target_lang' => $targetLang,
                        'style' => $style,
                        'tone' => $tone,
                    ],
                ]);
            }

            $result = $provider->write($text, $targetLang, $style, $tone);

            // Log usage
            $this->usageLogger->logImprovement(
                providerName: $provider->getName(),
                model: 'deepl-write',
                promptChars: strlen($text),
                completionChars: strlen($result['text'] ?? '')
            );

            \Illuminate\Support\Facades\Log::info('Translation/Improvement completed (Write API)', [
                'provider' => get_class($provider),
                'result_length' => strlen($result['text'] ?? ''),
            ]);

            if (config('logging.triggers.curl_request_object')) {
                \Illuminate\Support\Facades\Log::debug('Translation/Improvement Result Payload', [
                    'result' => $result,
                ]);
            }

            return $result;
        }

        throw new \Exception('Current provider does not support text improvement');
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
