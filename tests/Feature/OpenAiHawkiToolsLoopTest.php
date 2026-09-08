<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\Providers\OpenAiHawkiTools\Request\OpenAiHawkiToolsNonStreamingRequest;
use App\Services\AI\Tools\HawkiToolInterface;
use App\Services\AI\Tools\ToolCallRunner;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiResponse;
use Tests\TestCase;

/**
 * Exercises the tool loop itself. The HTTP transport of AbstractRequest speaks raw
 * cURL and cannot be faked, so it is replaced here while the loop logic - rounds,
 * message shape, tool dispatch, usage summing and the round cap - stays real.
 */
class OpenAiHawkiToolsLoopTest extends TestCase
{
    private AiModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = $this->createMock(AiModel::class);
        $this->model->method('getId')->willReturn('jlu/gemma-4-26b-it');
    }

    /**
     * A web_search tool that records its calls and returns a canned result.
     */
    private function recordingTool(array &$calls, string $result = 'Giessen: 14 degrees, light rain.'): HawkiToolInterface
    {
        return new class($calls, $result) implements HawkiToolInterface
        {
            public function __construct(private array &$calls, private string $result) {}

            public function getKey(): string
            {
                return 'web_search';
            }

            public function getDefinition(): array
            {
                return [
                    'type' => 'function',
                    'function' => ['name' => 'web_search', 'description' => 'search', 'parameters' => $this->getArgumentSchema()],
                ];
            }

            public function getArgumentSchema(): array
            {
                return ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']];
            }

            public function execute(array $arguments, ?string $serverBinding = null): string
            {
                $this->calls[] = ['arguments' => $arguments, 'binding' => $serverBinding];

                return $this->result;
            }
        };
    }

    /**
     * A web_search tool that hands its result to the source collector, the way
     * {@see \App\Services\AI\Tools\WebSearchTool} does with what the MCP server
     * returned.
     */
    private function searchingTool(string $result): HawkiToolInterface
    {
        return new class($result) implements HawkiToolInterface
        {
            public function __construct(private string $result) {}

            public function getKey(): string
            {
                return 'web_search';
            }

            public function getDefinition(): array
            {
                return [
                    'type' => 'function',
                    'function' => ['name' => 'web_search', 'description' => 'search', 'parameters' => $this->getArgumentSchema()],
                ];
            }

            public function getArgumentSchema(): array
            {
                return ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']];
            }

            public function execute(array $arguments, ?string $serverBinding = null): string
            {
                app(\App\Services\AI\Tools\WebSearchSources::class)->collect($this->result);

                return $this->result;
            }
        };
    }

    /**
     * A code_interpreter tool that records the server binding it was handed.
     */
    private function recordingCodeTool(array &$calls): HawkiToolInterface
    {
        return new class($calls) implements HawkiToolInterface
        {
            public function __construct(private array &$calls) {}

            public function getKey(): string
            {
                return 'code_interpreter';
            }

            public function getDefinition(): array
            {
                return [
                    'type' => 'function',
                    'function' => ['name' => 'code_interpreter', 'description' => 'run', 'parameters' => $this->getArgumentSchema()],
                ];
            }

            public function getArgumentSchema(): array
            {
                return ['type' => 'object', 'properties' => ['code' => ['type' => 'string']], 'required' => ['code']];
            }

            public function execute(array $arguments, ?string $serverBinding = null): string
            {
                $this->calls[] = ['arguments' => $arguments, 'binding' => $serverBinding];

                return '1';
            }
        };
    }

    private function toolCallResponse(string $arguments, string $id = 'call_1', string $name = 'web_search'): array
    {
        return [
            'choices' => [[
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => $id,
                        'type' => 'function',
                        'function' => ['name' => $name, 'arguments' => $arguments],
                    ]],
                ],
            ]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
        ];
    }

    private function finalResponse(string $text): array
    {
        return [
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => $text],
            ]],
            'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 80],
        ];
    }

    /**
     * @param  array<int, array>  $upstreamResponses
     * @param  array<string, HawkiToolInterface>  $tools
     * @return array{response: AiResponse, payloads: array}
     */
    private function runLoop(array $upstreamResponses, array $tools, array $bindings = []): array
    {
        $payloads = [];

        $request = new class(
            ['model' => 'jlu/gemma-4-26b-it', 'messages' => [['role' => 'user', 'content' => 'weather?']], 'tools' => [['type' => 'function']], 'tool_choice' => 'auto'],
            $tools,
            app(ToolCallRunner::class),
            $bindings,
            $upstreamResponses,
            $payloads
        ) extends OpenAiHawkiToolsNonStreamingRequest
        {
            private int $round = 0;

            public function __construct(
                array $payload,
                array $tools,
                ToolCallRunner $runner,
                array $bindings,
                private array $upstreamResponses,
                private array &$payloads
            ) {
                parent::__construct($payload, $tools, $runner, $bindings);
            }

            protected function executeNonStreamingRequest(
                AiModel $model,
                array $payload,
                callable $dataToResponse,
                ?callable $getHttpHeaders = null,
                ?string $apiUrl = null,
                ?int $timeout = null
            ): AiResponse {
                $this->payloads[] = $payload;

                $data = $this->upstreamResponses[$this->round] ?? end($this->upstreamResponses);
                $this->round++;

                return $dataToResponse($data);
            }
        };

        $response = $request->execute($this->model);

        return ['response' => $response, 'payloads' => $payloads];
    }

    public function test_a_tool_call_is_executed_and_the_answer_returned(): void
    {
        $toolCalls = [];

        $result = $this->runLoop(
            [
                $this->toolCallResponse('{"query":"current weather Giessen"}'),
                $this->finalResponse('It is 14 degrees and raining lightly in Giessen.'),
            ],
            ['web_search' => $this->recordingTool($toolCalls)]
        );

        $this->assertSame('It is 14 degrees and raining lightly in Giessen.', $result['response']->content['text']);
        $this->assertCount(1, $toolCalls);
        $this->assertSame(['query' => 'current weather Giessen'], $toolCalls[0]['arguments']);
        $this->assertCount(2, $result['payloads']);
    }

    public function test_the_second_round_carries_the_assistant_call_and_the_tool_result(): void
    {
        $toolCalls = [];

        $result = $this->runLoop(
            [
                $this->toolCallResponse('{"query":"weather"}', 'call_abc'),
                $this->finalResponse('Done.'),
            ],
            ['web_search' => $this->recordingTool($toolCalls, 'MOCK SEARCH RESULT')]
        );

        $messages = $result['payloads'][1]['messages'];

        $this->assertCount(3, $messages);

        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame('call_abc', $messages[1]['tool_calls'][0]['id']);
        $this->assertSame('web_search', $messages[1]['tool_calls'][0]['function']['name']);

        $this->assertSame('tool', $messages[2]['role']);
        $this->assertSame('call_abc', $messages[2]['tool_call_id']);
        $this->assertSame('MOCK SEARCH RESULT', $messages[2]['content']);
    }

    public function test_usage_is_summed_across_rounds_and_reported_once(): void
    {
        $toolCalls = [];

        $result = $this->runLoop(
            [
                $this->toolCallResponse('{"query":"weather"}'),
                $this->finalResponse('Done.'),
            ],
            ['web_search' => $this->recordingTool($toolCalls)]
        );

        $usage = $result['response']->usage;

        $this->assertNotNull($usage);
        $this->assertSame(1000, $usage->promptTokens);
        $this->assertSame(100, $usage->completionTokens);
        $this->assertSame(['web_search' => 1], $usage->serverToolUse);
    }

    public function test_a_direct_answer_makes_no_extra_request(): void
    {
        $toolCalls = [];

        $result = $this->runLoop(
            [$this->finalResponse('No search needed.')],
            ['web_search' => $this->recordingTool($toolCalls)]
        );

        $this->assertSame('No search needed.', $result['response']->content['text']);
        $this->assertSame([], $toolCalls);
        $this->assertCount(1, $result['payloads']);
    }

    public function test_the_round_cap_holds_and_the_last_round_withdraws_the_tools(): void
    {
        $toolCalls = [];

        // A model that keeps asking for the tool forever.
        $result = $this->runLoop(
            [$this->toolCallResponse('{"query":"loop"}')],
            ['web_search' => $this->recordingTool($toolCalls)]
        );

        $expectedRequests = OpenAiHawkiToolsNonStreamingRequest::MAX_TOOL_ROUNDS + 1;
        $this->assertCount($expectedRequests, $result['payloads']);
        $this->assertCount(OpenAiHawkiToolsNonStreamingRequest::MAX_TOOL_ROUNDS, $toolCalls);

        // The final request must not offer tools any more.
        $last = $result['payloads'][$expectedRequests - 1];
        $this->assertArrayNotHasKey('tools', $last);
        $this->assertArrayNotHasKey('tool_choice', $last);
    }

    public function test_the_provider_binding_is_handed_to_the_tool(): void
    {
        $toolCalls = [];

        $this->runLoop(
            [
                $this->toolCallResponse('{"query":"weather"}'),
                $this->finalResponse('Done.'),
            ],
            ['web_search' => $this->recordingTool($toolCalls)],
            ['web_search' => 'websearch-mcp']
        );

        $this->assertSame('websearch-mcp', $toolCalls[0]['binding']);
    }

    /**
     * Each tool gets its own pinned server, and a tool with none gets null so it
     * falls back to the server named in its own binding.
     *
     * The regression: the client used to resolve one binding for the whole request
     * and hand it to every tool. On the ki@JLU provider, where web search is
     * pinned to websearch-mcp and the code interpreter is pinned to nothing,
     * switching web search on in the chat sent the code interpreter's code_exec
     * call to the search server, which answered "Tool 'code_exec' not found" - so
     * the code never ran and no plot was produced.
     */
    public function test_a_tool_without_a_pinned_server_is_not_given_another_tools_server(): void
    {
        $searchCalls = [];
        $codeCalls = [];

        $this->runLoop(
            [
                $this->toolCallResponse('{"query":"weather"}'),
                $this->toolCallResponse('{"code":"print(1)"}', 'call_2', 'code_interpreter'),
                $this->finalResponse('Done.'),
            ],
            [
                'web_search' => $this->recordingTool($searchCalls),
                'code_interpreter' => $this->recordingCodeTool($codeCalls),
            ],
            // Exactly what the ki@JLU provider stores.
            ['web_search' => 'websearch-mcp', 'code_interpreter' => null]
        );

        $this->assertSame('websearch-mcp', $searchCalls[0]['binding']);
        $this->assertNull(
            $codeCalls[0]['binding'],
            'The code interpreter must not inherit the web search server.'
        );
    }

    public function test_dirty_arguments_are_sanitized_before_the_tool_runs(): void
    {
        $toolCalls = [];

        $this->runLoop(
            [
                // Verbatim gemma output shape, with the leaked template token.
                $this->toolCallResponse('{"query": "current weather Giessen<|\"|>"}'),
                $this->finalResponse('Done.'),
            ],
            ['web_search' => $this->recordingTool($toolCalls)]
        );

        $this->assertSame(['query' => 'current weather Giessen'], $toolCalls[0]['arguments']);
    }

    public function test_a_failing_tool_still_lets_the_model_answer(): void
    {
        $exploding = new class implements HawkiToolInterface
        {
            public function getKey(): string
            {
                return 'web_search';
            }

            public function getDefinition(): array
            {
                return ['type' => 'function', 'function' => ['name' => 'web_search', 'description' => '', 'parameters' => $this->getArgumentSchema()]];
            }

            public function getArgumentSchema(): array
            {
                return ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']];
            }

            public function execute(array $arguments, ?string $serverBinding = null): string
            {
                throw new \RuntimeException('MCP server unreachable');
            }
        };

        $result = $this->runLoop(
            [
                $this->toolCallResponse('{"query":"weather"}'),
                $this->finalResponse('I could not reach the search service.'),
            ],
            ['web_search' => $exploding]
        );

        $this->assertSame('I could not reach the search service.', $result['response']->content['text']);

        $toolMessage = $result['payloads'][1]['messages'][2];
        $this->assertSame('tool', $toolMessage['role']);
        $this->assertStringContainsString('MCP server unreachable', $toolMessage['content']);
    }

    public function test_without_tools_the_loop_is_a_plain_single_request(): void
    {
        $result = $this->runLoop(
            [$this->toolCallResponse('{"query":"weather"}')],
            []
        );

        $this->assertCount(1, $result['payloads']);
    }

    public function test_the_sources_a_search_used_are_returned_as_citations(): void
    {
        $result = $this->runLoop(
            [
                $this->toolCallResponse('{"query":"php 8.4"}'),
                $this->finalResponse('PHP 8.4 was released on 21 November 2024.'),
            ],
            ['web_search' => $this->searchingTool(
                "1. PHP 8.4\n\n## Sources\n\n1. [PHP 8.4](https://www.php.net/releases/8.4/)\n"
            )]
        );

        $auxiliaries = $result['response']->content['auxiliaries'] ?? [];

        $this->assertCount(1, $auxiliaries);
        $this->assertSame('hawkiToolsCitations', $auxiliaries[0]['type']);
        $this->assertSame(
            ['citations' => [[
                'type' => 'url_citation',
                'url' => 'https://www.php.net/releases/8.4/',
                'title' => 'PHP 8.4',
                'start_index' => 0,
                'end_index' => 0,
            ]]],
            json_decode($auxiliaries[0]['content'], true)
        );
    }

    public function test_an_answer_without_a_search_carries_no_citations(): void
    {
        $result = $this->runLoop([$this->finalResponse('2 + 2 is 4.')], []);

        $this->assertArrayNotHasKey('auxiliaries', $result['response']->content);
    }
}
