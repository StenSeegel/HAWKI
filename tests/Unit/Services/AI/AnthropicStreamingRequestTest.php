<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\Providers\Anthropic\Request\AnthropicStreamingRequest;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiResponse;
use Tests\TestCase;

/**
 * How the Anthropic SSE events become HAWKI responses. The fixtures are the
 * events the Messages API actually sends, one JSON object each, as the
 * StreamChunkHandler hands them over.
 */
class AnthropicStreamingRequestTest extends TestCase
{
    private AiModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = $this->createMock(AiModel::class);
    }

    public function test_an_error_event_reaches_the_frontend_as_an_error(): void
    {
        // Anthropic rejected the request before any content: without credits,
        // with a bad key or an invalid payload the body is this single object.
        $response = $this->parse(json_encode([
            'type' => 'error',
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Your credit balance is too low to access the Anthropic API.',
            ],
            'request_id' => 'req_011Cf7FZgBMkSFprBrunvgUd',
        ]));

        $this->assertTrue($response->isDone);
        $this->assertSame('Your credit balance is too low to access the Anthropic API.', $response->error);
        $this->assertStringContainsString('credit balance is too low', $response->content['text']);
    }

    public function test_a_text_delta_carries_its_text(): void
    {
        $response = $this->parse(json_encode([
            'type' => 'content_block_delta',
            'index' => 0,
            'delta' => ['type' => 'text_delta', 'text' => 'Hallo'],
        ]));

        $this->assertSame('Hallo', $response->content['text']);
        $this->assertFalse($response->isDone);
        $this->assertNull($response->error);
    }

    public function test_message_stop_ends_the_answer(): void
    {
        $response = $this->parse(json_encode(['type' => 'message_stop']));

        $this->assertTrue($response->isDone);
        $this->assertSame('', $response->content['text']);
    }

    private function parse(string $chunk): AiResponse
    {
        $request = new AnthropicStreamingRequest([], static fn () => null);

        return (new \ReflectionMethod($request, 'parseStreamChunk'))->invoke($request, $this->model, $chunk);
    }
}
