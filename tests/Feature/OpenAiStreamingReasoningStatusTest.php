<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\Providers\OpenAi\Request\OpenAiStreamingRequest;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiResponse;
use Tests\TestCase;

/**
 * The reasoning step of the chat completions stream. A thinking model behind the
 * gateway streams its reasoning whether or not it was asked for - for 106
 * seconds before a tool call, once - and the step is what tells the user the
 * model is working. The reasoning text itself stays reserved for requests that
 * asked for it.
 */
class OpenAiStreamingReasoningStatusTest extends TestCase
{
    private AiModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = $this->createMock(AiModel::class);
        $this->model->method('getId')->willReturn('jlu/qwen3.8-27b-fast');
    }

    private function request(bool $reasoningRequested = false): object
    {
        $payload = ['model' => 'jlu/qwen3.8-27b-fast', 'messages' => []];

        if ($reasoningRequested) {
            $payload['reasoning'] = ['effort' => 'medium'];
        }

        return new class($payload, fn () => null) extends OpenAiStreamingRequest
        {
            public function handle(AiModel $model, array $chunk): AiResponse
            {
                return $this->chunkToResponse($model, json_encode($chunk));
            }

            public function next(): void
            {
                $this->nextOutput();
            }
        };
    }

    private function delta(array $delta, ?string $finishReason = null): array
    {
        return ['choices' => [array_filter(['delta' => $delta, 'finish_reason' => $finishReason], fn ($v) => $v !== null)]];
    }

    /** @return array<int, array> the reasoning status updates of a response */
    private function reasoningSteps(AiResponse $response): array
    {
        $steps = [];
        foreach ($response->content['auxiliaries'] ?? [] as $auxiliary) {
            $content = json_decode($auxiliary['content'], true);
            if ($auxiliary['type'] === 'status' && ($content['type'] ?? null) === 'reasoning') {
                $steps[] = $content;
            }
        }

        return $steps;
    }

    private function hasSummary(AiResponse $response): bool
    {
        return in_array('reasoning_summary', array_column($response->content['auxiliaries'] ?? [], 'type'), true);
    }

    public function test_unrequested_reasoning_shows_its_step_but_not_its_text(): void
    {
        $request = $this->request();

        $opened = $request->handle($this->model, $this->delta(['reasoning_content' => 'Der Nutzer fragt nach HAProxy']));
        $more = $request->handle($this->model, $this->delta(['reasoning_content' => ' und Ubuntu 24.04.']));
        $answer = $request->handle($this->model, $this->delta(['content' => 'HAProxy 2.8']));

        $this->assertSame([['status' => 'reasoning', 'type' => 'reasoning', 'output_index' => 0]], $this->reasoningSteps($opened));
        $this->assertSame([], $this->reasoningSteps($more));
        $this->assertSame([['status' => 'completed', 'type' => 'reasoning', 'output_index' => 0]], $this->reasoningSteps($answer));
        $this->assertFalse($this->hasSummary($answer));
        $this->assertSame('HAProxy 2.8', $answer->content['text']);
    }

    public function test_a_tool_call_closes_the_reasoning_step(): void
    {
        $request = $this->request();

        $request->handle($this->model, $this->delta(['reasoning_content' => 'Ich sollte suchen.']));
        $call = $request->handle($this->model, $this->delta(['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'web_search', 'arguments' => '']]]]));

        $this->assertSame('completed', $this->reasoningSteps($call)[0]['status'] ?? null);
    }

    public function test_requested_reasoning_still_closes_with_its_summary(): void
    {
        $request = $this->request(reasoningRequested: true);

        $opened = $request->handle($this->model, $this->delta(['reasoning_content' => "**Planung**\nIch vergleiche die Versionen."]));
        $answer = $request->handle($this->model, $this->delta(['content' => 'Antwort']));

        $this->assertSame('reasoning', $this->reasoningSteps($opened)[0]['status']);
        $this->assertSame([['status' => 'completed', 'type' => 'reasoning', 'message' => 'Planung', 'output_index' => 0]], $this->reasoningSteps($answer));
        $this->assertTrue($this->hasSummary($answer));
    }

    public function test_each_output_gets_its_own_reasoning_step(): void
    {
        // One upstream request per tool round: the frontend keys a step by type
        // and output index, so the second round needs an index of its own.
        $request = $this->request();

        $request->handle($this->model, $this->delta(['reasoning_content' => 'Runde eins.']));
        $request->handle($this->model, $this->delta(['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'web_search', 'arguments' => '{}']]]], 'tool_calls'));

        $request->next();
        $second = $request->handle($this->model, $this->delta(['reasoning_content' => 'Runde zwei.']));

        $this->assertSame([['status' => 'reasoning', 'type' => 'reasoning', 'output_index' => 1]], $this->reasoningSteps($second));
    }

    public function test_a_model_without_reasoning_shows_no_reasoning_step(): void
    {
        $request = $this->request();

        $first = $request->handle($this->model, $this->delta(['role' => 'assistant', 'content' => '']));
        $answer = $request->handle($this->model, $this->delta(['content' => 'Hallo']));
        $done = $request->handle($this->model, $this->delta([], 'stop'));

        $this->assertSame([], [...$this->reasoningSteps($first), ...$this->reasoningSteps($answer), ...$this->reasoningSteps($done)]);
    }
}
