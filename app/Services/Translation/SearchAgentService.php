<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Services\AI\AiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SearchAgentService
{
    public function __construct(
        private AiService $aiService
    ) {}

    /**
     * Run the sub-agentic search and retrieval loop, executing queries or content extraction
     * using the MCP server, and returning the synthesized response.
     */
    public function search(
        string $query,
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
        $systemPrompt = "You are a specialized Web Search and Information Retrieval Agent. Your task is to find high-quality, up-to-date information on the web or from university resources to answer the user's query.

You have access to the following tools:

1. `google_search`: Use this for general web search queries.
<tool_call name=\"google_search\">
{
  \"query\": \"search keywords\"
}
</tool_call>

2. `extract_webpage_content`: Use this to extract text and content from a specific URL.
<tool_call name=\"extract_webpage_content\">
{
  \"url\": \"https://example.com/page\"
}
</tool_call>

3. `extract_multiple_webpages`: Use this when you need to extract content from multiple URLs at once.
<tool_call name=\"extract_multiple_webpages\">
{
  \"urls\": [\"https://example.com/1\", \"https://example.com/2\"]
}
</tool_call>

4. `research_topic`: Use this for deep, comprehensive research on a broader topic.
<tool_call name=\"research_topic\">
{
  \"topic\": \"topic to research\"
}
</tool_call>

5. `search_uni_giessen`: Use this specifically for searches related to the Justus Liebig University Giessen (JLU).
<tool_call name=\"search_uni_giessen\">
{
  \"query\": \"JLU search query\"
}
</tool_call>

Once you receive the tool response (wrapped in <tool_response>), you can either make another tool call if you need more information, or formulate your final answer.
CRITICAL: Do NOT output any text after a `<tool_call>` tag. Stop immediately and wait for the tool response.
Output your final answer directly when you have gathered enough information.";

        $messages = [
            ['role' => 'system', 'content' => ['text' => $systemPrompt]],
            ['role' => 'user', 'content' => ['text' => $query]],
        ];

        $iterations = 0;
        $maxIterations = 3;
        $responseText = '';

        while ($iterations < $maxIterations) {
            $payload = [
                'model' => $modelId,
                'messages' => $messages,
                'temperature' => 0.2,
                'max_tokens' => 2000,
                'stream' => false,
            ];

            try {
                Log::debug('[SearchSubAgent] Sending request to model', [
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

                // Check for tool calls
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
                            $lines = explode("\n", $innerContentTrimmed);
                            $extractedToolName = trim(array_shift($lines));
                            $extractedToolName = trim(preg_replace('/[^a-zA-Z0-9_]/', '', $extractedToolName));

                            if (empty($toolName)) {
                                $toolName = $extractedToolName;
                            }

                            $jsonRest = trim(implode("\n", $lines));
                            $args = json_decode($jsonRest, true) ?? [];
                        }
                    }

                    // Normalize/clean tool name to be alphanumeric
                    $toolName = trim(preg_replace('/[^a-zA-Z0-9_]/', '', $toolName));

                    Log::info('[SearchSubAgent] Found tool call', [
                        'tool' => $toolName,
                        'arguments' => $args,
                    ]);

                    $toolResult = null;
                    if ($toolName === 'google_search' || $toolName === 'web_search') {
                        $searchQuery = $args['query'] ?? '';
                        $toolResult = $this->executeMcpCall('google_search', ['query' => $searchQuery]);
                    } elseif ($toolName === 'extract_webpage_content') {
                        $url = $args['url'] ?? '';
                        $toolResult = $this->executeMcpCall('extract_webpage_content', ['url' => $url]);
                    } elseif ($toolName === 'extract_multiple_webpages') {
                        $urls = $args['urls'] ?? [];
                        $toolResult = $this->executeMcpCall('extract_multiple_webpages', ['urls' => $urls]);
                    } elseif ($toolName === 'research_topic') {
                        $topic = $args['topic'] ?? '';
                        $toolResult = $this->executeMcpCall('research_topic', ['topic' => $topic]);
                    } elseif ($toolName === 'search_uni_giessen') {
                        $searchQuery = $args['query'] ?? '';
                        $toolResult = $this->executeMcpCall('search_uni_giessen', ['query' => $searchQuery]);
                    }

                    if ($toolResult !== null) {
                        // Append assistant's call and the tool's result to conversation history
                        $messages[] = ['role' => 'assistant', 'content' => ['text' => $responseText]];
                        $messages[] = ['role' => 'user', 'content' => ['text' => "<tool_response name=\"{$toolName}\">\n".$toolResult."\n</tool_response>"]];

                        $iterations++;

                        continue;
                    }
                }

                break;
            } catch (\Exception $e) {
                Log::error('[SearchSubAgent] Iteration failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                break;
            }
        }

        return $responseText;
    }

    /**
     * Perform a direct HTTP call to the MCP server for a search query or URL extraction.
     */
    public function executeDirectSearch(string $query): string
    {
        $trimmed = trim($query);

        // 1. Detect if the query is a single URL
        if (preg_match('/^(?:https?:\/\/|www\.)[^\s]+$/i', $trimmed)) {
            $url = $trimmed;
            if (str_starts_with(strtolower($url), 'www.')) {
                $url = 'https://'.$url;
            }
            Log::info('[SearchAgentService] Direct extraction for URL', ['url' => $url]);

            return $this->executeMcpCall('extract_webpage_content', ['url' => $url]);
        }

        // 2. Detect if the query is JLU related
        $lowerQuery = mb_strtolower($trimmed);
        if (str_contains($lowerQuery, 'jlu') || str_contains($lowerQuery, 'gießen') || str_contains($lowerQuery, 'giessen') || str_contains($lowerQuery, 'justus-liebig')) {
            Log::info('[SearchAgentService] Direct JLU search', ['query' => $trimmed]);

            return $this->executeMcpCall('search_uni_giessen', ['query' => $trimmed]);
        }

        // 3. General web search
        Log::info('[SearchAgentService] Direct Google search', ['query' => $trimmed]);

        return $this->executeMcpCall('google_search', ['query' => $trimmed]);
    }

    /**
     * Executes a live HTTP call to a tool on the MCP server.
     */
    private function executeMcpCall(string $name, array $arguments): string
    {
        Log::info('[SearchSubAgent] Executing MCP tool', ['name' => $name, 'arguments' => $arguments]);

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json, text/event-stream',
            ])->post('https://ki-dev2.hrz.uni-giessen.de/mcp', [
                'jsonrpc' => '2.0',
                'method' => 'tools/call',
                'params' => [
                    'name' => $name,
                    'arguments' => $arguments,
                ],
                'id' => 1,
            ]);

            if (! $response->successful()) {
                throw new \Exception('MCP server returned status code '.$response->status());
            }

            $body = $response->body();
            $lines = explode("\n", $body);
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'data: ')) {
                    $dataText = substr($trimmed, 6);
                    $json = json_decode($dataText, true);
                    if ($json && isset($json['result']['content'][0]['text'])) {
                        return $json['result']['content'][0]['text'];
                    }
                    if ($json && isset($json['error'])) {
                        throw new \Exception($json['error']['message'] ?? 'Unknown JSON-RPC error');
                    }
                }
            }

            return 'No results found or invalid response format.';
        } catch (\Exception $e) {
            Log::error('[SearchSubAgent] MCP call failed', ['name' => $name, 'error' => $e->getMessage()]);

            return 'Failed to execute '.$name.': '.$e->getMessage();
        }
    }
}
