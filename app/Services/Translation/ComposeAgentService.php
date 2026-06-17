<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Services\AI\AiService;
use App\Services\AI\Value\TokenUsage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ComposeAgentService
{
    public function __construct(
        private AiService $aiService,
        private SearchAgentService $searchAgentService
    ) {}

    /**
     * Run the agentic loop to compose text, executing any tool calls as needed.
     *
     * @return array{text: string, usage: TokenUsage|null}
     */
    public function compose(
        string $userPrompt,
        string $systemPrompt,
        string $modelId,
        float $temperature,
        bool $webSearchEnabled = true,
        bool $proactive = true
    ): array {
        $aggregatedPromptTokens = 0;
        $aggregatedCompletionTokens = 0;
        $aggregatedCacheReadInputTokens = 0;
        $aggregatedCacheCreationInputTokens = 0;
        $aggregatedReasoningTokens = 0;
        $aggregatedAudioInputTokens = 0;
        $aggregatedAudioOutputTokens = 0;
        $aggregatedServerToolUse = [];

        if ($webSearchEnabled && $proactive) {
            $searchQuery = $userPrompt;
            if (str_starts_with($userPrompt, "INSTRUCTION / PROMPT:\n")) {
                $parts = explode("\n\n", $userPrompt);
                $searchQuery = trim(str_replace("INSTRUCTION / PROMPT:\n", '', $parts[0] ?? $userPrompt));
            }

            Log::info('[ComposeAgent] Proactively running web search direct call', ['query' => $searchQuery]);

            $searchResult = $this->searchAgentService->executeDirectSearch($searchQuery);

            Log::info('[ComposeAgent] Proactive web search completed', ['result_length' => strlen($searchResult)]);

            $userPrompt = "SEARCH RESULTS / CONTEXT:\n".$searchResult."\n\nUSER PROMPT / INSTRUCTION:\n".$userPrompt."\n\nCRITICAL REQUIREMENT: You MUST list all sources (URLs, titles) from the SEARCH RESULTS that you used to answer the prompt. List them at the very end of your response, formatted strictly in APA style.";
        }

        $messages = [
            ['role' => 'system', 'content' => ['text' => $systemPrompt]],
            ['role' => 'user', 'content' => ['text' => $userPrompt]],
        ];

        $iterations = 0;
        $maxIterations = 5;
        $responseText = '';

        while ($iterations < $maxIterations) {
            $payload = [
                'model' => $modelId,
                'messages' => $messages,
                'temperature' => $temperature,
                'max_tokens' => 4000,
                'stream' => false,
            ];

            try {
                Log::debug('[ComposeAgent] Sending request to model', [
                    'model' => $modelId,
                    'iteration' => $iterations,
                    'message_count' => count($messages),
                ]);

                $response = $this->aiService->sendRequest($payload);
                $responseText = $response->content['text'] ?? '';

                if ($response->usage) {
                    $aggregatedPromptTokens += $response->usage->promptTokens;
                    $aggregatedCompletionTokens += $response->usage->completionTokens;
                    $aggregatedCacheReadInputTokens += $response->usage->cacheReadInputTokens;
                    $aggregatedCacheCreationInputTokens += $response->usage->cacheCreationInputTokens;
                    $aggregatedReasoningTokens += $response->usage->reasoningTokens;
                    $aggregatedAudioInputTokens += $response->usage->audioInputTokens;
                    $aggregatedAudioOutputTokens += $response->usage->audioOutputTokens;
                    if ($response->usage->serverToolUse) {
                        foreach ($response->usage->serverToolUse as $tool => $count) {
                            $aggregatedServerToolUse[$tool] = ($aggregatedServerToolUse[$tool] ?? 0) + $count;
                        }
                    }
                }

                // Check for tool calls (matching the innermost tool_call tag to handle nested wrappers robustly)
                if (preg_match('/<tool_call(?:\s+name="([^"]+)")?\s*>((?:(?!<tool_call).)*?)<\/tool_call>/is', $responseText, $matches)) {
                    $toolNameAttr = trim($matches[1] ?? '');
                    $innerContent = trim($matches[2]);

                    $toolName = '';
                    $args = [];

                    if (! empty($toolNameAttr)) {
                        $toolName = $toolNameAttr;
                        $args = json_decode($innerContent, true) ?? [];
                    }

                    // Fallback parser if args is empty or toolName is not set
                    if (empty($toolName) || empty($args)) {
                        $innerContentTrimmed = trim($innerContent);
                        if (str_starts_with($innerContentTrimmed, '{')) {
                            $json = json_decode($innerContentTrimmed, true);
                            if (is_array($json)) {
                                if (empty($toolName)) {
                                    $toolName = $json['name'] ?? $json['tool'] ?? '';
                                }
                                $args = $json['arguments'] ?? $json;
                            }
                        } else {
                            // The string starts with something else, likely the tool name, followed by JSON or newline.
                            // Attempt to parse tool name and JSON block using regex.
                            if (preg_match('/^([a-zA-Z0-9_]+)\s*(\{.*)/is', $innerContentTrimmed, $m)) {
                                $extractedToolName = trim($m[1]);
                                $jsonRest = trim($m[2]);
                            } else {
                                $lines = explode("\n", $innerContentTrimmed);
                                $extractedToolName = trim(array_shift($lines));
                                $extractedToolName = trim(preg_replace('/[^a-zA-Z0-9_]/', '', $extractedToolName));
                                $jsonRest = trim(implode("\n", $lines));
                            }

                            if (empty($toolName)) {
                                $toolName = $extractedToolName;
                            }

                            $args = json_decode($jsonRest, true) ?? [];
                        }
                    }

                    // Normalize/clean tool name to be alphanumeric
                    $toolName = trim(preg_replace('/[^a-zA-Z0-9_]/', '', $toolName));

                    Log::info('[ComposeAgent] Found tool call', [
                        'tool' => $toolName,
                        'arguments' => $args,
                    ]);

                    if ($toolName === 'create_mermaid_chart') {
                        $type = $args['type'] ?? 'flowchart';

                        // Normalize type to prevent wrong defaults or casing issues
                        $typeLower = strtolower(trim($type));
                        if (str_contains($typeLower, 'git')) {
                            $type = 'gitGraph';
                        }

                        $description = $args['description'] ?? '';

                        // Execute the tool
                        $toolResult = $this->executeCreateMermaidChart(
                            type: $type,
                            description: $description,
                            modelId: $modelId,
                            aggregatedPromptTokens: $aggregatedPromptTokens,
                            aggregatedCompletionTokens: $aggregatedCompletionTokens,
                            aggregatedCacheReadInputTokens: $aggregatedCacheReadInputTokens,
                            aggregatedCacheCreationInputTokens: $aggregatedCacheCreationInputTokens,
                            aggregatedReasoningTokens: $aggregatedReasoningTokens,
                            aggregatedAudioInputTokens: $aggregatedAudioInputTokens,
                            aggregatedAudioOutputTokens: $aggregatedAudioOutputTokens,
                            aggregatedServerToolUse: $aggregatedServerToolUse
                        );

                        // Append assistant's call and the tool's result to conversation history
                        $messages[] = ['role' => 'assistant', 'content' => ['text' => $responseText]];
                        $messages[] = ['role' => 'user', 'content' => ['text' => "<tool_response name=\"create_mermaid_chart\">\n".$toolResult."\n</tool_response>"]];

                        $iterations++;

                        continue;
                    }

                    if ($toolName === 'web_search') {
                        if (! $webSearchEnabled) {
                            $messages[] = ['role' => 'assistant', 'content' => ['text' => $responseText]];
                            $messages[] = ['role' => 'user', 'content' => ['text' => "<tool_response name=\"web_search\">\nWeb search is currently disabled by the user.\n</tool_response>"]];
                            $iterations++;

                            continue;
                        }

                        $query = $args['query'] ?? '';
                        $toolResult = $this->searchAgentService->search(
                            query: $query,
                            modelId: $modelId,
                            aggregatedPromptTokens: $aggregatedPromptTokens,
                            aggregatedCompletionTokens: $aggregatedCompletionTokens,
                            aggregatedCacheReadInputTokens: $aggregatedCacheReadInputTokens,
                            aggregatedCacheCreationInputTokens: $aggregatedCacheCreationInputTokens,
                            aggregatedReasoningTokens: $aggregatedReasoningTokens,
                            aggregatedAudioInputTokens: $aggregatedAudioInputTokens,
                            aggregatedAudioOutputTokens: $aggregatedAudioOutputTokens,
                            aggregatedServerToolUse: $aggregatedServerToolUse
                        );

                        // Append assistant's call and the tool's result to conversation history
                        $messages[] = ['role' => 'assistant', 'content' => ['text' => $responseText]];
                        $messages[] = ['role' => 'user', 'content' => ['text' => "<tool_response name=\"web_search\">\n".$toolResult."\n</tool_response>"]];

                        $iterations++;

                        continue;
                    }
                }

                // If no tool call is matched, this might be the final response.
                // Validate any Mermaid code generated directly in the response.
                if (preg_match_all('/```mermaid\s+(.*?)\s*```/is', $responseText, $mermaidMatches, PREG_SET_ORDER)) {
                    $hasInvalidMermaid = false;
                    foreach ($mermaidMatches as $match) {
                        $rawCode = trim($match[1]);

                        // Parse diagram type from code
                        $type = 'flowchart';
                        $firstLine = strtok($rawCode, "\n");
                        if ($firstLine !== false) {
                            $firstWord = trim(strtok(trim($firstLine), " \t\r\n"));
                            $type = $firstWord;
                        }

                        $validation = $this->validateMermaidSyntax($rawCode, $type);
                        if (! $validation['valid']) {
                            $hasInvalidMermaid = true;
                            $errorMsg = $validation['error'];

                            Log::warning('[ComposeAgent] Final response Mermaid validation failed, retrying...', [
                                'error' => $errorMsg,
                                'code' => $rawCode,
                            ]);

                            file_put_contents(
                                storage_path('logs/validation_attempts.log'),
                                "--- DIRECT FINAL ATTEMPT FAILED ---\nError(s):\n{$errorMsg}\nCode:\n{$rawCode}\n\n",
                                FILE_APPEND
                            );

                            $messages[] = ['role' => 'assistant', 'content' => ['text' => $responseText]];

                            $feedbackMsg = "SYNTAX ERROR: You generated a Mermaid diagram directly in your response, but it contains syntax errors:\n{$errorMsg}\n\n".
                                "CRITICAL REMINDERS:\n".
                                "- You MUST call the `create_mermaid_chart` tool to generate Mermaid diagrams instead of writing them directly. Do NOT write them directly in your response.\n".
                                "- Tags on separate lines (e.g. `tag \"v1.0.0\"`) or with trailing colons (e.g. `tag: \"v1.0.0\"`) are invalid. They must be inline attributes.\n".
                                "- Every branch created must be checked out immediately (e.g. `branch develop` -> `checkout develop`).\n".
                                "- Do not perform redundant merges.\n\n".
                                'Please call the `create_mermaid_chart` tool to regenerate the correct diagram, or fix the syntax error directly.';

                            $messages[] = ['role' => 'user', 'content' => ['text' => $feedbackMsg]];
                            break; // Break the matches loop, retry the agent loop
                        }
                    }

                    if ($hasInvalidMermaid) {
                        $iterations++;

                        continue;
                    }
                }

                break;

            } catch (\Exception $e) {
                Log::error('[ComposeAgent] Iteration failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                break;
            }
        }

        // Resolve model instance for TokenUsage
        $model = $this->aiService->getModel($modelId);
        $finalUsage = $model ? new TokenUsage(
            model: $model,
            promptTokens: $aggregatedPromptTokens,
            completionTokens: $aggregatedCompletionTokens,
            cacheReadInputTokens: $aggregatedCacheReadInputTokens,
            cacheCreationInputTokens: $aggregatedCacheCreationInputTokens,
            reasoningTokens: $aggregatedReasoningTokens,
            audioInputTokens: $aggregatedAudioInputTokens,
            audioOutputTokens: $aggregatedAudioOutputTokens,
            serverToolUse: empty($aggregatedServerToolUse) ? null : $aggregatedServerToolUse
        ) : null;

        return [
            'text' => $responseText,
            'usage' => $finalUsage,
        ];
    }

    /**
     * Specialized execution for generating a Mermaid diagram.
     */
    private function executeCreateMermaidChart(
        string $type,
        string $description,
        string $modelId,
        int &$aggregatedPromptTokens,
        int &$aggregatedCompletionTokens,
        int &$aggregatedCacheReadInputTokens,
        int &$aggregatedCacheCreationInputTokens,
        int &$aggregatedReasoningTokens,
        int &$aggregatedAudioInputTokens,
        int &$aggregatedAudioOutputTokens,
        array &$aggregatedServerToolUse
    ): string {
        $templatePath = resource_path('context/mermaid_templates.txt');
        $templateContent = '';

        if (file_exists($templatePath)) {
            $fullContent = file_get_contents($templatePath) ?: '';
            $templateContent = $this->extractTemplateForType($fullContent, $type);
        } else {
            Log::warning('[ComposeAgent] Mermaid templates reference file not found', [
                'path' => $templatePath,
            ]);
        }

        $systemPrompt = "You are a specialized Mermaid.js Diagram generator. Your task is to write 100% syntactically correct and functioning Mermaid.js code for a diagram of type '{$type}' based on the user's description.

CRITICAL REQUIREMENT:
You MUST read the following template rules and syntax guides from our context reference file, and follow them EXACTLY to avoid syntax errors:
--- START OF REFERENCE CONTEXT ---
{$templateContent}
--- END OF REFERENCE CONTEXT ---

CRITICAL GITGRAPH RULES:
If type is 'gitGraph', follow these strictly:
- ALWAYS checkout a branch immediately after creating it (e.g. write `branch develop` and then immediately `checkout develop`).
- Every branch MUST have at least one commit made on it while it is checked out, before it is merged back into any other branch.
- NEVER merge a branch if there are no new commits on it. A merge should only occur if the branch being merged has new commits that are not already present in the active branch.
- Do NOT merge a parent branch (like 'main') into a child branch (like 'develop') right after creating the child branch.
- NEVER write 'tag' on a separate line. Tags must ONLY be inline attributes on a commit or merge command (e.g., `commit tag: \"v1.0.0\"` or `merge release/v1.0.0 tag: \"v1.0.0\"`).

Output ONLY the raw Mermaid.js code. Do NOT wrap it in markdown code blocks (no ```mermaid), do NOT include any introductory or concluding text, explanations, or markdown fences. Just the raw text starting with the diagram type (e.g. 'flowchart TD' or 'gitGraph').";

        $messages = [
            ['role' => 'system', 'content' => ['text' => $systemPrompt]],
            ['role' => 'user', 'content' => ['text' => "Generate a '{$type}' diagram for the following description:\n".$description]],
        ];

        $attempts = 0;
        $maxAttempts = 3;
        $mermaidCode = '';

        while ($attempts < $maxAttempts) {
            $payload = [
                'model' => $modelId,
                'messages' => $messages,
                'temperature' => 0.2, // Low temperature for precise code generation
                'max_tokens' => 2000,
                'stream' => false,
            ];

            try {
                Log::debug('[ComposeAgent] Requesting specialized Mermaid generation', [
                    'type' => $type,
                    'model' => $modelId,
                    'attempt' => $attempts + 1,
                ]);

                $response = $this->aiService->sendRequest($payload);
                $mermaidCode = trim($response->content['text'] ?? '');

                if ($response->usage) {
                    $aggregatedPromptTokens += $response->usage->promptTokens;
                    $aggregatedCompletionTokens += $response->usage->completionTokens;
                    $aggregatedCacheReadInputTokens += $response->usage->cacheReadInputTokens;
                    $aggregatedCacheCreationInputTokens += $response->usage->cacheCreationInputTokens;
                    $aggregatedReasoningTokens += $response->usage->reasoningTokens;
                    $aggregatedAudioInputTokens += $response->usage->audioInputTokens;
                    $aggregatedAudioOutputTokens += $response->usage->audioOutputTokens;
                    if ($response->usage->serverToolUse) {
                        foreach ($response->usage->serverToolUse as $tool => $count) {
                            $aggregatedServerToolUse[$tool] = ($aggregatedServerToolUse[$tool] ?? 0) + $count;
                        }
                    }
                }

                // Clean up any accidental markdown fences generated by the model
                $mermaidCode = preg_replace('/^```(?:mermaid)?\s+/i', '', $mermaidCode);
                $mermaidCode = preg_replace('/\s*```$/', '', $mermaidCode);
                $mermaidCode = trim($mermaidCode);

                // Run syntax validation
                $validation = $this->validateMermaidSyntax($mermaidCode, $type);
                if ($validation['valid']) {
                    Log::info('[ComposeAgent] Mermaid code successfully validated', [
                        'type' => $type,
                        'attempt' => $attempts + 1,
                    ]);

                    return "```mermaid\n".$mermaidCode."\n```";
                }

                // If invalid, add syntax feedback and retry
                $errorMsg = $validation['error'];
                Log::warning('[ComposeAgent] Mermaid validation failed, retrying...', [
                    'type' => $type,
                    'attempt' => $attempts + 1,
                    'error' => $errorMsg,
                    'code' => $mermaidCode,
                ]);

                // Append to local validation attempts log file for easier manual inspection
                file_put_contents(
                    storage_path('logs/validation_attempts.log'),
                    '--- ATTEMPT '.($attempts + 1)." FAILED (type: '{$type}') ---\nError(s):\n{$errorMsg}\nCode:\n{$mermaidCode}\n\n",
                    FILE_APPEND
                );

                // Append assistant's invalid output and user feedback to messages
                $messages[] = ['role' => 'assistant', 'content' => ['text' => $mermaidCode]];

                $feedbackMsg = "SYNTAX ERROR: Your generated Mermaid code contains syntax errors:\n{$errorMsg}\n\n".
                    "CRITICAL GITGRAPH RULES REMINDER:\n".
                    "- ALWAYS checkout a branch immediately after creating it (e.g. write `branch develop` and then immediately `checkout develop`).\n".
                    "- Do NOT commit on a parent branch expecting it to be on the child branch.\n".
                    "- Standalone `tag` or `tag:` commands are invalid and cause rendering errors. Tags must ONLY be inline attributes on a commit or merge command (e.g., `commit tag: \"v1.0.0\"` or `merge release/v1.0.0 tag: \"v1.0.0\"`).\n\n".
                    "Please analyze the errors carefully and regenerate the entire diagram fixing all of them. Output ONLY the raw Mermaid code starting with the type declaration (e.g. 'gitGraph' or 'flowchart TD').";
                $messages[] = ['role' => 'user', 'content' => ['text' => $feedbackMsg]];

                $attempts++;

            } catch (\Exception $e) {
                Log::error('[ComposeAgent] Specialized Mermaid generation failed', [
                    'error' => $e->getMessage(),
                ]);

                return 'Failed to generate Mermaid diagram: '.$e->getMessage();
            }
        }

        // If we exhausted all attempts, return whatever we had (but log it)
        Log::error('[ComposeAgent] Failed to generate valid Mermaid code after max attempts', [
            'type' => $type,
            'maxAttempts' => $maxAttempts,
        ]);

        return "```mermaid\n".$mermaidCode."\n```";
    }

    /**
     * Validate Mermaid.js syntax for specific diagram types.
     *
     * @return array{valid: bool, error: ?string}
     */
    /**
     * Public method to validate Mermaid.js syntax for testing or external use.
     *
     * @return array{valid: bool, error: ?string}
     */
    public function validateSyntax(string $code, string $type): array
    {
        return $this->validateMermaidSyntax($code, $type);
    }

    private function validateMermaidSyntax(string $code, string $type): array
    {
        $scriptPath = resource_path('js/validate_mermaid.mjs');
        if (! file_exists($scriptPath)) {
            Log::warning('[ComposeAgent] validate_mermaid.mjs not found, skipping validation.');

            return ['valid' => true, 'error' => null];
        }

        $result = Process::input($code)->run('node '.escapeshellarg($scriptPath));

        $output = trim($result->output());
        $json = json_decode($output, true);

        if (is_array($json) && isset($json['valid'])) {
            return [
                'valid' => $json['valid'],
                'error' => $json['error'] ?? null,
            ];
        }

        Log::error('[ComposeAgent] Node script validation failed', [
            'output' => $output,
            'errorOutput' => $result->errorOutput(),
        ]);

        return [
            'valid' => false,
            'error' => 'Syntax validation script failed. Output: '.$output,
        ];
    }

    /**
     * Extract only the relevant section from the templates context file.
     */
    private function extractTemplateForType(string $content, string $type): string
    {
        $map = [
            'flowchart' => '## 1. FLOWCHART',
            'sequencediagram' => '## 2. SEQUENCE DIAGRAM',
            'gitgraph' => '## 3. GIT GRAPH',
            'classdiagram' => '## 4. CLASS DIAGRAM',
            'erdiagram' => '## 5. ENTITY RELATIONSHIP DIAGRAM',
            'gantt' => '## 6. GANTT CHART',
            'pie' => '## 7. PIE CHART',
            'statediagram-v2' => '## 8. STATE DIAGRAM',
            'mindmap' => '## 9. MINDMAP',
            'timeline' => '## 10. TIMELINE',
        ];

        $sectionHeader = $map[strtolower($type)] ?? null;
        if (! $sectionHeader) {
            // Fallback search
            foreach ($map as $key => $header) {
                if (str_contains(strtolower($key), strtolower($type)) || str_contains(strtolower($type), strtolower($key))) {
                    $sectionHeader = $header;
                    break;
                }
            }
        }

        if (! $sectionHeader) {
            return $content; // Fallback to full content
        }

        $pos = strpos($content, $sectionHeader);
        if ($pos === false) {
            return $content;
        }

        $sectionContent = substr($content, $pos);
        $nextSectionPos = strpos($sectionContent, '## ', strlen($sectionHeader));
        if ($nextSectionPos !== false) {
            $sectionContent = substr($sectionContent, 0, $nextSectionPos);
        }

        if (str_contains(strtolower($type), 'flowchart')) {
            $flowchartContextPath = resource_path('context/flowchart.md');
            if (file_exists($flowchartContextPath)) {
                $sectionContent .= "\n\n--- DETAILED FLOWCHART SYNTAX DOCUMENTATION ---\n";
                $sectionContent .= file_get_contents($flowchartContextPath);
            }
        }

        return trim($sectionContent);
    }
}
