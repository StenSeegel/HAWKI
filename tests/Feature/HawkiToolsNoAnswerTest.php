<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\Providers\OpenAiHawkiTools\Request\OpenAiHawkiToolsStreamingRequest;
use App\Services\AI\Tools\ToolCallRunner;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiResponse;
use Tests\TestCase;

/**
 * The 'no_answer' auxiliary of the streamed HAWKI tools path: a final round
 * that ends without text is flagged on the finishing chunk, so the frontend
 * shows its failure message instead of saving a blank answer.
 *
 * The chunks are fed to the request directly; the upstream transport speaks raw
 * cURL and cannot be faked.
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

    /**
     * The request with its protected chunk handler opened up. Without tools in
     * the payload it stands for the final round, where they are withdrawn.
     */
    private function request(bool $finalRound): object
    {
        $payload = ['model' => 'jlu/qwen3-coder-next', 'messages' => []];

        if (! $finalRound) {
            $payload['tools'] = [['type' => 'function']];
        }

        return new class($payload, fn () => null, app(ToolCallRunner::class)) extends OpenAiHawkiToolsStreamingRequest
        {
            public function __construct(array $payload, \Closure $callback, ToolCallRunner $runner)
            {
                parent::__construct($payload, $callback, [], $runner);
            }

            public function handle(AiModel $model, string $chunk): AiResponse
            {
                return $this->chunkToResponse($model, $chunk);
            }
        };
    }

    private function flagged(AiResponse $response): bool
    {
        foreach ($response->content['auxiliaries'] ?? [] as $auxiliary) {
            if ($auxiliary['type'] === 'no_answer') {
                return true;
            }
        }

        return false;
    }

    private function contentChunk(string $text): string
    {
        return json_encode(['choices' => [['delta' => ['content' => $text]]]]);
    }

    private function stopChunk(): string
    {
        return json_encode(['choices' => [['delta' => new \stdClass, 'finish_reason' => 'stop']]]);
    }

    public function test_an_empty_final_round_is_flagged(): void
    {
        // What the gateway sent when it took a fifth tool call out of the text:
        // no content at all, finish_reason stop.
        $this->assertTrue($this->flagged($this->request(finalRound: true)->handle($this->model, $this->stopChunk())));
    }

    public function test_a_final_round_with_an_answer_is_not_flagged(): void
    {
        $request = $this->request(finalRound: true);

        $request->handle($this->model, $this->contentChunk('HAProxy 2.8 dropped nbproc.'));

        $this->assertFalse($this->flagged($request->handle($this->model, $this->stopChunk())));
    }

    public function test_an_empty_answer_outside_the_final_round_is_not_flagged(): void
    {
        $this->assertFalse($this->flagged($this->request(finalRound: false)->handle($this->model, $this->stopChunk())));
    }
}
