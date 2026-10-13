<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\Providers\OpenAiHawkiTools\Request\OpenAiHawkiToolsNonStreamingRequest;
use App\Services\AI\Providers\OpenAiHawkiTools\Request\OpenAiHawkiToolsStreamingRequest;
use App\Services\AI\Tools\HawkiToolInterface;
use App\Services\AI\Tools\ToolCallRunner;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiResponse;
use Tests\TestCase;

/**
 * The end of the streamed HAWKI tools loop: the last tool round tells the model
 * its tool budget is used up, and a final round that still ends without text is
 * flagged 'no_answer', so the frontend shows its failure message instead of
 * saving a blank answer. Each tool call along the way gets a status step of its
 * own.
 *
 * The upstream transport speaks raw cURL and cannot be faked, so it is replaced
 * by scripted rounds of chunks; the loop itself - rounds, tool dispatch, the
 * withdrawn tools and the checks after the last round - stays real.
 */
class HawkiToolsNoAnswerTest extends TestCase
{
    private AiModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = $this->createMock(AiModel::class);
        $this->model->method('getId')->willReturn('jlu/qwen3-coder-next');
    }

    private function tool(string $key, string $result): HawkiToolInterface
    {
        return new class($key, $result) implements HawkiToolInterface
        {
            public function __construct(private string $key, private string $result) {}

            public function getKey(): string
            {
                return $this->key;
            }

            public function getDefinition(): array
            {
                return ['type' => 'function', 'function' => ['name' => $this->key, 'parameters' => $this->getArgumentSchema()]];
            }

            public function getArgumentSchema(): array
            {
                return ['type' => 'object', 'properties' => ['query' => ['type' => 'string'], 'code' => ['type' => 'string']]];
            }

            public function execute(array $arguments, ?string $serverBinding = null): string
            {
                return $this->result;
            }
        };
    }

    private function content(string $text): array
    {
        return ['choices' => [['delta' => ['content' => $text]]]];
    }

    private function finish(string $reason): array
    {
        return ['choices' => [['delta' => new \stdClass, 'finish_reason' => $reason]]];
    }

    private function toolCall(string $name, string $arguments): array
    {
        return ['choices' => [[
            'delta' => ['tool_calls' => [[
                'index' => 0,
                'id' => 'call_'.uniqid(),
                'function' => ['name' => $name, 'arguments' => $arguments],
            ]]],
            'finish_reason' => 'tool_calls',
        ]]];
    }

    /**
     * Runs the loop: every tool round calls the tool, then the final round sends
     * the given chunks.
     *
     * @param  array<int, array>  $finalRound
     * @param  array<int, array>  $firstRoundPrefix  chunks before the first tool call
     * @return array{responses: array<int, AiResponse>, payloads: array<int, array>}
     */
    private function runLoop(array $finalRound, string $tool = 'web_search', array $firstRoundPrefix = []): array
    {
        $arguments = $tool === 'code_interpreter' ? '{"code":"print(6*7)"}' : '{"query":"haproxy 2.8"}';

        $rounds = [];
        for ($i = 0; $i < OpenAiHawkiToolsNonStreamingRequest::MAX_TOOL_ROUNDS; $i++) {
            $rounds[] = [...($i === 0 ? $firstRoundPrefix : []), $this->toolCall($tool, $arguments)];
        }
        $rounds[] = $finalRound;

        $responses = [];
        $payloads = [];

        $request = new class(
            ['model' => 'jlu/qwen3-coder-next', 'messages' => [['role' => 'user', 'content' => 'q']], 'tools' => [['type' => 'function']]],
            function (AiResponse $response) use (&$responses) {
                $responses[] = $response;
            },
            [$tool => $this->tool($tool, $tool === 'code_interpreter' ? '42' : 'MOCK SEARCH RESULT')],
            app(ToolCallRunner::class),
            $rounds,
            $payloads
        ) extends OpenAiHawkiToolsStreamingRequest
        {
            private int $round = 0;

            public function __construct(array $payload, \Closure $callback, array $tools, ToolCallRunner $runner, private array $rounds, private array &$payloads)
            {
                parent::__construct($payload, $callback, $tools, $runner);
            }

            protected function executeStreamingRequest(
                AiModel $model,
                array $payload,
                callable $onData,
                callable $chunkToResponse,
                ?callable $getHttpHeaders = null,
                ?string $apiUrl = null,
                ?int $timeout = null
            ): void {
                $this->payloads[] = $payload;

                foreach ($this->rounds[$this->round++] as $chunk) {
                    $onData($chunkToResponse($model, json_encode($chunk)));
                }
            }
        };

        $request->execute($this->model);

        return ['responses' => $responses, 'payloads' => $payloads];
    }

    /** @return array<int, AiResponse> the responses that carry the flag */
    private function flagged(array $responses): array
    {
        return array_values(array_filter($responses, fn (AiResponse $response) => in_array(
            'no_answer',
            array_column($response->content['auxiliaries'] ?? [], 'type'),
            true
        )));
    }

    public function test_an_empty_final_round_is_flagged_on_the_finishing_chunk(): void
    {
        // What the gateway sent when it took a fifth tool call out of the text:
        // no content at all, finish_reason stop.
        $flagged = $this->flagged($this->runLoop([$this->finish('stop')])['responses']);

        $this->assertCount(1, $flagged);
        $this->assertTrue($flagged[0]->isDone);
    }

    public function test_text_from_an_earlier_round_is_no_answer(): void
    {
        $result = $this->runLoop([$this->finish('stop')], firstRoundPrefix: [$this->content('Ich suche kurz nach.')]);

        $this->assertCount(1, $this->flagged($result['responses']));
    }

    public function test_a_final_round_ending_in_a_tool_call_is_flagged_after_the_stream(): void
    {
        // No chunk is marked done, so the client reads on until the connection
        // closes and gets the flag as a chunk of its own.
        $responses = $this->runLoop([$this->toolCall('web_search', '{"query":"again"}')])['responses'];
        $flagged = $this->flagged($responses);

        $this->assertCount(1, $flagged);
        $this->assertFalse($flagged[0]->isDone);
        $this->assertSame(end($responses), $flagged[0]);
    }

    public function test_a_final_round_cut_off_by_the_token_limit_is_flagged_after_the_stream(): void
    {
        $flagged = $this->flagged($this->runLoop([$this->finish('length')])['responses']);

        $this->assertCount(1, $flagged);
        $this->assertFalse($flagged[0]->isDone);
    }

    public function test_a_final_answer_is_not_flagged(): void
    {
        $responses = $this->runLoop([$this->content('HAProxy 2.8 dropped nbproc.'), $this->finish('stop')])['responses'];

        $this->assertSame([], $this->flagged($responses));
    }

    public function test_shown_tool_output_is_not_replaced_by_the_failure_message(): void
    {
        // The code and its output are in the message already; the frontend would
        // replace them along with the missing words.
        $responses = $this->runLoop([$this->finish('stop')], tool: 'code_interpreter')['responses'];

        $this->assertSame([], $this->flagged($responses));
    }

    public function test_only_the_last_tool_round_carries_the_budget_note(): void
    {
        $payloads = $this->runLoop([$this->content('Antwort.'), $this->finish('stop')])['payloads'];

        $final = end($payloads);
        $this->assertArrayNotHasKey('tools', $final);

        $toolResults = array_values(array_filter($final['messages'], fn (array $message) => $message['role'] === 'tool'));
        $this->assertCount(OpenAiHawkiToolsNonStreamingRequest::MAX_TOOL_ROUNDS, $toolResults);

        foreach (array_slice($toolResults, 0, -1) as $earlier) {
            $this->assertSame('MOCK SEARCH RESULT', $earlier['content']);
        }

        $this->assertSame("MOCK SEARCH RESULT\n\n".config('hawki_tools.final_round_note'), end($toolResults)['content']);
    }

    public function test_every_tool_call_gets_a_step_of_its_own(): void
    {
        // The frontend keys a step by type and output index: with one index for
        // every call, each search replaced the step of the one before.
        $responses = $this->runLoop([$this->content('Antwort.'), $this->finish('stop')])['responses'];

        $steps = [];
        foreach ($responses as $response) {
            foreach ($response->content['auxiliaries'] ?? [] as $auxiliary) {
                $status = json_decode($auxiliary['content'], true);
                if ($auxiliary['type'] === 'status' && ($status['type'] ?? null) === 'web_search') {
                    $steps[$status['output_index']][] = $status['status'];
                }
            }
        }

        $this->assertSame(
            array_fill(0, OpenAiHawkiToolsNonStreamingRequest::MAX_TOOL_ROUNDS, ['in_progress', 'completed']),
            $steps
        );
    }

    public function test_an_empty_answer_while_the_tools_are_still_offered_is_not_flagged(): void
    {
        $responses = [];

        $request = new class(
            ['model' => 'jlu/qwen3-coder-next', 'messages' => [], 'tools' => [['type' => 'function']]],
            function (AiResponse $response) use (&$responses) {
                $responses[] = $response;
            },
            app(ToolCallRunner::class)
        ) extends OpenAiHawkiToolsStreamingRequest
        {
            public function __construct(array $payload, \Closure $callback, ToolCallRunner $runner)
            {
                parent::__construct($payload, $callback, [], $runner);
            }

            protected function executeStreamingRequest(
                AiModel $model,
                array $payload,
                callable $onData,
                callable $chunkToResponse,
                ?callable $getHttpHeaders = null,
                ?string $apiUrl = null,
                ?int $timeout = null
            ): void {
                $onData($chunkToResponse($model, json_encode(['choices' => [['delta' => new \stdClass, 'finish_reason' => 'stop']]])));
            }
        };

        $request->execute($this->model);

        $this->assertSame([], $this->flagged($responses));
    }
}
