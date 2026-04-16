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
    public function translate(string|array $text, ?string $sourceLang, string $targetLang, int|array|null $glossaryId = null, ?string $formality = null): array
    {
        $isBatch = is_array($text);

        // 1. Build System Prompt
        $glossaryInstructions = '';
        if ($glossaryId && $sourceLang) {
            $textForGlossary = $isBatch ? implode(' ', (array) $text) : (string) $text;
            $entries = $this->getGlossaryEntries($glossaryId, $sourceLang, $targetLang, $textForGlossary);
            if (! empty($entries)) {
                $glossaryInstructions = "\n\nUSE THE FOLLOWING GLOSSARY TERMS STRICTLY:\n";
                foreach ($entries as $source => $target) {
                    $glossaryInstructions .= "- \"$source\" -> \"$target\"\n";
                }
            }
        }

        $systemPrompt = $this->buildSystemPrompt($sourceLang, $targetLang, $glossaryInstructions, $formality, null, $isBatch);

        // 2. Build User Prompt
        // If it's a batch of sentences, we send it as a JSON array string to ensure the AI 
        // treats the elements as distinct units if the provider requires string input.
        $userPrompt = $isBatch ? json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $text;

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
                'max_tokens' => 4000,
                'stream' => false,
            ];

            $response = $this->aiService->sendRequest($payload);

            // Check for API-level errors
            if ($response->error) {
                throw new TranslationFailedException('AI API Error: '.$response->error);
            }

            $content = $response->content['text'] ?? '';
            
            // Ensure any HTML entities returned by the AI are decoded to raw characters
            // (prevents double-escaping in the UI)
            if (str_contains($content, '&lt;') || str_contains($content, '&gt;')) {
                $content = htmlspecialchars_decode($content);
            }

            // 4. Parse Response
            $result = $this->parseResponse($content, $isBatch);

            // If batching failed to return correct array length, we have a problem
            if ($isBatch && is_array($result['text']) && count($result['text']) !== count((array) $text)) {
                Log::warning('Translation batch length mismatch', [
                    'expected' => count((array) $text),
                    'actual' => count($result['text']),
                ]);
            }

            return [
                'text' => $result['text'],
                'detected_source_language' => $result['detected_source_language'],
                'usage' => $response->usage,
            ];
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
            'EN-GB' => 'English (British)',
            'EN-US' => 'English (American)',
            'DE' => 'German',
            'UK' => 'Ukrainian',
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

    private function buildSystemPrompt(?string $sourceLang, string $targetLang, string $glossaryInstructions = '', ?string $formality = null, ?string $style = null, bool $isBatch = false): string
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

        if ($isBatch) {
            $prompt .= "The input is a JSON array of sentences. You MUST return a JSON object containing a 'text' field which is an array of strings, where each element corresponds to the input array element at the same index.\n";
        }

        $prompt .= <<<'EOT'
CRITICAL OUTPUT RULES:
1. Return ONLY valid JSON. No markdown formatting, no explanations.
2. The JSON must follow this exact structure:
{
    "text": "The translated text here (or array of strings if input was array)",
    "detected_source_language": "The detected 2-letter source language code (e.g. EN, DE, FR)"
}
3. If the input is just a few words, translate them accurately.
4. Do not include '```json' or similar markers. Just the raw JSON string.
5. PRESERVE HTML: If the input contains HTML tags, preserve the tag structure and characters EXACTLY. ONLY translate the text content inside the tags.
6. NO EXTRA CONTENT: Do NOT add new line breaks (\n), indentation, or escape characters (like \") to the HTML code. Use the exact same formatting as the input.
7. PRESERVE WHITESPACE: Do NOT trim leading or trailing whitespace/newlines from the input. Return each segment exactly as formatted.
EOT;

        return $prompt;
    }

    private function parseResponse(string $content, bool $isBatch = false): array
    {
        $rawContent = $content;

        // Remove markdown blocks if present
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        $content = trim($content);

        try {
            // Try direct JSON decode first
            $json = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            return [
                'text' => $json['text'] ?? $content,
                'detected_source_language' => $json['detected_source_language'] ?? null,
            ];
        } catch (\Exception $e) {
            // Fallback: Try to find a JSON block { ... } within the content
            // (LLMs sometimes add explanation text before or after the JSON)
            if (preg_match('/\{.*\}/s', $content, $matches)) {
                try {
                    $json = json_decode($matches[0], true, 512, JSON_THROW_ON_ERROR);

                    return [
                        'text' => $json['text'] ?? $rawContent,
                        'detected_source_language' => $json['detected_source_language'] ?? null,
                    ];
                } catch (\Exception $inner) {
                    // fall back further
                }
            }

            // Final fallback: return the raw content
            return [
                'text' => $rawContent,
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

        $baseSource = explode('-', $sourceLang)[0];
        $baseTarget = explode('-', $targetLang)[0];

        $entries = TranslateGlossaryEntry::whereIn('glossary_id', (array) $glossaryId)
            ->where(function ($query) use ($sourceLang, $targetLang, $baseSource, $baseTarget) {
                // Direct or base match
                $query->where(function ($q) use ($sourceLang, $targetLang, $baseSource, $baseTarget) {
                    $q->whereIn('source_language', [$sourceLang, $baseSource])
                        ->whereIn('target_language', [$targetLang, $baseTarget]);
                })
                // Inverse match
                    ->orWhere(function ($q) use ($sourceLang, $targetLang, $baseSource, $baseTarget) {
                        $q->whereIn('source_language', [$targetLang, $baseTarget])
                            ->whereIn('target_language', [$sourceLang, $baseSource]);
                    });
            })
            ->get();

        $filtered = [];
        $shouldFilter = \App\Models\TranslateSetting::where('key', 'filter_glossary')->first()?->typed_value ?? true;

        foreach ($entries as $entry) {
            $isDirect = ($entry->source_language === $sourceLang || $entry->source_language === $baseSource);
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
