<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\AiService;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiResponse;
use App\Services\AI\Value\TokenUsage;
use App\Services\Translation\ComposeAgentService;
use App\Services\Translation\SearchAgentService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ComposeAgentMcpSearchTest extends TestCase
{
    /**
     * Test a live HTTP request to the remote MCP server to use the google_search tool.
     */
    public function test_live_mcp_google_search(): void
    {
        $response = Http::withHeaders([
            'Accept' => 'application/json, text/event-stream',
        ])->post('https://ki-dev2.hrz.uni-giessen.de/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'google_search',
                'arguments' => [
                    'query' => 'Justus-Liebig-Universität Gießen',
                ],
            ],
            'id' => 1,
        ]);

        $this->assertEquals(200, $response->status());
        $this->assertStringContainsString('text/event-stream', $response->header('Content-Type'));

        $body = $response->body();
        $this->assertStringContainsString('event: message', $body);
        $this->assertStringContainsString('data: ', $body);
        $this->assertStringContainsString('Justus-Liebig-Universität Gießen', $body);
    }

    /**
     * Helper to create a mocked AiService.
     */
    private function createMockedAiService(array $consecutiveResponses): AiService
    {
        $aiService = $this->createMock(AiService::class);
        $model = $this->createMock(AiModel::class);
        $model->method('getId')->willReturn('test-model');
        $aiService->method('getModel')->willReturn($model);

        $aiService->expects($this->exactly(count($consecutiveResponses)))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(...$consecutiveResponses);

        return $aiService;
    }

    /**
     * 1. Test scenario for google_search tool.
     */
    public function test_scenario_google_search(): void
    {
        Http::fake([
            'https://ki-dev2.hrz.uni-giessen.de/mcp' => Http::response(
                "event: message\ndata: {\"result\":{\"content\":[{\"type\":\"text\",\"text\":\"Mocked search results: PHP 8.4 is out.\"}]},\"jsonrpc\":\"2.0\",\"id\":1}\n",
                200,
                ['Content-Type' => 'text/event-stream']
            ),
        ]);

        $model = $this->createMock(AiModel::class);
        $model->method('getId')->willReturn('test-model');

        $subResponse1 = new AiResponse(
            content: ['text' => 'Let me search: <tool_call name="google_search">{"query":"php 8.4 release date"}</tool_call>'],
            usage: new TokenUsage(model: $model, promptTokens: 10, completionTokens: 15)
        );
        $subResponse2 = new AiResponse(
            content: ['text' => 'PHP 8.4 was released on Nov 21, 2024.'],
            usage: new TokenUsage(model: $model, promptTokens: 25, completionTokens: 10)
        );

        $aiService = $this->createMockedAiService([$subResponse1, $subResponse2]);
        $searchAgent = new SearchAgentService($aiService);

        $promptTokens = 0;
        $completionTokens = 0;
        $cacheReadTokens = 0;
        $cacheCreationTokens = 0;
        $reasoningTokens = 0;
        $audioInputTokens = 0;
        $audioOutputTokens = 0;
        $serverToolUse = [];

        $result = $searchAgent->search(
            query: 'search php 8.4',
            modelId: 'test-model',
            aggregatedPromptTokens: $promptTokens,
            aggregatedCompletionTokens: $completionTokens,
            aggregatedCacheReadInputTokens: $cacheReadTokens,
            aggregatedCacheCreationInputTokens: $cacheCreationTokens,
            aggregatedReasoningTokens: $reasoningTokens,
            aggregatedAudioInputTokens: $audioInputTokens,
            aggregatedAudioOutputTokens: $audioOutputTokens,
            aggregatedServerToolUse: $serverToolUse
        );

        $this->assertEquals('PHP 8.4 was released on Nov 21, 2024.', $result);
        $this->assertEquals(35, $promptTokens);
        $this->assertEquals(25, $completionTokens);
    }

    /**
     * 2. Test scenario for extract_webpage_content tool.
     */
    public function test_scenario_extract_webpage_content(): void
    {
        Http::fake([
            'https://ki-dev2.hrz.uni-giessen.de/mcp' => Http::response(
                "event: message\ndata: {\"result\":{\"content\":[{\"type\":\"text\",\"text\":\"Mocked extracted content: JLU is in Gießen.\"}]},\"jsonrpc\":\"2.0\",\"id\":1}\n",
                200,
                ['Content-Type' => 'text/event-stream']
            ),
        ]);

        $model = $this->createMock(AiModel::class);
        $model->method('getId')->willReturn('test-model');

        $subResponse1 = new AiResponse(
            content: ['text' => 'Extracting page content: <tool_call name="extract_webpage_content">{"url":"https://uni-giessen.de"}</tool_call>'],
            usage: new TokenUsage(model: $model, promptTokens: 10, completionTokens: 15)
        );
        $subResponse2 = new AiResponse(
            content: ['text' => 'The website says JLU is in Gießen.'],
            usage: new TokenUsage(model: $model, promptTokens: 25, completionTokens: 10)
        );

        $aiService = $this->createMockedAiService([$subResponse1, $subResponse2]);
        $searchAgent = new SearchAgentService($aiService);

        $promptTokens = 0;
        $completionTokens = 0;
        $cacheReadTokens = 0;
        $cacheCreationTokens = 0;
        $reasoningTokens = 0;
        $audioInputTokens = 0;
        $audioOutputTokens = 0;
        $serverToolUse = [];

        $result = $searchAgent->search(
            query: 'extract https://uni-giessen.de',
            modelId: 'test-model',
            aggregatedPromptTokens: $promptTokens,
            aggregatedCompletionTokens: $completionTokens,
            aggregatedCacheReadInputTokens: $cacheReadTokens,
            aggregatedCacheCreationInputTokens: $cacheCreationTokens,
            aggregatedReasoningTokens: $reasoningTokens,
            aggregatedAudioInputTokens: $audioInputTokens,
            aggregatedAudioOutputTokens: $audioOutputTokens,
            aggregatedServerToolUse: $serverToolUse
        );

        $this->assertEquals('The website says JLU is in Gießen.', $result);
    }

    /**
     * 3. Test scenario for extract_multiple_webpages tool.
     */
    public function test_scenario_extract_multiple_webpages(): void
    {
        Http::fake([
            'https://ki-dev2.hrz.uni-giessen.de/mcp' => Http::response(
                "event: message\ndata: {\"result\":{\"content\":[{\"type\":\"text\",\"text\":\"Mocked multiple pages content.\"}]},\"jsonrpc\":\"2.0\",\"id\":1}\n",
                200,
                ['Content-Type' => 'text/event-stream']
            ),
        ]);

        $model = $this->createMock(AiModel::class);
        $model->method('getId')->willReturn('test-model');

        $subResponse1 = new AiResponse(
            content: ['text' => 'Extracting multiple pages: <tool_call name="extract_multiple_webpages">{"urls":["https://page1.com","https://page2.com"]}</tool_call>'],
            usage: new TokenUsage(model: $model, promptTokens: 10, completionTokens: 15)
        );
        $subResponse2 = new AiResponse(
            content: ['text' => 'Combined information extracted successfully.'],
            usage: new TokenUsage(model: $model, promptTokens: 25, completionTokens: 10)
        );

        $aiService = $this->createMockedAiService([$subResponse1, $subResponse2]);
        $searchAgent = new SearchAgentService($aiService);

        $promptTokens = 0;
        $completionTokens = 0;
        $cacheReadTokens = 0;
        $cacheCreationTokens = 0;
        $reasoningTokens = 0;
        $audioInputTokens = 0;
        $audioOutputTokens = 0;
        $serverToolUse = [];

        $result = $searchAgent->search(
            query: 'extract page1 and page2',
            modelId: 'test-model',
            aggregatedPromptTokens: $promptTokens,
            aggregatedCompletionTokens: $completionTokens,
            aggregatedCacheReadInputTokens: $cacheReadTokens,
            aggregatedCacheCreationInputTokens: $cacheCreationTokens,
            aggregatedReasoningTokens: $reasoningTokens,
            aggregatedAudioInputTokens: $audioInputTokens,
            aggregatedAudioOutputTokens: $audioOutputTokens,
            aggregatedServerToolUse: $serverToolUse
        );

        $this->assertEquals('Combined information extracted successfully.', $result);
    }

    /**
     * 4. Test scenario for research_topic tool.
     */
    public function test_scenario_research_topic(): void
    {
        Http::fake([
            'https://ki-dev2.hrz.uni-giessen.de/mcp' => Http::response(
                "event: message\ndata: {\"result\":{\"content\":[{\"type\":\"text\",\"text\":\"Deep research topic details.\"}]},\"jsonrpc\":\"2.0\",\"id\":1}\n",
                200,
                ['Content-Type' => 'text/event-stream']
            ),
        ]);

        $model = $this->createMock(AiModel::class);
        $model->method('getId')->willReturn('test-model');

        $subResponse1 = new AiResponse(
            content: ['text' => 'Doing deep research: <tool_call name="research_topic">{"topic":"quantum computing"}</tool_call>'],
            usage: new TokenUsage(model: $model, promptTokens: 10, completionTokens: 15)
        );
        $subResponse2 = new AiResponse(
            content: ['text' => 'Quantum computing utilizes qubits.'],
            usage: new TokenUsage(model: $model, promptTokens: 25, completionTokens: 10)
        );

        $aiService = $this->createMockedAiService([$subResponse1, $subResponse2]);
        $searchAgent = new SearchAgentService($aiService);

        $promptTokens = 0;
        $completionTokens = 0;
        $cacheReadTokens = 0;
        $cacheCreationTokens = 0;
        $reasoningTokens = 0;
        $audioInputTokens = 0;
        $audioOutputTokens = 0;
        $serverToolUse = [];

        $result = $searchAgent->search(
            query: 'research quantum computing',
            modelId: 'test-model',
            aggregatedPromptTokens: $promptTokens,
            aggregatedCompletionTokens: $completionTokens,
            aggregatedCacheReadInputTokens: $cacheReadTokens,
            aggregatedCacheCreationInputTokens: $cacheCreationTokens,
            aggregatedReasoningTokens: $reasoningTokens,
            aggregatedAudioInputTokens: $audioInputTokens,
            aggregatedAudioOutputTokens: $audioOutputTokens,
            aggregatedServerToolUse: $serverToolUse
        );

        $this->assertEquals('Quantum computing utilizes qubits.', $result);
    }

    /**
     * 5. Test scenario for search_uni_giessen tool.
     */
    public function test_scenario_search_uni_giessen(): void
    {
        Http::fake([
            'https://ki-dev2.hrz.uni-giessen.de/mcp' => Http::response(
                "event: message\ndata: {\"result\":{\"content\":[{\"type\":\"text\",\"text\":\"JLU Giessen was founded in 1607.\"}]},\"jsonrpc\":\"2.0\",\"id\":1}\n",
                200,
                ['Content-Type' => 'text/event-stream']
            ),
        ]);

        $model = $this->createMock(AiModel::class);
        $model->method('getId')->willReturn('test-model');

        $subResponse1 = new AiResponse(
            content: ['text' => 'Searching JLU: <tool_call name="search_uni_giessen">{"query":"history of JLU"}</tool_call>'],
            usage: new TokenUsage(model: $model, promptTokens: 10, completionTokens: 15)
        );
        $subResponse2 = new AiResponse(
            content: ['text' => 'JLU was founded in 1607 by Landgrave Louis V.'],
            usage: new TokenUsage(model: $model, promptTokens: 25, completionTokens: 10)
        );

        $aiService = $this->createMockedAiService([$subResponse1, $subResponse2]);
        $searchAgent = new SearchAgentService($aiService);

        $promptTokens = 0;
        $completionTokens = 0;
        $cacheReadTokens = 0;
        $cacheCreationTokens = 0;
        $reasoningTokens = 0;
        $audioInputTokens = 0;
        $audioOutputTokens = 0;
        $serverToolUse = [];

        $result = $searchAgent->search(
            query: 'search uni giessen history',
            modelId: 'test-model',
            aggregatedPromptTokens: $promptTokens,
            aggregatedCompletionTokens: $completionTokens,
            aggregatedCacheReadInputTokens: $cacheReadTokens,
            aggregatedCacheCreationInputTokens: $cacheCreationTokens,
            aggregatedReasoningTokens: $reasoningTokens,
            aggregatedAudioInputTokens: $audioInputTokens,
            aggregatedAudioOutputTokens: $audioOutputTokens,
            aggregatedServerToolUse: $serverToolUse
        );

        $this->assertEquals('JLU was founded in 1607 by Landgrave Louis V.', $result);
    }

    /**
     * Test the full agent-subagent loop with the web_search tool, using Http::fake to mock the MCP server.
     */
    public function test_agent_web_search_flow(): void
    {
        // Mock the MCP server HTTP response
        Http::fake([
            'https://ki-dev2.hrz.uni-giessen.de/mcp' => Http::response(
                "event: message\ndata: {\"result\":{\"content\":[{\"type\":\"text\",\"text\":\"Mocked search result: Laravel MCP is a protocol package.\"}]},\"jsonrpc\":\"2.0\",\"id\":1}\n",
                200,
                ['Content-Type' => 'text/event-stream']
            ),
        ]);

        $aiService = $this->createMock(AiService::class);
        $model = $this->createMock(AiModel::class);
        $model->method('getId')->willReturn('test-model');
        $aiService->method('getModel')->willReturn($model);

        // Usage statistics
        $usage1 = new TokenUsage(model: $model, promptTokens: 10, completionTokens: 20);
        $subUsage1 = new TokenUsage(model: $model, promptTokens: 15, completionTokens: 25);
        $subUsage2 = new TokenUsage(model: $model, promptTokens: 35, completionTokens: 15);
        $usage2 = new TokenUsage(model: $model, promptTokens: 40, completionTokens: 30);

        // 1st LLM response (Main Agent): calls the tool
        $response1 = new AiResponse(
            content: ['text' => 'Let me search for that: <tool_call name="web_search">{"query":"laravel mcp"}</tool_call>'],
            usage: $usage1
        );

        // 2nd LLM response (Sub-Agent, call 1): calls google_search
        $subResponse1 = new AiResponse(
            content: ['text' => '<tool_call name="google_search">{"query":"laravel mcp"}</tool_call>'],
            usage: $subUsage1
        );

        // 3rd LLM response (Sub-Agent, call 2): returns synthesized results
        $subResponse2 = new AiResponse(
            content: ['text' => 'Synthesized info: Laravel MCP is a protocol package.'],
            usage: $subUsage2
        );

        // 4th LLM response (Main Agent, call 2): final answer
        $response2 = new AiResponse(
            content: ['text' => 'Based on the search, Laravel MCP is a protocol package.'],
            usage: $usage2
        );

        $aiService->expects($this->exactly(4))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls($response1, $subResponse1, $subResponse2, $response2);

        $searchAgent = new SearchAgentService($aiService);
        $service = new ComposeAgentService($aiService, $searchAgent);

        $result = $service->compose(
            userPrompt: 'What is Laravel MCP?',
            systemPrompt: 'You are a helpful assistant with tools.',
            modelId: 'test-model',
            temperature: 0.7
        );

        $this->assertEquals('Based on the search, Laravel MCP is a protocol package.', $result['text']);
        $this->assertNotNull($result['usage']);
        $this->assertEquals(100, $result['usage']->promptTokens); // 10 + 15 + 35 + 40
        $this->assertEquals(90, $result['usage']->completionTokens); // 20 + 25 + 15 + 30
    }

    /**
     * Test the agent loop when web search is disabled by the user.
     */
    public function test_agent_web_search_disabled_flow(): void
    {
        $aiService = $this->createMock(AiService::class);
        $model = $this->createMock(AiModel::class);
        $model->method('getId')->willReturn('test-model');
        $aiService->method('getModel')->willReturn($model);

        $usage1 = new TokenUsage(model: $model, promptTokens: 10, completionTokens: 20);
        $usage2 = new TokenUsage(model: $model, promptTokens: 40, completionTokens: 30);

        // 1st LLM response: main agent attempts to call web_search
        $response1 = new AiResponse(
            content: ['text' => 'Let me search for that: <tool_call name="web_search">{"query":"laravel mcp"}</tool_call>'],
            usage: $usage1
        );

        // 2nd LLM response: main agent handles the "disabled" tool response
        $response2 = new AiResponse(
            content: ['text' => 'I cannot search since web search is disabled.'],
            usage: $usage2
        );

        $aiService->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls($response1, $response2);

        $searchAgent = $this->createMock(SearchAgentService::class);
        // Ensure searchAgent is never called since web search is disabled
        $searchAgent->expects($this->never())->method('search');

        $service = new ComposeAgentService($aiService, $searchAgent);

        $result = $service->compose(
            userPrompt: 'What is Laravel MCP?',
            systemPrompt: 'You are a helpful assistant with tools.',
            modelId: 'test-model',
            temperature: 0.7,
            webSearchEnabled: false // Disabled!
        );

        $this->assertEquals('I cannot search since web search is disabled.', $result['text']);
        $this->assertNotNull($result['usage']);
        $this->assertEquals(50, $result['usage']->promptTokens);
        $this->assertEquals(50, $result['usage']->completionTokens);
    }

    /**
     * Test that ComposeAgentService can robustly parse double-wrapped/nested tool calls
     * (e.g. <tool_call><tool_call name="web_search">...</tool_call></tool_call>).
     */
    public function test_agent_robust_nested_tool_call_parsing(): void
    {
        $aiService = $this->createMock(AiService::class);
        $model = $this->createMock(AiModel::class);
        $model->method('getId')->willReturn('test-model');
        $aiService->method('getModel')->willReturn($model);

        $usage1 = new TokenUsage(model: $model, promptTokens: 10, completionTokens: 20);
        $usage2 = new TokenUsage(model: $model, promptTokens: 40, completionTokens: 30);

        // 1st LLM response: double-wrapped tool call
        $doubleWrappedResponse = "<tool_call>\n<tool_call name=\"web_search\">\n{\n  \"query\": \"nested query\"\n}\n</tool_call>\n</tool_call>";
        $response1 = new AiResponse(
            content: ['text' => $doubleWrappedResponse],
            usage: $usage1
        );

        // 2nd LLM response: final answer
        $response2 = new AiResponse(
            content: ['text' => 'The answer to nested query.'],
            usage: $usage2
        );

        $aiService->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls($response1, $response2);

        $searchAgent = $this->createMock(SearchAgentService::class);
        $searchAgent->expects($this->once())
            ->method('search')
            ->with('nested query')
            ->willReturn('Search result for nested query');

        $service = new ComposeAgentService($aiService, $searchAgent);

        $result = $service->compose(
            userPrompt: 'nested query test',
            systemPrompt: 'You have tools.',
            modelId: 'test-model',
            temperature: 0.7,
            proactive: false
        );

        $this->assertEquals('The answer to nested query.', $result['text']);
    }

    /**
     * Test that ComposeAgentService can robustly parse double-wrapped/nested unnamed tool calls
     * (e.g. <tool_call><tool_call>web_search\n{"query": "nested query"}</tool_call></tool_call>).
     */
    public function test_agent_robust_nested_unnamed_tool_call_parsing(): void
    {
        $aiService = $this->createMock(AiService::class);
        $model = $this->createMock(AiModel::class);
        $model->method('getId')->willReturn('test-model');
        $aiService->method('getModel')->willReturn($model);

        $usage1 = new TokenUsage(model: $model, promptTokens: 10, completionTokens: 20);
        $usage2 = new TokenUsage(model: $model, promptTokens: 40, completionTokens: 30);

        // 1st LLM response: double-wrapped unnamed line-based tool call
        $doubleWrappedResponse = "<tool_call>\n<tool_call>\nweb_search\n{\n  \"query\": \"nested query\"\n}\n</tool_call>\n</tool_call>";
        $response1 = new AiResponse(
            content: ['text' => $doubleWrappedResponse],
            usage: $usage1
        );

        // 2nd LLM response: final answer
        $response2 = new AiResponse(
            content: ['text' => 'The answer to nested query.'],
            usage: $usage2
        );

        $aiService->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls($response1, $response2);

        $searchAgent = $this->createMock(SearchAgentService::class);
        $searchAgent->expects($this->once())
            ->method('search')
            ->with('nested query')
            ->willReturn('Search result for nested query');

        $service = new ComposeAgentService($aiService, $searchAgent);

        $result = $service->compose(
            userPrompt: 'nested query test',
            systemPrompt: 'You have tools.',
            modelId: 'test-model',
            temperature: 0.7,
            proactive: false
        );

        $this->assertEquals('The answer to nested query.', $result['text']);
    }

    /**
     * Test the proactive direct search flow where the search is executed directly
     * via the MCP server (mocked) and results are prepended to the prompt.
     */
    public function test_proactive_direct_search_flow(): void
    {
        $aiService = $this->createMock(AiService::class);
        $model = $this->createMock(AiModel::class);
        $model->method('getId')->willReturn('test-model');
        $aiService->method('getModel')->willReturn($model);

        $usage = new TokenUsage(model: $model, promptTokens: 30, completionTokens: 40);

        // We expect only 1 call to sendRequest (the main LLM execution)
        $aiService->expects($this->once())
            ->method('sendRequest')
            ->with($this->callback(function ($payload) {
                // Assert that the user prompt has search results prepended
                $userPrompt = $payload['messages'][1]['content']['text'] ?? '';

                return str_contains($userPrompt, 'SEARCH RESULTS / CONTEXT:') &&
                       str_contains($userPrompt, 'Mocked President of JLU is Katharina Lorenz') &&
                       str_contains($userPrompt, 'Wer ist Präsident der JLU Gießen?');
            }))
            ->willReturn(new AiResponse(
                content: ['text' => 'Katharina Lorenz ist die Präsidentin der JLU Gießen.'],
                usage: $usage
            ));

        $searchAgent = $this->createMock(SearchAgentService::class);
        $searchAgent->expects($this->once())
            ->method('executeDirectSearch')
            ->with('Wer ist Präsident der JLU Gießen?')
            ->willReturn('Mocked President of JLU is Katharina Lorenz');

        $service = new ComposeAgentService($aiService, $searchAgent);

        $result = $service->compose(
            userPrompt: "INSTRUCTION / PROMPT:\nWer ist Präsident der JLU Gießen?\n\n",
            systemPrompt: 'You are a helpful assistant.',
            modelId: 'test-model',
            temperature: 0.7,
            webSearchEnabled: true,
            proactive: true
        );

        $this->assertEquals('Katharina Lorenz ist die Präsidentin der JLU Gießen.', $result['text']);
    }

    /**
     * Test a live HTTP request to the remote MCP server to use the search_uni_giessen tool
     * for the president query and verify that the result contains "Katharina Lorenz".
     */
    public function test_live_mcp_jlu_president(): void
    {
        $searchAgent = app(SearchAgentService::class);
        $result = $searchAgent->executeDirectSearch('Wer ist Präsident der JLU Gießen?');

        dump('Live JLU President search result: '.$result);

        $this->assertStringContainsString('Katharina Lorenz', $result);
    }
}
