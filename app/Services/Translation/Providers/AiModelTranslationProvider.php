<?php

declare(strict_types=1);

namespace App\Services\Translation\Providers;

use App\Models\TranslateGlossaryEntry;
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
     * {@inheritDoc}
     */
    public function translate(string $text, ?string $sourceLang, string $targetLang, ?int $glossaryId = null, ?string $formality = null): array
    {
        // 1. Build System Prompt
        $glossaryInstructions = '';
        if ($glossaryId && $sourceLang) {
            $entries = $this->getGlossaryEntries($glossaryId, $sourceLang, $targetLang, $text);
            if (! empty($entries)) {
                $glossaryInstructions = "\n\nUSE THE FOLLOWING GLOSSARY TERMS STRICTLY:\n";
                foreach ($entries as $source => $target) {
                    $glossaryInstructions .= "- \"$source\" -> \"$target\"\n";
                }
            }
        }

        $systemPrompt = $this->buildSystemPrompt($sourceLang, $targetLang, $glossaryInstructions, $formality);

        // 2. Build User Prompt
        $userPrompt = $text;

        // 3. Send Request
        try {
            $payload = [
                'model' => $this->modelId,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => ['text' => $systemPrompt],
                    ],
                    [
                        'role' => 'user',
                        'content' => ['text' => $userPrompt],
                    ],
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
                'error' => $e->getMessage(),
            ]);
            throw new TranslationFailedException('AI Translation failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritDoc}
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
     * {@inheritDoc}
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
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'ai-model-'.$this->modelId;
    }

    private function buildSystemPrompt(?string $sourceLang, string $targetLang, string $glossaryInstructions = '', ?string $formality = null, ?string $style = null): string
    {
        $sourceInstruction = $sourceLang ? "from language code '$sourceLang'" : 'detecting the source language';
        $prompt = "You are a professional translation engine.\n";
        $prompt .= "Translate the user input $sourceInstruction to language code '$targetLang'.\n";

        if ($formality) {
            $formalityMap = [
                'formal' => 'formal and polite (use formal address forms, e.g. "Sie" in German)',
                'informal' => 'informal and casual (use informal address forms, e.g. "du" in German)',
                'more' => 'formal and polite',
                'less' => 'informal and casual',
            ];
            $desc = $formalityMap[$formality] ?? $formality;
            $prompt .= "Use a $desc tone.\n";
        }

        if ($style) {
            $styleMap = [
                'business' => 'professional business language',
                'academic' => 'academic and scientific language',
                'casual' => 'casual, everyday language',
                'simple' => 'simple, plain language that is easy to understand',
            ];
            $desc = $styleMap[$style] ?? $style;
            $prompt .= "Write in $desc.\n";
        }

        $prompt .= $glossaryInstructions."\n";
        $prompt .= <<<'EOT'
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

        return $prompt;
    }

    private function parseResponse(string $content): array
    {
        // Clean markdown code blocks if present (LLMs love them)
        $cleaned = preg_replace('/^```json\s*|\s*```$/', '', trim($content));

        try {
            $data = json_decode($cleaned, true, 512, JSON_THROW_ON_ERROR);

            if (! isset($data['text'])) {
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

    /**
     * Fetch glossary entries for the given languages
     *
     * @return array<string, string>
     */
    private function getGlossaryEntries(int $glossaryId, string $sourceLang, string $targetLang, string $text): array
    {
        $entries = TranslateGlossaryEntry::where('glossary_id', $glossaryId)
            ->where('source_language', strtoupper($sourceLang))
            ->where('target_language', strtoupper($targetLang))
            ->get();

        $filtered = [];
        $shouldFilter = config('translation.filter_glossary', true);

        foreach ($entries as $entry) {
            if ($shouldFilter) {
                $found = $entry->case_sensitive
                    ? str_contains($text, $entry->source_term)
                    : stripos($text, $entry->source_term) !== false;

                if (! $found) {
                    continue;
                }
            }
            $filtered[$entry->source_term] = $entry->target_term;
        }

        return $filtered;
    }
}
