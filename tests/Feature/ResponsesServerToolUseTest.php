<?php

namespace Tests\Feature;

use App\Services\AI\Providers\Responses\Request\ResponsesRequest;
use App\Services\AI\Providers\Responses\Request\ResponsesStreamingRequest;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiResponse;
use App\Services\Chat\Attachment\AttachmentService;
use Tests\TestCase;

/**
 * Provider side tool calls on the OpenAI Responses path have to reach the usage
 * record, or the "tool use per provider" on the requests dashboard shows nothing
 * for OpenAI - which is what happened for image generation.
 */
class ResponsesServerToolUseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $attachments = $this->createMock(AttachmentService::class);
        $attachments->method('storeFromBase64')->willReturn([
            'url' => 'https://hawki.test/files/generated.png',
            'uuid' => 'generated-uuid',
            'mime' => 'image/png',
            'name' => 'generated.png',
        ]);
        $this->app->instance(AttachmentService::class, $attachments);
    }

    public function test_a_streamed_image_generation_is_counted_in_the_usage(): void
    {
        $request = new ResponsesStreamingRequest(['model' => 'gpt-5'], static function (): void {});

        $this->stream($request, [
            'type' => 'response.output_item.done',
            'output_index' => 1,
            'item' => ['type' => 'image_generation_call', 'status' => 'completed', 'result' => base64_encode('png')],
        ]);
        $this->stream($request, [
            'type' => 'response.output_item.done',
            'output_index' => 2,
            'item' => ['type' => 'image_generation_call', 'status' => 'completed', 'result' => base64_encode('png')],
        ]);
        $response = $this->stream($request, [
            'type' => 'response.completed',
            'response' => ['id' => 'resp_1', 'usage' => $this->usage()],
        ]);

        $this->assertNotNull($response->usage);
        $this->assertSame(['image_generation' => 2], $response->usage->serverToolUse);
        $this->assertSame(10, $response->usage->promptTokens);
    }

    public function test_a_stream_without_tool_calls_reports_no_tool_use(): void
    {
        $request = new ResponsesStreamingRequest(['model' => 'gpt-5'], static function (): void {});

        $response = $this->stream($request, [
            'type' => 'response.completed',
            'response' => ['id' => 'resp_1', 'usage' => $this->usage()],
        ]);

        $this->assertNotNull($response->usage);
        $this->assertNull($response->usage->serverToolUse);
    }

    /**
     * Group chats answer without streaming; their tool calls count as well.
     */
    public function test_a_non_streamed_response_counts_every_tool_call(): void
    {
        $request = new ResponsesRequest(['model' => 'gpt-5']);

        $method = new \ReflectionMethod($request, 'dataToResponse');
        $method->setAccessible(true);

        $response = $method->invoke($request, [
            'id' => 'resp_1',
            'output' => [
                ['type' => 'web_search_call', 'status' => 'completed', 'action' => ['type' => 'search', 'query' => 'hawki']],
                ['type' => 'image_generation_call', 'status' => 'completed', 'result' => base64_encode('png')],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Here you go.']]],
            ],
            'usage' => $this->usage(),
        ], $this->createMock(AiModel::class));

        $this->assertNotNull($response->usage);
        $this->assertSame(['web_search' => 1, 'image_generation' => 1], $response->usage->serverToolUse);
    }

    private function stream(ResponsesStreamingRequest $request, array $chunk): AiResponse
    {
        $method = new \ReflectionMethod($request, 'chunkToResponse');
        $method->setAccessible(true);

        return $method->invoke($request, $this->createMock(AiModel::class), json_encode($chunk));
    }

    private function usage(): array
    {
        return ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15];
    }
}
