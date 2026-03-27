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
            $configuredDefault = TranslateSetting::where('key', 'translate_model')->value('value');

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

    public function getDefaultModelId(): ?string
    {
        return $this->getAvailableModels()['default_model'] ?? null;
    }

    /**
     * Check if debug logging is enabled.
     */
    public function shouldShowDebug(): bool
    {
        return \Illuminate\Support\Facades\Cache::remember('translate_settings_show_debug_infos', now()->addHours(1), function () {
            return \App\Models\TranslateSetting::where('key', 'show_debug_infos')->first()?->typed_value ?? false;
        });
    }



    /**
     * Check if payload should be shown in logs.
     */
    public function shouldShowPayload(): bool
    {
        return \Illuminate\Support\Facades\Cache::remember('translate_settings_show_payload', now()->addHours(1), function () {
            return \App\Models\TranslateSetting::where('key', 'show_payload')->first()?->typed_value ?? false;
        });
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
    public function translate(string|array $text, ?string $sourceLang, string $targetLang, int|array|null $glossaryId = null, ?string $model = null, ?string $formality = null): array
    {
        if (empty($model)) {
            $model = $this->resolveDefaultModelForType('translate', true);
        }

        $provider = $this->getProvider($model);

        if ($this->shouldShowDebug()) {
            $logContext = [
                'model_id' => $model,
                'provider' => get_class($provider),
                'source_lang' => $sourceLang,
                'target_lang' => $targetLang,
                'text_length' => is_array($text) ? strlen(json_encode($text)) : strlen($text),
            ];

            Log::debug('[Text Translation] Requested', $logContext);

            if ($this->shouldShowPayload()) {
                Log::debug('[Text Translation] Request Payload', [
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
        }

        if (method_exists($provider, 'translate')) {
            $result = $provider->translate($text, $sourceLang, $targetLang, $glossaryId, $formality);
        } else {
            // Fallback if provider doesn't support formality/translate interface yet
            $result = $provider->translate($text, $sourceLang, $targetLang, $glossaryId);
        }

        // Log usage
        $textForLength = is_array($text) ? json_encode($text) : $text;
        $resultTextForLength = is_array($result['text'] ?? '') ? json_encode($result['text']) : ($result['text'] ?? '');

        $this->usageLogger->logTranslation(
            providerName: $provider->getName(),
            model: $model ?? 'deepl', // Default translation is DeepL
            promptChars: strlen($textForLength),
            completionChars: strlen($resultTextForLength),
            aiUsage: $result['usage'] ?? null
        );

        if ($this->shouldShowDebug()) {
            $logContext = [
                'model_id' => $model,
                'provider' => get_class($provider),
                'result_length' => strlen($resultTextForLength),
                'detected_lang' => $result['detected_source_language'] ?? null,
            ];

            Log::debug('[Text Translation] Completed', $logContext);

            if ($this->shouldShowPayload()) {
                Log::debug('[Text Translation] Result Payload', [
                    'result' => $result,
                ]);
            }
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
    public function write(string|array $text, ?string $targetLang = null, ?string $style = null, ?string $tone = null): array
    {
        $provider = $this->getProvider();

        // Check if provider supports write method (DeepL specific for now)
        if (method_exists($provider, 'write')) {
            if ($this->shouldShowDebug()) {
                $logContext = [
                    'model_id' => 'deepl',
                    'provider' => get_class($provider),
                    'target_lang' => $targetLang,
                    'style' => $style,
                    'tone' => $tone,
                    'text_length' => is_array($text) ? strlen(json_encode($text)) : strlen($text),
                ];

                Log::debug('[Text Rephrase] (Write API) Requested', $logContext);

                if ($this->shouldShowPayload()) {
                    Log::debug('[Text Rephrase] Request Payload', [
                        'service' => $provider->getName(),
                        'payload' => [
                            'text' => $text,
                            'target_lang' => $targetLang,
                            'style' => $style,
                            'tone' => $tone,
                        ],
                    ]);
                }
            }

            $result = $provider->write($text, $targetLang, $style, $tone);

            // Log usage
            $textForLength = is_array($text) ? json_encode($text) : $text;
            $resultTextForLength = is_array($result['text'] ?? '') ? json_encode($result['text']) : ($result['text'] ?? '');

            $this->usageLogger->logImprovement(
                providerName: $provider->getName(),
                model: 'deepl-write',
                promptChars: strlen($textForLength),
                completionChars: strlen($resultTextForLength)
            );

            if ($this->shouldShowDebug()) {
                $logContext = [
                    'model_id' => 'deepl',
                    'provider' => get_class($provider),
                    'result_length' => strlen($resultTextForLength),
                ];

                Log::debug('[Text Rephrase] (Write API) Completed', $logContext);

                if ($this->shouldShowPayload()) {
                    Log::debug('[Text Rephrase] Result Payload', [
                        'result' => $result,
                    ]);
                }
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

    /**
     * Resolve the default model ID for a specific improvement type.
     * Falls back to the global default model if no type-specific model is configured.
     *
     * @param  string  $type  Improvement type (alternatives, synonyms, correction)
     * @return string|null Resolved model ID (e.g. 'gpt-4o' or 'deepl')
     */
    public function resolveDefaultModelForType(string $type, bool $allowFallback = true): ?string
    {
        $key = match ($type) {
            'translate' => 'translate_model',
            'rephrase', 'default' => 'rephrase_model',
            'alternatives' => 'alternative_sentence_model',
            'synonyms' => 'replace_word_model',
            'correction' => 'correction_model',
            'detection' => 'detection_model',
            default => 'translate_model',
        };

        $friendlyName = match ($type) {
            'translate' => 'translate text',
            'rephrase', 'default' => 'rephrase text',
            'alternatives' => 'rephrase sentence',
            'synonyms' => 'replace word',
            'correction' => 'correct after word replacement',
            'detection' => 'detect language',
            default => $type,
        };

        // Try to get the specific model setting
        $source = 'specific';
        $configuredModel = TranslateSetting::where('key', $key)->value('value');

        // If not set for this type, optionally fall back to the main translate model
        if (empty($configuredModel) && $key !== 'translate_model') {
            if (! $allowFallback) {
                return null;
            }
            $source = 'main translate fallback';
            $configuredModel = TranslateSetting::where('key', 'translate_model')->value('value');
        }

        // if (empty($configuredModel)) {
        //    Log::debug('[ModelResolution] No model configured for feature', ['feature' => $friendlyName, 'key' => $key]);

        //    return null;
        // }

        $resolvedId = null;

        // DeepL is special and used as a literal string
        if ($configuredModel === 'deepl') {
            $resolvedId = 'deepl';
        } else {
            // AI models are stored as system_id; resolve to the ID used by AiService
            $availableModels = $this->aiService->getAvailableModels();
            foreach ($availableModels->models as $model) {
                $raw = $model->toArray();
                if (($raw['system_id'] ?? null) === $configuredModel) {
                    $resolvedId = $model->getId();
                    break;
                }
            }
        }



        return $resolvedId;
    }
}
