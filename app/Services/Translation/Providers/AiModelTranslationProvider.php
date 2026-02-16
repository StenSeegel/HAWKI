<?php

declare(strict_types=1);

namespace App\Services\Translation\Providers;

use App\Services\AI\AiService;
use App\Services\Translation\Contracts\TranslationProviderInterface;
use App\Services\Translation\Exceptions\TranslationFailedException;
use Illuminate\Support\Facades\Log;

class AiModelTranslationProvider implements TranslationProviderInterface
{
    private AiService $aiService;
    private string $modelId;

    public function __construct(AiService $aiService, string $modelId)
    {
        $this->aiService = $aiService;
        $this->modelId = $modelId;
    }

    /**
     * @inheritDoc
     */
    public function translate(string $text, ?string $sourceLang, string $targetLang, ?int $glossaryId = null): array
    {
        // 1. Build System Prompt
        $systemPrompt = $this->buildSystemPrompt($sourceLang, $targetLang);

        // 2. Build User Prompt
        $userPrompt = $text;

        // 3. Send Request
        try {
            $payload = [
                'model' => $this->modelId,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => ['text' => $systemPrompt]
                    ],
                    [
                        'role' => 'user',
                        'content' => ['text' => $userPrompt]
                    ]
                ],
                'temperature' => 0.0, // Low temperature for deterministic output
            ];

            $response = $this->aiService->sendRequest($payload);
            $content = $response->content['text'] ?? '';

            // 4. Parse Response
            return $this->parseResponse($content);

        } catch (\Exception $e) {
            Log::error('AI Translation failed', [
                'model' => $this->modelId,
                'error' => $e->getMessage()
            ]);
            throw new TranslationFailedException("AI Translation failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function getSupportedLanguages(): array
    {
        // LLMs generally support many languages, but we can return a standard list or empty
        // Returning a common set of languages for UI purposes
        return [
            'EN' => 'English',
            'DE' => 'German',
            'FR' => 'French',
            'ES' => 'Spanish',
            'IT' => 'Italian',
            'PT' => 'Portuguese',
            'NL' => 'Dutch',
            'PL' => 'Polish',
            'RU' => 'Russian',
            'JA' => 'Japanese',
            'ZH' => 'Chinese',
        ];
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(): bool
    {
        try {
            $model = $this->aiService->getModel($this->modelId);
            return $model !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'ai-model-' . $this->modelId;
    }

    private function buildSystemPrompt(?string $sourceLang, string $targetLang): string
    {
        $sourceInstruction = $sourceLang ? "from language code '$sourceLang'" : "detecting the source language";
        
        return <<<EOT
You are a professional translation engine.
Translate the user input $sourceInstruction to language code '$targetLang'.

CRITICAL OUTPUT RULES:
1. Return ONLY valid JSON. No markdown formatting, no explanations.
2. The JSON must follow this exact structure:
{
    "text": "The translated text here",
    "detected_source_language": "The detected 2-letter source language code (e.g. EN, DE, FR)"
}
3. If the input is just a few words, translate them accurately.
4. Do not include '```json' or similar markers. Just the raw JSON string.
EOT;
    }

    private function parseResponse(string $content): array
    {
        // Clean markdown code blocks if present (LLMs love them)
        $cleaned = preg_replace('/^```json\s*|\s*```$/', '', trim($content));
        
        try {
            $data = json_decode($cleaned, true, 512, JSON_THROW_ON_ERROR);
            
            if (!isset($data['text'])) {
                throw new \Exception('Missing "text" field in JSON');
            }
            
            return [
                'text' => $data['text'],
                'detected_source_language' => $data['detected_source_language'] ?? null,
            ];
            
        } catch (\JsonException $e) {
            // Fallback: If JSON parsing fails, assume the whole content is the translation
            // This is risky but better than failing completely if the LLM was chatty
            Log::warning('AI Translation returned non-JSON response, using raw content', ['content' => $content]);
            
            return [
                'text' => $content,
                'detected_source_language' => null, 
            ];
        }
    }
}
