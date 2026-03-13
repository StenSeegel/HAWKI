<?php

declare(strict_types=1);

namespace App\Services\Translation\Providers;

use App\Models\TranslateGlossaryEntry;
use App\Services\AI\AiService;
use App\Services\Translation\Contracts\TranslationProviderInterface;
use App\Services\Translation\Exceptions\TranslationFailedException;
use App\Services\Translation\Utils\SmartSplitGlossaryTrait;
use Illuminate\Support\Facades\Log;

class AiModelTranslationProvider implements TranslationProviderInterface
{
    use SmartSplitGlossaryTrait;

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
    public function translate(string $text, ?string $sourceLang, string $targetLang, int|array|null $glossaryId = null, ?string $formality = null): array
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
            $result = $this->parseResponse($content);
            $result['usage'] = $response->usage;

            return $result;

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
    private function getGlossaryEntries(int|array $glossaryId, string $sourceLang, string $targetLang, string $text): array
    {
        $sourceLang = strtoupper($sourceLang);
        $targetLang = strtoupper($targetLang);

        $entries = TranslateGlossaryEntry::whereIn('glossary_id', (array) $glossaryId)
            ->where(function ($query) use ($sourceLang, $targetLang) {
                $query->where(function ($q) use ($sourceLang, $targetLang) {
                    $q->where('source_language', $sourceLang)
                        ->where('target_language', $targetLang);
                })->orWhere(function ($q) use ($sourceLang, $targetLang) {
                    $q->where('source_language', $targetLang)
                        ->where('target_language', $sourceLang);
                });
            })
            ->get();

        $filtered = [];
        $shouldFilter = \App\Models\TranslateSetting::where('key', 'filter_glossary')->first()?->typed_value ?? true;

        foreach ($entries as $entry) {
            $isDirect = ($entry->source_language === $sourceLang && $entry->target_language === $targetLang);
            $sTermRaw = $isDirect ? $entry->source_term : $entry->target_term;
            $tTermRaw = $isDirect ? $entry->target_term : $entry->source_term;

            // Apply Smart Split: If term looks like "Long Form (Acronym)", check for each variant
            $sourceVariants = $this->getTermVariants($sTermRaw);

            foreach ($sourceVariants as $variant) {
                if ($shouldFilter) {
                    $found = $entry->case_sensitive
                        ? str_contains($text, $variant)
                        : stripos($text, $variant) !== false;

                    if (! $found) {
                        continue;
                    }
                }
                $filtered[$variant] = $tTermRaw;
            }
        }

        return $filtered;
    }
}
