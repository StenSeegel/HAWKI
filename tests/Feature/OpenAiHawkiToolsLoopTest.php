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

    private function toolCallResponse(string $arguments, string $id = 'call_1'): array
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
                        'function' => ['name' => 'web_search', 'arguments' => $arguments],
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
    private function runLoop(array $upstreamResponses, array $tools, ?string $binding = null): array
    {
        $payloads = [];

        $request = new class(
            ['model' => 'jlu/gemma-4-26b-it', 'messages' => [['role' => 'user', 'content' => 'weather?']], 'tools' => [['type' => 'function']], 'tool_choice' => 'auto'],
            $tools,
            app(ToolCallRunner::class),
            $binding,
            $upstreamResponses,
            $payloads
        ) extends OpenAiHawkiToolsNonStreamingRequest
        {
            private int $round = 0;

            public function __construct(
                array $payload,
                array $tools,
                ToolCallRunner $runner,
                ?string $binding,
                private array $upstreamResponses,
                private array &$payloads
            ) {
                parent::__construct($payload, $tools, $runner, $binding);
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
            'websearch-mcp'
        );

        $this->assertSame('websearch-mcp', $toolCalls[0]['binding']);
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
}
