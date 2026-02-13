<?php
declare(strict_types=1);

namespace App\Services\Translation;

use App\Services\AI\AiService;
use App\Services\Translation\Exceptions\TranslationFailedException;
use Illuminate\Support\Facades\Log;

class TextImprovementService
{
    public function __construct(
        private AiService $aiService
    ) {
    }

    /**
     * Get available AI models for text improvement
     *
     * @return array
     */
    public function getAvailableModels(): array
    {
        try {
            $availableModels = $this->aiService->getAvailableModels();
            
            $models = [];
            
            // Add DeepL Write as first option if API key is configured
            if (TranslationFactory::isActive('deepl')) {
                $models[] = [
                    'id' => 'deepl-write',
                    'label' => 'DeepL API Pro',
                    'provider' => 'deepl',
                    'provider_name' => 'DeepL',
                    'provider_display_order' => 0, // Show first
                    'status' => 'online',
                    'visible' => true,
                ];
            }
            
            // Get models as array to include all fields (provider_name, provider_display_order, etc.)
            $aiModelsArray = $availableModels->toArray();
            foreach ($aiModelsArray['models'] as $model) {
                $models[] = $model; // Already includes all fields we need
            }
            
            // If no models available, return default fallback
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
            
            return $models;
        } catch (\Exception $e) {
            Log::error('Failed to get available models', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Return fallback model
            return [
                [
                    'id' => '',
                    'label' => 'Standard Modell (Default)',
                    'provider' => 'default',
                    'provider_name' => 'Default',
                    'provider_display_order' => 9999,
                    'status' => 'online',
                    'visible' => true,
                ]
            ];
        }
    }

    /**
     * Improve text using AI
     *
     * @param string $text Text to improve
     * @param string|null $targetLang Target language (optional)
     * @param string|null $modelId Model ID to use (optional, uses default if not provided)
     * @param string|null $style Writing style (optional)
     * @return array{text: string}
     * @throws TranslationFailedException
     */
    public function improveText(string $text, ?string $targetLang = null, ?string $modelId = null, ?string $style = null): array
    {
        try {
            // Determine which model to use
            $modelIdToUse = null;
            
            if ($modelId && !empty($modelId)) {
                // User selected a specific model
                $modelIdToUse = $modelId;
            } else {
                // Use default model - get it from config
                $defaultModelId = config('model_providers.default_models.default_model');
                
                if (!$defaultModelId) {
                    throw new TranslationFailedException('No default AI model configured');
                }
                
                $modelIdToUse = $defaultModelId;
            }
            
            Log::info('TextImprovement using model', ['model_id' => $modelIdToUse]);

            // Build the prompt for text improvement
            $prompt = $this->buildImprovementPrompt($text, $targetLang, $style);

            // Build payload for AI request
            $payload = [
                'model' => $modelIdToUse,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => [
                            'text' => 'Du bist ein Assistent zur Textverbesserung. Korrigiere Rechtschreibung, Grammatik und verbessere die Formulierung. Gib NUR den verbesserten Text zurück, ohne Erklärungen oder zusätzliche Kommentare.'
                        ]
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            'text' => $prompt
                        ]
                    ]
                ],
                'temperature' => 0.3,
                'max_tokens' => 4000,
            ];

            // Send request to AI - AiService accepts array or AiRequest
            $response = $this->aiService->sendRequest($payload);

            // Extract improved text from response
            $improvedText = $response->content['text'] ?? '';
            
            if (empty($improvedText)) {
                throw new TranslationFailedException('AI returned empty response');
            }

            return [
                'text' => trim($improvedText)
            ];

        } catch (\Exception $e) {
            Log::error('Text improvement failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new TranslationFailedException('Text improvement failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Build the improvement prompt
     *
     * @param string $text
     * @param string|null $targetLang
     * @param string|null $style
     * @return string
     */
    private function buildImprovementPrompt(string $text, ?string $targetLang, ?string $style): string
    {
        $prompt = "Verbessere folgenden Text:\n\n{$text}";
        
        if ($targetLang) {
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

        return $prompt;
    }
}
