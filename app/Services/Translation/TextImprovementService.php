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
        private TranslationService $translationService,
        private ComposeAgentService $composeAgentService
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
    public function improveText(string|array $text, ?string $sourceLang = null, ?string $targetLang = null, ?string $modelId = null, ?string $style = null, ?string $tone = null, ?string $formality = null, ?array $exclusions = null, string $type = 'default', ?string $context = null, ?bool $webSearchEnabled = null): array
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
                    'proofread' => '[Text Proofreading]',
                    'key_points' => '[Text Key Points]',
                    'paraphrase' => '[Text Paraphrase]',
                    'shorten' => '[Text Shorten]',
                    'expand' => '[Text Expand]',
                    'list' => '[Text List]',
                    'table' => '[Text Table]',
                    'compose' => '[Text Compose]',
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
            if ($type === 'compose' && ! empty($style)) {
                $userPrompt = "INSTRUCTION / PROMPT:\n".$style."\n\n";
                if (! empty($text)) {
                    $contextText = is_array($text) ? implode("\n", $text) : $text;
                    if (trim($contextText) !== '') {
                        $userPrompt .= "CONTEXT / INPUT TEXT:\n".$contextText;
                    }
                }
            } elseif ($isBatch) {
                $userPrompt = json_encode($text, JSON_UNESCAPED_UNICODE);
            } elseif ($type === 'synonyms' && ! empty($context)) {
                // For word replacements: Explicitly separate the word and its context
                $userPrompt = 'WORD-TO-REPLACE: '.(is_array($text) ? implode(' ', $text) : $text)."\n".
                              'IN CONTEXT: '.$context;
            } elseif ($type === 'correction' && $context) {
                $userPrompt = "ORIGINAL:\n".$context."\n\nNEU:\n".$text;
            } else {
                $userPrompt = $context ?: (is_array($text) ? implode(' ', $text) : $text);
            }

            $systemPrompt = $this->getSystemPrompt($type, $isBatch, $sourceLang, $targetLang, $style, $tone, $formality, $exclusions, $context, $type === 'compose' ? false : ($webSearchEnabled ?? true));

            if ($type === 'compose') {
                $composeResult = $this->composeAgentService->compose(
                    userPrompt: $userPrompt,
                    systemPrompt: $systemPrompt,
                    modelId: $modelIdToUse,
                    temperature: $this->getTemperatureForType($type, $style, $tone),
                    webSearchEnabled: $webSearchEnabled ?? true
                );
                $improvedText = $composeResult['text'];
                $finalUsage = $composeResult['usage'];
            } else {
                // Build payload for AI request
                $payload = [
                    'model' => $modelIdToUse,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => [
                                'text' => $systemPrompt,
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
                $finalUsage = $response->usage;
            }

            // Robust markdown stripping (common for some models like Gemma)
            $improvedText = trim($improvedText);
            if (str_starts_with($improvedText, '```')) {
                // Check if it has a non-text language specifier we should preserve (e.g. mermaid, javascript)
                $shouldPreserve = false;
                if (preg_match('/^```([a-zA-Z0-9_-]+)/i', $improvedText, $langMatches)) {
                    $lang = strtolower($langMatches[1]);
                    if (! in_array($lang, ['text', 'plain', 'plaintext'])) {
                        $shouldPreserve = true;
                    }
                }

                if (! $shouldPreserve) {
                    // Remove starting ```lang or ```
                    $improvedText = preg_replace('/^```(?:[a-zA-Z0-9_-]+)?\s*/i', '', $improvedText);
                    // Remove ending ```
                    $improvedText = preg_replace('/\s*```$/', '', $improvedText);
                    $improvedText = trim($improvedText);
                }
            }

            // Clean up whitespace around HTML block and list tags to prevent Tiptap/ProseMirror from generating empty bullet points
            if (str_contains($improvedText, '<')) {
                $improvedText = preg_replace('/>\s+(?=<(?:\/)?(?:ul|ol|li|p|div|h[1-6])\b)/i', '>', $improvedText);
                $improvedText = preg_replace('/(<(?:\/)?(?:ul|ol|li|p|div|h[1-6])\b[^>]*>)\s+</i', '$1<', $improvedText);
            }

            if ($isBatch || $type === 'alternatives' || $type === 'synonyms') {
                $parsedArray = null;
                $decodeError = null;
                try {
                    // Try direct JSON decode
                    $decoded = json_decode($improvedText, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $parsedArray = $decoded;
                    }
                } catch (\Exception $e) {
                    $decodeError = $e->getMessage();

                    // Fallback 1: Extract from markdown code blocks
                    if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $improvedText, $matches)) {
                        try {
                            $decoded = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
                            if (is_array($decoded)) {
                                $parsedArray = $decoded;
                            }
                        } catch (\Exception $inner) {
                        }
                    }

                    // Fallback 2: Greedy search for object or array
                    if ($parsedArray === null) {
                        $candidates = [];
                        // JSON Array
                        if (preg_match('/\[.*\]/s', $improvedText, $matches)) {
                            $candidates[] = $matches[0];
                        }
                        // JSON Object
                        if (preg_match('/\{.*\}/s', $improvedText, $matches)) {
                            $candidates[] = $matches[0];
                        }

                        foreach ($candidates as $candidate) {
                            try {
                                $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
                                if (is_array($decoded)) {
                                    $parsedArray = $decoded;
                                    break;
                                }
                            } catch (\Exception $inner) {
                            }
                        }
                    }
                }

                if ($parsedArray !== null) {
                    $improvedText = $parsedArray;
                } else {
                    Log::warning('Failed to decode batch improvement result', ['error' => $decodeError, 'content' => $improvedText]);
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
                aiUsage: $finalUsage
            );

            $improvedTextForLength = is_array($improvedText) ? json_encode($improvedText) : $improvedText;

            if ($this->translationService->shouldShowDebug()) {
                $label = match ($type) {
                    'rephrase', 'default', 'improvement' => '[Text Rephrase]',
                    'alternatives' => '[Sentence Replacement]',
                    'synonyms' => '[Word Replacement]',
                    'correction' => '[Sentence Correction]',
                    'proofread' => '[Text Proofreading]',
                    'key_points' => '[Text Key Points]',
                    'paraphrase' => '[Text Paraphrase]',
                    'shorten' => '[Text Shorten]',
                    'expand' => '[Text Expand]',
                    'list' => '[Text List]',
                    'table' => '[Text Table]',
                    'compose' => '[Text Compose]',
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
                    'proofread' => '[Text Proofreading]',
                    'key_points' => '[Text Key Points]',
                    'paraphrase' => '[Text Paraphrase]',
                    'shorten' => '[Text Shorten]',
                    'expand' => '[Text Expand]',
                    'list' => '[Text List]',
                    'table' => '[Text Table]',
                    'compose' => '[Text Compose]',
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
        ?string $context = null,
        bool $webSearchEnabled = true
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
                          "3. CONTEXT: The alternative must fit semantically perfectly into the sentence. It must be a drop-in replacement.\n".
                          "4. ONLY RAW JSON: Respond EXCLUSIVELY with a raw JSON array. DO NOT use markdown code blocks (like ```json ... ```) or any explanations. Output must start with [ and end with ].\n".
                          '5. Example: ["Word 1", "Word 2", "Word 3", "Word 4", "Word 5"]',

            'correction' => "You are a correction assistant. Your task is to correct grammar, spelling, punctuation, and syntactic harmony in the input text.\n\n".
                            "RULES:\n".
                            "1. SYNTAX: Ensure that the sentence structure still sounds natural after a word replacement (e.g., by a synonym). Adjust prepositions, articles, or verb positions if the new word requires it.\n".
                            "2. GRAMMAR: Correct all inflection errors, agreement errors, and the placement of separable verbs.\n".
                            "3. PARTICLE CORRECTION: If a separable verb was replaced by a non-separable one, remove the remaining particle (e.g., \"an\", \"auf\", \"ab\") at the end of the sentence.\n".
                            '4. ONLY TEXT: Respond EXCLUSIVELY with the corrected sentence (no JSON, no explanations).',

            'proofread' => 'You are a professional editor and proofreader. Your task is to correct any grammatical, spelling, punctuation, and typographical errors in the input text. Do NOT rewrite or rephrase the text; preserve the original style, vocabulary, tone, and sentence structure as closely as possible. Only fix actual language mistakes and syntactic issues.',

            'rephrase' => 'You are an expert copywriter. Your task is to rewrite the input text to make it sound more elegant, professional, and clear while perfectly retaining the original meaning and core information. Improve sentence structure and vocabulary to enhance readability and flow.',

            'key_points' => 'You are an analytical summarizing assistant. Your task is to extract ONLY the most critical key facts, core arguments, and essential takeaways from the input text. Present them as a highly concise, focused bulleted list in the same language. FORMATTING RULE: You MUST use standard Markdown bullet points (using "-" or "*") only. Do NOT use raw HTML tags (like "<ul>", "<li>", "<p>") and do NOT wrap the output in markdown code blocks. Leave out minor details, examples, background explanations, or fluff to isolate the absolute key facts. Do not write a continuous text; only provide the summarized list.',

            'paraphrase' => 'You are a linguistic specialist in paraphrasing. Your task is to completely rewrite the input text using entirely different words and sentence structures (sinngemäße Wiedergabe in eigenen Worten) while ensuring that the original meaning and semantic content are perfectly preserved. CRITICAL RULES FOR A SCIENTIFIC PARAPHRASE: 1. PRESERVE THE MEANING: Keep the original statement 100% complete and intact. Do not add any new information and do not omit any central facts. 2. DEEP RESTRUCTURING: Change both sentence structure and vocabulary significantly. Do NOT merely swap a few words for synonyms while keeping the original syntax (avoid shallow rephrasing). 3. ACADEMIC STYLE: Use a formal, objective, and scientific tone (sachlicher, wissenschaftlicher Stil). 4. PRESERVE & INTEGRATE CITATIONS: If the input contains a citation, author, book, or source (e.g. "(Wittgenstein, Tractatus logico-philosophicus)" or "(Mandela)"), you MUST preserve it and are encouraged to integrate the author/source cleanly into the sentence flow using academic attribution verbs and signal words (e.g., "Nach Ansicht von...", "Wie [Autor] beschreibt/feststellt...", "[Autor] betont/argumentiert/vertritt die Auffassung, dass..."). FEW-SHOT EXAMPLES: Example 1: Philosophical Citation. Original: "Die Grenzen meiner Sprache bedeuten die Grenzen meiner Welt." (Wittgenstein, Tractatus logico-philosophicus) Good Paraphrase: "Wittgenstein vertritt im Tractatus logico-philosophicus die Auffassung, dass die Möglichkeiten sprachlichen Ausdrucks zugleich den Horizont dessen bestimmen, was Menschen von der Welt erfassen können." Example 2: Academic Statement. Original: "Lernen ist ein aktiver Prozess, bei dem Lernende neues Wissen auf der Grundlage vorhandener Erfahrungen konstruieren." Good Paraphrase: "Nach konstruktivistischen Lerntheorien entsteht Wissen nicht passiv, sondern wird von Lernenden aktiv aufgebaut, wobei bereits vorhandene Erfahrungen eine zentrale Rolle spielen." Example 3: Empirical Claim. Original: "Die Untersuchung zeigte, dass regelmäßige körperliche Aktivität das risiko für Herz-Kreislauf-Erkrankungen senkt." Good Paraphrase: "Die Ergebnisse der Studie weisen darauf hin, dass Personen, die sich regelmäßig körperlich betätigen, seltener von Herz-Kreislauf-Erkrankungen betroffen sind." Example 4: Definition. Original: "Künstliche Intelligenz bezeichnet Systeme, die Aufgaben ausführen können, die normalerweise menschliche Intelligenz erfordern." Good Paraphrase: "Unter künstlicher Intelligenz werden Technologien verstanden, die Tätigkeiten übernehmen, für deren Bewältigung üblicherweise menschliche kognitive Fähigkeiten notwendig sind." Example 5: Contrast (Poor vs. Good Paraphrase). Original: "Bildung ist die mächtigste Waffe, um die Welt zu verändern." (Mandela) Bad Paraphrase (Shallow synonym swap, structurally too close): "Bildung ist das stärkste Werkzeug, um die Welt zu verändern." Good Paraphrase (Deep rephrasing, scientific style, proper attribution): "Mandela betont, dass gesellschaftlicher Wandel vor allem durch den Zugang zu Bildung ermöglicht und gefördert werden kann."',

            'shorten' => 'You are a precise editor. Your task is to drastically shorten the input text, focusing only on the essential information and main messages. Remove fluff, redundant adjectives, and unnecessary explanations to make the text as concise, crisp, and direct as possible.',

            'expand' => 'You are a creative and detailed writer. Your task is to expand the input text by elaborating on the ideas, adding context, and filling in details to make the text more descriptive, comprehensive, and cohesive. Ensure the text flows naturally and remains highly readable. If the input contains a list (bullet points or numbered list), you MUST convert it into a continuous, well-structured prose/narrative flow (Fließtext) without any bullet points, lists, or numbering. CRITICAL RULE: You MUST merge all list points into ONE SINGLE, CONTINUOUS, UNIFIED PARAGRAPH. Do NOT use multiple paragraphs. Do NOT use bullet points, numbering, subheadings, bold prefixes, or bold headers (like "**Komplexität:**" or "Komplexität:"). The entire output for the list MUST be a single, cohesive, running text paragraph (ein einziger durchgehender Fließtext-Absatz) that organically connects all ideas.',

            'list' => 'You are a structured organization assistant. Your task is to convert the input prose text into a beautifully formatted list (bullet points or numbered list) WITHOUT reducing or summarizing the detail, information density, or substance of the original text. Every important detail, fact, and argument in the input must be fully retained in the list, but structured cleanly as list items rather than running prose. FORMATTING RULE: You MUST use standard Markdown bullet points (using "-" or "*") or numbering only. Do NOT use raw HTML tags (like "<ul>", "<li>", "<ol>", "<p>") and do NOT wrap the output in markdown code blocks. Group related ideas logically, but do not shorten or omit the details of the original text.',

            'table' => 'You are a data presentation expert. Your task is to transform the information in the input text into an organized, easy-to-read Markdown table. Define appropriate, descriptive headers and align columns logically. Return EXCLUSIVELY the raw Markdown table itself. Do NOT include any introductory sentences, explanations, transitions, or concluding remarks before or after the table. Output ONLY the table, absolutely nothing else.',

            'compose' => 'You are a highly skilled co-writer, author, and creative writing assistant. Your task is to compose new text, generate, expand, or continue text based on the user\'s custom instructions or prompt, using any provided text as context. Be creative, flexible, and fully execute the user\'s requests without unnecessary constraints.',

            default => 'You are an assistant for text improvement and stylistic adaptation. Correct spelling, grammar, and if a style or tone is requested, rewrite the text to strictly adapt it to those requirements. Return ONLY the improved text, without explanations or additional comments.'.$batchInstruction,
        };

        if ($type === 'compose') {
            $prompt = $basePrompt."\n\nMANDATORY INSTRUCTIONS FOR THIS ASSIGNMENT:\n";
            $prompt .= "- OUTPUT FORMAT: Return ONLY the composed/completed text. Do NOT include any introductory remarks, meta-commentary, conversational filler, or explanations (e.g. do NOT write 'Hier ist dein Text:' or 'Sure, here is...'). Start generating the content directly.\n";
            $prompt .= "- FORMATTING: You are encouraged to use natural formatting (such as paragraphs, newlines, lists, or code blocks) if appropriate for the composed text.\n";
            $prompt .= "- CODE / FLOWCHART FORMATTING: If the requested or generated output contains programming scripts (like JavaScript, Python, Bash, etc.), HTML, or SVG, you MUST wrap the entire block in a standard Markdown fenced code block with the appropriate language specifier. CRITICAL: Do NOT attempt to generate any Mermaid.js diagrams directly in your response. You MUST use the `create_mermaid_chart` tool to generate them.\n";
            $prompt .= "- TOOLS: You have access to the following tools to assist you. To call a tool, output the EXACT XML structure shown below (including the outer `<tool_call>` tag and its attributes) and do NOT write any text after the tag; wait for the tool response.\n\n";
            $prompt .= "  1. `create_mermaid_chart`: Use this to generate high-quality, 100% syntactically correct Mermaid.js diagrams. ONLY call this tool if the user EXPLICITLY asks for a diagram, flowchart, mindmap, timeline, or visual representation. Do NOT generate a diagram spontaneously if it was not explicitly requested:\n";
            $prompt .= "  <tool_call name=\"create_mermaid_chart\">\n";
            $prompt .= "  {\n";
            $prompt .= "    \"type\": \"flowchart\", // or \"gitGraph\", \"sequenceDiagram\", \"classDiagram\", \"erDiagram\", \"gantt\", \"pie\", \"stateDiagram-v2\", \"mindmap\", \"timeline\"\n";
            $prompt .= "    \"description\": \"A very detailed description of the flowchart nodes, arrows, text, and structure you want to generate.\"\n";
            $prompt .= "  }\n";
            $prompt .= "  </tool_call>\n";
            $prompt .= "  CRITICAL: You MUST use 'gitGraph' for Git branching flows, commit histories, and repository workflows. Do NOT use 'flowchart' for Git workflows. Once you receive the tool response (wrapped in <tool_response>), you MUST present the generated Mermaid block (wrapped in ```mermaid ... ``` code fences) to the user.\n\n";
            if ($webSearchEnabled !== false) {
                $prompt .= "  2. `web_search`: Use this to search the web, extract webpage contents, research topics, or look up information. Whenever the user asks you a question that requires up-to-date information, local knowledge, web-based facts, or asks you to analyze or use content from a specific website URL, you MUST call this tool:\n";
                $prompt .= "  <tool_call name=\"web_search\">\n";
                $prompt .= "  {\n";
                $prompt .= "    \"query\": \"Specific search keywords or URL to extract/analyze\"\n";
                $prompt .= "  }\n";
                $prompt .= "  </tool_call>\n";
                $prompt .= "  Once you receive the tool response (wrapped in <tool_response>), you MUST use the search results to inform and compose your response.\n";
            }
        } else {
            $prompt = $basePrompt."\n\nMANDATORY INSTRUCTIONS FOR THIS ASSIGNMENT:\n";
            $prompt .= "- PRESERVE HTML: If the input contains HTML tags, preserve the tag structure and characters EXACTLY. ONLY improve the text content inside the tags.\n";
            $prompt .= "- NO EXTRA CONTENT: Do NOT add new line breaks ``\n``, indentation, or escape characters like ``\"`` or ``\`` to the HTML code. Use the exact same formatting as the input.\n";
            $prompt .= "- PRESERVE WHITESPACE: Do NOT trim leading or trailing whitespace or newlines from the segments. Return each string with its original trailing/leading formatting intact.\n";
        }

        if ($type === 'compose') {
            $prompt .= "- The user input contains your instruction/prompt and optional context text.\n";
        } elseif ($isBatch) {
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

        if ($type === 'table') {
            $prompt .= "- NATIVE MARKDOWN TABLE ONLY: Antworte AUSSCHLIESSLICH mit der Markdown-Tabelle. Beginne die Ausgabe direkt mit der ersten Zeile der Tabelle und beende sie sofort nach der letzten Zeile. Jeder weitere Text ist strengstens verboten. Nur Tabelle sonst nichts.\n";
        }

        if ($targetLang) {
            $language = $langMap[strtolower($targetLang)] ?? $targetLang;
            $isTranslation = ($sourceLang && strtolower($sourceLang) !== strtolower($targetLang));

            if (in_array($type, ['alternatives', 'synonyms', 'correction', 'proofread', 'rephrase', 'key_points', 'paraphrase', 'shorten', 'expand', 'list', 'table', 'compose']) || ! $isTranslation) {
                $prompt .= "- The text is in {$language}. Do NOT create a translation, but process the text exclusively in {$language}.\n";
            } else {
                $prompt .= "- Ensure that the result is in {$language}.\n";
            }
        }

        if ($style && $type !== 'compose') {
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
            'proofread' => 0.2,
            'table' => 0.3,
            'key_points', 'shorten' => 0.4,
            'list' => 0.5,
            'rephrase', 'alternatives' => 0.6,
            'expand' => 0.7,
            'paraphrase', 'compose' => 0.8,
            'synonyms' => 0.9,
            'correction' => 0.2,
            default => ($style || $tone) ? 0.7 : 0.3,
        };
    }
}
