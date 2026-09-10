<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\Providers\OpenAiHawkiTools\Request\OpenAiHawkiToolsStreamingRequest;
use App\Services\AI\Tools\CodeInterpreterTool;
use App\Services\AI\Tools\ImageGenerationTool;
use App\Services\AI\Tools\SandboxImages;
use App\Services\AI\Tools\ToolCallRunner;
use App\Services\AI\Value\AiResponse;
use App\Services\Chat\Attachment\AttachmentService;
use Tests\TestCase;

/**
 * Which frontend component a picture from a HAWKI tool lands in.
 *
 * The two kinds of image need opposite treatment, and getting it wrong is
 * visible: a generated image went out the way a sandbox plot does - markdown in
 * the message text plus 'inline' on the auxiliary - which makes the frontend
 * skip its image container, so the picture appeared as a bare markdown image
 * instead of in the frame with the prompt caption, the download button and the
 * offer to attach it to the next message, the way the provider side image tool
 * renders it.
 */
class HawkiToolsImageRenderingTest extends TestCase
{
    /** A 1x1 PNG, complete and decodable. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        // The image store, stubbed: the shapes are what this test is about, not
        // the filesystem or the attachments table.
        $attachments = $this->createMock(AttachmentService::class);
        $attachments->method('storeFromBase64')->willReturnCallback(
            static fn (string $data, string $category, string $filename) => [
                'url' => 'https://hawki.test/files/'.$filename,
                'uuid' => 'uuid-'.$filename,
                'mime' => 'image/png',
                'name' => $filename,
            ]
        );

        $this->app->instance(SandboxImages::class, new SandboxImages($attachments));
    }

    /**
     * Runs the emission for one tool and returns what it sent to the client.
     */
    private function emitFor(string $tool): AiResponse
    {
        $sent = null;

        $request = new OpenAiHawkiToolsStreamingRequest(
            ['model' => 'jlu/qwen3.8-27b', 'messages' => []],
            function (AiResponse $response) use (&$sent): void {
                $sent = $response;
            },
            [],
            app(ToolCallRunner::class),
        );

        (function () use ($tool): void {
            $this->emitToolImages($tool);
        })->call($request);

        return $sent ?? new AiResponse(content: []);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function auxiliaryImages(AiResponse $response): array
    {
        $images = [];

        foreach ($response->content['auxiliaries'] ?? [] as $auxiliary) {
            if ($auxiliary['type'] === 'generated_image') {
                $images[] = json_decode($auxiliary['content'], true);
            }
        }

        return $images;
    }

    public function test_a_generated_image_goes_into_the_image_container(): void
    {
        app(SandboxImages::class)->collect(self::PNG, 'a red bicycle');

        $response = $this->emitFor(ImageGenerationTool::KEY);
        $images = $this->auxiliaryImages($response);

        $this->assertCount(1, $images);

        // No 'inline', so the frontend draws its container - the component the
        // provider side image tool renders into.
        $this->assertArrayNotHasKey('inline', $images[0]);
        $this->assertSame(0, $images[0]['output_index']);
        $this->assertSame('a red bicycle', $images[0]['prompt']);

        // And no markdown copy of the picture in the message text.
        $this->assertSame('', $response->content['text'] ?? '');
    }

    public function test_a_sandbox_plot_stays_inline_next_to_the_code(): void
    {
        app(SandboxImages::class)->extractFromText('data:image/png;base64,'.self::PNG);

        $response = $this->emitFor(CodeInterpreterTool::KEY);
        $images = $this->auxiliaryImages($response);

        $this->assertCount(1, $images);
        $this->assertTrue($images[0]['inline']);
        $this->assertStringContainsString('!['.$images[0]['prompt'].']('.$images[0]['url'].')', $response->content['text']);
    }

    /**
     * The frontend keys the container by output_index, so two images in one
     * message need two indices or the second replaces the first.
     */
    public function test_every_image_of_a_message_gets_its_own_index(): void
    {
        $sent = [];

        $request = new OpenAiHawkiToolsStreamingRequest(
            ['model' => 'jlu/qwen3.8-27b', 'messages' => []],
            function (AiResponse $response) use (&$sent): void {
                $sent[] = $response;
            },
            [],
            app(ToolCallRunner::class),
        );

        $emit = (function (string $tool): void {
            $this->emitToolImages($tool);
        });

        app(SandboxImages::class)->collect(self::PNG, 'first');
        $emit->call($request, ImageGenerationTool::KEY);

        app(SandboxImages::class)->collect(self::PNG, 'second');
        $emit->call($request, ImageGenerationTool::KEY);

        $indices = array_merge(
            ...array_map(fn (AiResponse $r) => array_column($this->auxiliaryImages($r), 'output_index'), $sent)
        );

        $this->assertSame([0, 1], $indices);
    }

    public function test_nothing_is_sent_when_a_tool_produced_no_image(): void
    {
        $response = $this->emitFor(ImageGenerationTool::KEY);

        $this->assertSame([], $this->auxiliaryImages($response));
    }
}
