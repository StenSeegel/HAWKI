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
        private TranslationUsageLogger $usageLogger,
        private TranslationService $translationService
    ) {}

    /**
     * Improve text using AI
     *
     * @param  string|array  $text  Text to improve
     * @param  string|null  $sourceLang  Source language (optional)
     * @param  string|null  $targetLang  Target language (optional)
     * @param  string|null  $modelId  Model ID to use (optional, uses default if not provided)
     * @param  string|null  $style  Writing style (optional)
     * @param  string|null  $tone  Writing tone (optional)
     * @param  string|null  $formality  Formality (optional)
     * @param  array|null  $exclusions  Existing variants to avoid (optional)
     * @param  string  $type  Type of improvement (default, alternatives, synonyms, correction)
     * @param  string|null  $context  Optional context (e.g. surrounding sentence) for the text (optional)
     * @return array{text: string}
     *
     * @throws TranslationFailedException
     */
    public function improveText(string|array $text, ?string $sourceLang = null, ?string $targetLang = null, ?string $modelId = null, ?string $style = null, ?string $tone = null, ?string $formality = null, ?array $exclusions = null, string $type = 'default', ?string $context = null): array
    {
        $isBatch = is_array($text);

        try {
            // Determine which model to use
            $modelIdToUse = null;
            $utilityTypes = ['alternatives', 'synonyms', 'correction'];

            if (in_array($type, $utilityTypes)) {
                // If a model is explicitly provided (e.g. via user selection in the UI), we use it.
                // Otherwise, we use the admin-configured default for this specific utility type.
                if (! empty($modelId)) {
                    $modelIdToUse = $modelId;
                } else {
                    $modelIdToUse = $this->translationService->resolveDefaultModelForType($type, false);
                }

                // If no specific model is provided and no default is configured, we MUST NOT use a generic fallback for utilities.
                if (! $modelIdToUse) {
                    throw new TranslationFailedException("Kein Modell für '$type' in den Übersetzungseinstellungen konfiguriert.");
                }
            } else {
                // Default rephrasing: Use user selection if provided, otherwise resolve with possible fallback to translate_model
                if (! empty($modelId)) {
                    $modelIdToUse = $modelId;
                } else {
                    $modelIdToUse = $this->translationService->resolveDefaultModelForType($type, true);
                }

                // Final fallback for rephrase (must be from the allowed list if possible)
                if (! $modelIdToUse) {
                    $modelIdToUse = $this->translationService->getDefaultModelId();
                }
            }

            if (! $modelIdToUse) {
                throw new TranslationFailedException('Kein KI-Modell verfügbar.');
            }

            if ($this->translationService->shouldShowDebug()) {
                $label = match ($type) {
                    'rephrase', 'default', 'improvement' => '[Text Rephrase]',
                    'alternatives' => '[Sentence Replacement]',
                    'synonyms' => '[Word Replacement]',
                    'correction' => '[Sentence Correction]',
                    default => '['.ucfirst($type).']',
                };

                $logContext = [
                    'model_id' => $modelIdToUse,
                    'target_lang' => $targetLang,
                    'style' => $style,
                    'tone' => $tone,
                    'formality' => $formality,
                    'type' => $type,
                    'text_length' => is_array($text) ? strlen(implode(' ', $text)) : strlen($text),
                ];

                Log::debug("{$label} Requested", $logContext);

                if ($this->translationService->shouldShowPayload()) {
                    Log::debug("{$label} Request Payload", [
                        'payload' => [
                            'text' => $text,
                            'source_lang' => $sourceLang,
                            'target_lang' => $targetLang,
                            'style' => $style,
                            'tone' => $tone,
                            'formality' => $formality,
                            'model' => $modelIdToUse,
                            'type' => $type,
                            'context' => $context,
                        ],
                    ]);
                }
            }

            // Clean User Prompt
            if ($isBatch) {
                $userPrompt = json_encode($text, JSON_UNESCAPED_UNICODE);
            } elseif ($type === 'synonyms' && ! empty($context)) {
                // For word replacements: Explicitly separate the word and its context
                $userPrompt = "WORD-TO-REPLACE: " . (is_array($text) ? implode(' ', $text) : $text) . "\n" .
                              "IN CONTEXT: " . $context;
            } elseif ($type === 'correction' && $context) {
                $userPrompt = "ORIGINAL:\n".$context."\n\nNEU:\n".$text;
            } else {
                $userPrompt = $context ?: (is_array($text) ? implode(' ', $text) : $text);
            }

            // Build payload for AI request
            $payload = [
                'model' => $modelIdToUse,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => [
                            'text' => $systemPrompt = $this->getSystemPrompt($type, $isBatch, $sourceLang, $targetLang, $style, $tone, $formality, $exclusions, $context),
                        ],
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            'text' => $userPrompt,
                        ],
                    ],
                ],
                'temperature' => $this->getTemperatureForType($type, $style, $tone),
                'max_tokens' => 4000,
                'stream' => false,
            ];

            if ($this->translationService->shouldShowDebug() && $type === 'synonyms') {
                Log::debug('[Word Replacement] Context', ['prompt' => $userPrompt, 'system' => $systemPrompt]);
            }

            // Send request to AI - AiService accepts array or AiRequest
            $response = $this->aiService->sendRequest($payload);

            // Extract improved text from response
            $improvedText = $response->content['text'] ?? '';

            // Robust markdown stripping (common for some models like Gemma)
            $improvedText = trim($improvedText);
            if (str_starts_with($improvedText, '```')) {
                // Remove starting ```json or ```
                $improvedText = preg_replace('/^```(?:json)?\s*/i', '', $improvedText);
                // Remove ending ```
                $improvedText = preg_replace('/\s*```$/', '', $improvedText);
                $improvedText = trim($improvedText);
            }

            if ($isBatch || $type === 'alternatives' || $type === 'synonyms') {
                try {
                    // Try direct JSON decode
                    $decoded = json_decode($improvedText, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $improvedText = $decoded;
                    }
                } catch (\Exception $e) {
                    // Fallback: search for JSON block
                    if (preg_match('/\{.*?\}/s', $improvedText, $matches)) {
                        try {
                            $decoded = json_decode($matches[0], true, 512, JSON_THROW_ON_ERROR);
                            if (is_array($decoded)) {
                                $improvedText = $decoded;
                            }
                        } catch (\Exception $inner) {
                        }
                    }

                    if ($isBatch && ! is_array($improvedText)) {
                        Log::warning('Failed to decode batch improvement result', ['error' => $e->getMessage(), 'content' => $improvedText]);
                    }
                }
            }

            if (empty($improvedText)) {
                throw new TranslationFailedException('AI returned empty response');
            }

            // Log usage
            $this->usageLogger->logImprovement(
                providerName: 'ai-improvement', // Will be resolved by logger using model ID
                model: $modelIdToUse,
                promptChars: is_array($text) ? strlen(implode(' ', $text)) : strlen($text),
                completionChars: is_array($improvedText) ? strlen(implode(' ', $improvedText)) : strlen($improvedText),
                aiUsage: $response->usage
            );

            $improvedTextForLength = is_array($improvedText) ? json_encode($improvedText) : $improvedText;

            if ($this->translationService->shouldShowDebug()) {
                $label = match ($type) {
                    'rephrase', 'default', 'improvement' => '[Text Rephrase]',
                    'alternatives' => '[Sentence Replacement]',
                    'synonyms' => '[Word Replacement]',
                    'correction' => '[Sentence Correction]',
                    default => '['.ucfirst($type).']',
                };

                $logContext = [
                    'model_id' => $modelIdToUse,
                    'result_length' => strlen($improvedTextForLength),
                ];

                Log::debug("{$label} Completed", $logContext);

                if ($this->translationService->shouldShowPayload()) {
                    Log::debug("{$label} Result Payload", [
                        'result' => $improvedText,
                    ]);
                }
            }

            return [
                'text' => is_array($improvedText) ? $improvedText : trim($improvedText),
            ];

        } catch (\Exception $e) {
            if ($this->translationService->shouldShowDebug()) {
                $label = match ($type) {
                    'rephrase', 'default', 'improvement' => '[Text Rephrase]',
                    'alternatives' => '[Sentence Replacement]',
                    'synonyms' => '[Word Replacement]',
                    'correction' => '[Sentence Correction]',
                    default => '['.ucfirst($type).']',
                };
                Log::error("{$label} Failed", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            throw new TranslationFailedException('Text improvement failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the system prompt based on the type of improvement.
     */
    protected function getSystemPrompt(
        string $type,
        bool $isBatch,
        ?string $sourceLang = null,
        ?string $targetLang = null,
        ?string $style = null,
        ?string $tone = null,
        ?string $formality = null,
        ?array $exclusions = null,
        ?string $context = null
    ): string {
        $langMap = [
            'de' => 'German',
            'en' => 'English',
            'en-GB' => 'British English',
            'en-US' => 'American English',
            'fr' => 'French',
            'es' => 'Spanish',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'pt-BR' => 'Brazilian Portuguese',
            'uk' => 'Ukrainian',
            'ru' => 'Russian',
            'pl' => 'Polish',
            'nl' => 'Dutch',
            'zh' => 'Chinese',
            'ja' => 'Japanese',
            'tr' => 'Turkish',
            'ar' => 'Arabic',
        ];

        $batchInstruction = $isBatch ? ' Since the input is a JSON array of sentences, you MUST return a RAW JSON array with the improved sentences in the same order. DO NOT use markdown code blocks (like ```json ... ```). Output must start with [ and end with ]. Example: ["Sentence 1", "Sentence 2"].' : '';

        // Base instructions depending on type
        $basePrompt = match ($type) {
            'alternatives' => $isBatch
                ? 'You are an assistant for creative text improvement. Your goal is to formulate stylistically high-quality and varied alternatives. Correct spelling and grammar, but focus primarily on an appealing redesign. Return ONLY the improved text, without explanations or additional comments.'.$batchInstruction
                : "You are an assistant for creative text improvement. Your goal is to formulate stylistically high-quality and varied alternatives.\n\n".
                "TASK: Provide 3 suitable alternatives for the input text. Correct spelling and grammar, but focus primarily on an appealing redesign.\n\n".
                "RULES:\n".
                "1. ONLY RAW JSON: Respond EXCLUSIVELY with a raw JSON array of 3 strings. DO NOT use markdown code blocks (like ```json ... ```) or any explanations. Output must start with [ and end with ].\n".
                "2. VARIETY: The alternatives should differ in style and tone while keeping the original meaning.\n".
                '3. Example: ["Alternative 1", "Alternative 2", "Alternative 3"]',

            'synonyms' => "You are a linguistic expert for word alternatives and synonyms.\n\n".
                          "TASK: Provide 5 suitable synonyms or alternatives for the word marked with [[TARGET]] in the input sentence. Never translate the word into another language; always stay in the same language as the sentence.\n\n".
                          "RULES:\n".
                          "1. PART OF SPEECH: The alternative must be of the SAME part of speech as the target word (e.g., replace a noun with a noun, a verb with a verb). Never omit the target word's core meaning or head of the phrase.\n".
                          "2. GRAMMAR: Adjust the alternative EXACTLY to the grammatical form (case, number, gender, person, tense) of the target word in the sentence.\n".
                          "3. CONTEXT: The alternative must fit semantically and syntactically perfectly into the sentence. It must be a drop-in replacement.\n".
                          "4. ONLY RAW JSON: Respond EXCLUSIVELY with a raw JSON array. DO NOT use markdown code blocks (like ```json ... ```) or any explanations. Output must start with [ and end with ].\n".
                          '5. Example: ["Word 1", "Word 2", "Word 3", "Word 4", "Word 5"]',

            'correction' => "You are a correction assistant. Your task is to correct grammar, spelling, punctuation, and syntactic harmony in the input text.\n\n".
                            "RULES:\n".
                            "1. SYNTAX: Ensure that the sentence structure still sounds natural after a word replacement (e.g., by a synonym). Adjust prepositions, articles, or verb positions if the new word requires it.\n".
                            "2. GRAMMAR: Correct all inflection errors, agreement errors, and the placement of separable verbs.\n".
                            "3. PARTICLE CORRECTION: If a separable verb was replaced by a non-separable one, remove the remaining particle (e.g., \"an\", \"auf\", \"ab\") at the end of the sentence.\n".
                            '4. ONLY TEXT: Respond EXCLUSIVELY with the corrected sentence (no JSON, no explanations).',

            default => 'You are an assistant for text improvement and stylistic adaptation. Correct spelling, grammar, and if a style or tone is requested, rewrite the text to strictly adapt it to those requirements. Return ONLY the improved text, without explanations or additional comments.'.$batchInstruction,
        };

        $prompt = $basePrompt."\n\nMANDATORY INSTRUCTIONS FOR THIS ASSIGNMENT:\n";
        $prompt .= "- PRESERVE HTML: If the input contains HTML tags, preserve the tag structure and characters EXACTLY. ONLY improve the text content inside the tags.\n";
        $prompt .= "- NO EXTRA CONTENT: Do NOT add new line breaks ``\n``, indentation, or escape characters like ``\"`` or ``\`` to the HTML code. Use the exact same formatting as the input.\n";
        $prompt .= "- PRESERVE WHITESPACE: Do NOT trim leading or trailing whitespace or newlines from the segments. Return each string with its original trailing/leading formatting intact.\n";

        if ($isBatch) {
            $prompt .= "- The user input is a JSON array. Improve the elements individually.\n";
        } elseif ($context && $type === 'synonyms') {
            $prompt .= "- The user input is a sentence in which the target word is marked with [[TARGET]].\n";
            if (str_contains($context, '<') && str_contains($context, '>')) {
                $prompt .= "- The input contains HTML/code. Preserve all tags exactly. Provide only the replacement for the text inside [[TARGET]].\n";
            }
        } elseif ($context && $type === 'correction') {
            $prompt .= "- The user input consists of an ORIGINAL sentence and a new (NEW) sentence containing the inserted synonym. Your task is to syntactically complete the NEW sentence based on the ORIGINAL sentence correctly.\n";
        } else {
            $prompt .= "- The user input is the text to be improved.\n";
        }

        if ($targetLang) {
            $language = $langMap[strtolower($targetLang)] ?? $targetLang;
            $isTranslation = ($sourceLang && strtolower($sourceLang) !== strtolower($targetLang));

            if (in_array($type, ['alternatives', 'synonyms', 'correction']) || ! $isTranslation) {
                $prompt .= "- The text is in {$language}. Do NOT create a translation, but process the text exclusively in {$language}.\n";
            } else {
                $prompt .= "- Ensure that the result is in {$language}.\n";
            }
        }

        if ($style) {
            $styleMap = [
                'formal' => 'a formal, professional style. Use elevated vocabulary and sophisticated sentence structure',
                'casual' => 'a relaxed, informal, and conversational style. Sound like you are talking to a friend',
                'business' => 'a professional, crisp, and business-like style. Get straight to the point',
                'academic' => 'an academic, objective, and scholarly style. Avoid emotional language',
                'creative' => 'a creative, expressive, and engaging style. Feel free to use metaphors and vivid imagery',
                'simple' => 'a very simple and clear language (Plain Language). Use short sentences, everyday words, and avoid complex clauses. You MUST drastically rewrite old-fashioned or complex texts so a beginner could understand them',
            ];
            $styleDesc = $styleMap[strtolower($style)] ?? $style;
            $prompt .= "- WRITING STYLE: Adapt the text to {$styleDesc}. You MUST rewrite the phrasing and vocabulary completely if needed to match this exact style. Do not just fix grammar.\n";
        }

        if ($tone) {
            $toneMap = [
                'enthusiastic' => 'enthusiastic and excited',
                'friendly' => 'friendly and warm',
                'confident' => 'confident and convincing',
                'diplomatic' => 'diplomatic and tactful',
            ];
            $toneDesc = $toneMap[strtolower($tone)] ?? $tone;
            $prompt .= "- TONE: Emulate a {$toneDesc} tone. Adjust the emotional weight of words significantly to convey this feeling.\n";
        }

        if ($formality) {
            $formMap = [
                'formal' => 'strictly formal (e.g., using "Sie" in German)',
                'informal' => 'informal (e.g., using "Du" in German)',
            ];
            $formDesc = $formMap[strtolower($formality)] ?? $formality;
            $prompt .= "- FORMALITY: The text must be {$formDesc}. Ensure pronouns and addresses are consistently adapted.\n";
        }

        if (! empty($exclusions)) {
            $prompt .= "- Create a version that differs SIGNIFICANTLY from the following variants:\n  * ".implode("\n  * ", $exclusions)."\n";
        }

        return $prompt;
    }

    /**
     * Define the temperature for different improvement types.
     */
    protected function getTemperatureForType(string $type, ?string $style = null, ?string $tone = null): float
    {
        return match ($type) {
            'alternatives' => 0.6,    // Balanced creativity for sentence rephrasing
            'synonyms' => 0.9,        // High temperature for diverse synonyms
            'correction' => 0.2,      // Very low temperature for deterministic grammar fixing
            default => ($style || $tone) ? 0.7 : 0.3, // Allow higher creativity when a specific style/tone is requested
        };
    }
}
