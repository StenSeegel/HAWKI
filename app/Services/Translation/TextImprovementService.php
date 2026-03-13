<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Services\AI\AiService;
use App\Services\Translation\Exceptions\TranslationFailedException;
use Illuminate\Support\Facades\Log;

class TextImprovementService
{
    public function __construct(
        private AiService $aiService,
        private TranslationUsageLogger $usageLogger
    ) {}

    /**
     * Improve text using AI
     *
     * @param  string  $text  Text to improve
     * @param  string|null  $sourceLang  Source language (optional)
     * @param  string|null  $targetLang  Target language (optional)
     * @param  string|null  $modelId  Model ID to use (optional, uses default if not provided)
     * @param  string|null  $style  Writing style (optional)
     * @param  string|null  $tone  Writing tone (optional)
     * @param  string|null  $formality  Formality (optional)
     * @return array{text: string}
     *
     * @throws TranslationFailedException
     */
    public function improveText(string $text, ?string $sourceLang = null, ?string $targetLang = null, ?string $modelId = null, ?string $style = null, ?string $tone = null, ?string $formality = null): array
    {
        try {
            // Determine which model to use
            $modelIdToUse = null;

            if ($modelId && ! empty($modelId)) {
                // User selected a specific model — verify it exists, otherwise fall through to default
                if ($this->aiService->getModel($modelId) !== null) {
                    $modelIdToUse = $modelId;
                }
            }

            if (! $modelIdToUse) {
                // Try the configured default first
                $defaultModelId = config('model_providers.default_models.default_model');
                if ($defaultModelId && $this->aiService->getModel($defaultModelId) !== null) {
                    $modelIdToUse = $defaultModelId;
                } else {
                    // Fall back to the first available AI model
                    $firstModel = null;
                    foreach ($this->aiService->getAvailableModels()->models as $m) {
                        $firstModel = $m;
                        break;
                    }
                    if (! $firstModel) {
                        throw new TranslationFailedException('No AI model available for text improvement');
                    }
                    $modelIdToUse = $firstModel->getId();
                }
            }

            Log::info('TextImprovement requested', [
                'model_id' => $modelIdToUse,
                'target_lang' => $targetLang,
                'style' => $style,
                'tone' => $tone,
                'formality' => $formality,
                'text_length' => strlen($text),
            ]);

            if (config('logging.triggers.curl_request_object')) {
                Log::debug('TextImprovement Request Payload', [
                    'service' => 'ai-text-improvement',
                    'payload' => [
                        'text' => $text,
                        'target_lang' => $targetLang,
                        'style' => $style,
                        'tone' => $tone,
                        'formality' => $formality,
                        'model' => $modelIdToUse,
                    ],
                ]);
            }

            // Build the prompt for text improvement
            $prompt = $this->buildImprovementPrompt($text, $sourceLang, $targetLang, $style, $tone, $formality);

            // Build payload for AI request
            $payload = [
                'model' => $modelIdToUse,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => [
                            'text' => 'Du bist ein Assistent zur Textverbesserung. Korrigiere Rechtschreibung, Grammatik und verbessere die Formulierung. Gib NUR den verbesserten Text zurück, ohne Erklärungen oder zusätzliche Kommentare.',
                        ],
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            'text' => $prompt,
                        ],
                    ],
                ],
                'temperature' => 0.3,
                'max_tokens' => 4000,
            ];

            // Send request to AI - AiService accepts array or AiRequest
            $response = $this->aiService->sendRequest($payload);

            // Log usage
            $this->usageLogger->logImprovement(
                providerName: 'ai-improvement', // Will be resolved by logger using model ID
                model: $modelIdToUse,
                promptChars: strlen($text),
                completionChars: strlen($response->content['text'] ?? ''),
                aiUsage: $response->usage
            );

            // Extract improved text from response
            $improvedText = $response->content['text'] ?? '';

            if (empty($improvedText)) {
                throw new TranslationFailedException('AI returned empty response');
            }

            Log::info('TextImprovement completed', [
                'model_id' => $modelIdToUse,
                'result_length' => strlen($improvedText),
            ]);

            if (config('logging.triggers.curl_request_object')) {
                Log::debug('TextImprovement Result Payload', [
                    'result' => ['text' => $improvedText],
                ]);
            }

            return [
                'text' => trim($improvedText),
            ];

        } catch (\Exception $e) {
            Log::error('Text improvement failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new TranslationFailedException('Text improvement failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Build the improvement prompt
     */
    private function buildImprovementPrompt(string $text, ?string $sourceLang, ?string $targetLang, ?string $style, ?string $tone = null, ?string $formality = null): string
    {
        $prompt = "Verbessere folgenden Text:\n\n{$text}";

        $langMap = [
            'de' => 'Deutsch',
            'en' => 'Englisch',
            'en-GB' => 'British English',
            'en-US' => 'American English',
            'fr' => 'Französisch',
            'es' => 'Spanisch',
            'it' => 'Italienisch',
            'pt' => 'Portugiesisch',
            'pt-BR' => 'Brasilianisches Portugiesisch',
        ];

        if ($sourceLang && $targetLang && $sourceLang === $targetLang) {
            $language = $langMap[strtolower($sourceLang)] ?? $sourceLang;
            $prompt .= "\n\nDer Text ist in {$language}. Erstelle KEINE Übersetzung, sondern verbessere den Text ausschließlich in {$language}.";
        } elseif ($targetLang) {
            $language = $langMap[strtolower($targetLang)] ?? $targetLang;
            $prompt .= "\n\nStelle sicher, dass der verbesserte Text in {$language} ist.";
        }

        if ($style) {
            $styleMap = [
                'formal' => 'einem formellen, professionellen Stil',
                'casual' => 'einem lockeren, ungezwungenen Stil',
                'business' => 'einem geschäftlichen, sachlichen Stil',
                'academic' => 'einem akademischen, wissenschaftlichen Stil',
                'creative' => 'einem kreativen, ausdrucksstarken Stil',
                'simple' => 'einer einfachen, klaren Sprache',
            ];

            $styleDesc = $styleMap[strtolower($style)] ?? $style;
            $prompt .= "\n\nSchreibe den Text in {$styleDesc}.";
        }

        if ($tone) {
            $toneMap = [
                'enthusiastic' => 'enthusiastischen, begeisterten',
                'friendly' => 'freundlichen, herzlichen',
                'confident' => 'selbstbewussten, überzeugten',
                'diplomatic' => 'diplomatischen, taktvollen',
            ];
            $toneDesc = $toneMap[strtolower($tone)] ?? $tone;
            $prompt .= "\n\nVerwende einen {$toneDesc} Tonfall.";
        }

        if ($formality) {
            $formMap = [
                'formal' => 'formell (Sie-Form)',
                'informal' => 'informell (Du-Form)',
            ];
            $formDesc = $formMap[strtolower($formality)] ?? $formality;
            $prompt .= "\n\nSchreibe den Text {$formDesc}.";
        }

        return $prompt;
    }
}
