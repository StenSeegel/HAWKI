<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\User;
use App\Services\AI\Tools\HawkiToolRegistry;
use App\Services\AI\Tools\ImageGenerationTool;
use App\Services\AI\Tools\SandboxImages;
use App\Services\AI\Tools\ToolCallRunner;
use App\Services\AI\Utils\MessageAttachmentFinder;
use App\Services\Chat\Attachment\AttachmentService;
use App\Services\Mcp\McpClient;
use App\Services\Mcp\McpServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Image generation served through an MCP server, for providers with no image
 * tool of their own (the ki@JLU gateway, which fronts image_mcp on the Spark).
 *
 * The picture arrives as an MCP image block rather than as text, which is the
 * part that needed care: the client used to keep only the text parts of a tool
 * result, so the bytes were dropped and a finished image looked like a tool that
 * had answered with nothing but its status line.
 */
class ImageGenerationToolTest extends TestCase
{
    use RefreshDatabase;

    private const MCP_URL = 'https://images.test/mcp';

    /** A 1x1 PNG, complete and decodable. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** @var array<int,array{data: string, filename: string, size: string}> */
    private array $stored = [];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        config([
            'hawki_tools.mcp_servers' => [
                'image-mcp' => ['url' => self::MCP_URL, 'requires_session' => false, 'timeout' => 320],
            ],
            'hawki_tools.bindings.image_generation' => [
                'server' => 'image-mcp',
                'tools' => ['generate' => 'generate_image', 'edit' => 'edit_image'],
            ],
        ]);
    }

    /**
     * The tool with a stubbed attachment store, so a test asserts on the base64
     * that arrived without touching the filesystem or the attachments table.
     *
     * @param  ?string  $sourceBytes  The bytes of an attached image to edit, if the
     *                                test wants the conversation to have one.
     */
    private function tool(?string $sourceBytes = null, string $type = 'image'): array
    {
        $attachments = $this->createMock(AttachmentService::class);
        $attachments->method('storeFromBase64')->willReturnCallback(
            function (string $data, string $category, string $filename, string $size = 'medium') {
                $this->stored[] = ['data' => $data, 'filename' => $filename, 'size' => $size];

                return [
                    'url' => 'https://hawki.test/files/'.count($this->stored).'.png',
                    'uuid' => 'uuid-'.count($this->stored),
                    'mime' => 'image/png',
                    'name' => $filename,
                ];
            }
        );

        if ($sourceBytes !== null) {
            // The real lookup runs, so the attachment has to exist the way the
            // frontend leaves it behind: a row, referenced by uuid on the message.
            Attachment::create([
                'uuid' => 'source-uuid',
                'name' => 'source.png',
                'category' => 'private',
                'type' => $type,
                'mime' => 'image/png',
                'user_id' => $this->user->id,
            ]);

            $attachments->method('retrieve')->willReturn($sourceBytes);
        }

        $images = new SandboxImages($attachments);

        $tool = new ImageGenerationTool(
            app(McpClient::class),
            app(McpServerRegistry::class),
            $images,
            app(MessageAttachmentFinder::class),
            $attachments,
        );

        // What the frontend sends: the attached image travels as a uuid on the
        // newest message, never as data.
        $tool->configureForRequest([
            'messages' => [
                ['role' => 'user', 'content' => ['text' => 'change it', 'attachments' => ['source-uuid']]],
            ],
        ]);

        return [$tool, $images];
    }

    private function fakeImage(string $status = '{"status":"completed","seed":1}'): void
    {
        Http::fake([self::MCP_URL => Http::response(
            'data: '.json_encode(['result' => ['content' => [
                ['type' => 'text', 'text' => $status],
                ['type' => 'image', 'data' => self::PNG, 'mimeType' => 'image/png'],
            ]]])."\n",
            200
        )]);
    }

    public function test_the_prompt_reaches_the_bound_mcp_tool(): void
    {
        $this->fakeImage();
        [$tool] = $this->tool();

        $tool->execute(['prompt' => 'a red bicycle']);

        $call = Http::recorded()[0][0]->data();
        $this->assertSame('tools/call', $call['method']);
        $this->assertSame('generate_image', $call['params']['name']);
        $this->assertSame('a red bicycle', $call['params']['arguments']['prompt']);
    }

    /**
     * The bytes are stored and handed to the request as a picture; the model gets
     * a note. A megabyte of base64 in the conversation would fill the context
     * window and buy nothing - the model cannot look at the image.
     */
    public function test_the_image_is_stored_and_the_model_only_told_about_it(): void
    {
        $this->fakeImage();
        [$tool, $images] = $this->tool();

        $result = $tool->execute(['prompt' => 'a red bicycle']);

        $this->assertStringNotContainsString(self::PNG, $result);
        $this->assertStringNotContainsString('iVBORw0KGgo', $result);
        $this->assertStringContainsString('shown to the user', $result);

        $collected = $images->drain();
        $this->assertCount(1, $collected);
        $this->assertSame('https://hawki.test/files/1.png', $collected[0]['url']);

        // The prompt becomes the alt text of the picture in the message.
        $this->assertSame('a red bicycle', $collected[0]['prompt']);

        $this->assertSame(self::PNG, $this->stored[0]['data']);
        // A generated image keeps its own dimensions; the presets would square it.
        $this->assertSame('original', $this->stored[0]['size']);
    }

    /**
     * The chat UI has no size buttons: a request that names no format comes out
     * as a small square, whatever the payload may still carry from older clients.
     */
    public function test_without_a_format_the_image_is_a_small_square(): void
    {
        $this->fakeImage();
        [$tool] = $this->tool();
        $tool->configureForRequest(['image_generation_size' => 'big']);

        $tool->execute(['prompt' => 'a red bicycle']);

        $arguments = Http::recorded()[0][0]->data()['params']['arguments'];
        $this->assertSame(512, $arguments['width']);
        $this->assertSame(512, $arguments['height']);
    }

    /**
     * Size and shape are the model's call, read out of the user's prompt.
     */
    public function test_the_dimensions_the_model_asks_for_are_used(): void
    {
        $this->fakeImage();
        [$tool] = $this->tool();
        $tool->configureForRequest(['image_generation_ratio' => '1:1']);

        $tool->execute(['prompt' => 'a wide banner', 'width' => 1024, 'height' => 576]);

        $arguments = Http::recorded()[0][0]->data()['params']['arguments'];
        $this->assertSame(1024, $arguments['width']);
        $this->assertSame(576, $arguments['height']);
    }

    public function test_a_ratio_changes_the_shape_and_keeps_the_long_edge(): void
    {
        $this->fakeImage();
        [$tool] = $this->tool();
        $tool->configureForRequest(['image_generation_ratio' => '16:9']);

        $tool->execute(['prompt' => 'a wide landscape']);

        $arguments = Http::recorded()[0][0]->data()['params']['arguments'];
        $this->assertSame(512, $arguments['width']);
        // 512 * 9 / 16 = 288, and every edge stays a multiple of 16.
        $this->assertSame(288, $arguments['height']);
        $this->assertSame(0, $arguments['height'] % 16);
    }

    public function test_dimensions_the_model_asks_for_are_clamped_to_what_the_server_accepts(): void
    {
        $this->fakeImage();
        [$tool] = $this->tool();

        $tool->execute(['prompt' => 'a poster', 'width' => 5000, 'height' => 30]);

        $arguments = Http::recorded()[0][0]->data()['params']['arguments'];
        $this->assertSame(2048, $arguments['width']);
        $this->assertSame(256, $arguments['height']);
    }

    public function test_one_edge_from_the_model_means_a_square(): void
    {
        $this->fakeImage();
        [$tool] = $this->tool();

        $tool->execute(['prompt' => 'a square', 'width' => 768]);

        $arguments = Http::recorded()[0][0]->data()['params']['arguments'];
        $this->assertSame(768, $arguments['width']);
        $this->assertSame(768, $arguments['height']);
    }

    /**
     * A server that answers with a status line but no image has usually refused
     * the prompt, so its text is what the model needs to see.
     */
    public function test_a_result_without_an_image_is_reported_to_the_model(): void
    {
        Http::fake([self::MCP_URL => Http::response(
            'data: '.json_encode(['result' => ['content' => [
                ['type' => 'text', 'text' => '{"status":"rejected"}'],
            ]]])."\n",
            200
        )]);
        [$tool] = $this->tool();

        $result = app(ToolCallRunner::class)->run(
            ['image_generation' => $tool],
            'image_generation',
            '{"prompt":"something"}'
        );

        $this->assertStringStartsWith('Error:', $result);
        $this->assertStringContainsString('no image', $result);
        $this->assertStringContainsString('rejected', $result);
    }

    public function test_an_unbound_server_is_reported_to_the_model(): void
    {
        config(['hawki_tools.mcp_servers' => []]);
        [$tool] = $this->tool();

        $result = app(ToolCallRunner::class)->run(
            ['image_generation' => $tool],
            'image_generation',
            '{"prompt":"something"}'
        );

        $this->assertStringContainsString('not bound to a reachable MCP server', $result);
    }

    public function test_an_empty_prompt_is_rejected(): void
    {
        [$tool] = $this->tool();

        $result = app(ToolCallRunner::class)->run(
            ['image_generation' => $tool],
            'image_generation',
            '{"prompt":"   "}'
        );

        $this->assertStringStartsWith('Error:', $result);
    }

    public function test_the_function_definition_declares_a_prompt_argument(): void
    {
        [$tool] = $this->tool();
        $definition = $tool->getDefinition();

        $this->assertSame('image_generation', $definition['function']['name']);
        $this->assertSame(['prompt'], $definition['function']['parameters']['required']);
        $this->assertNotEmpty($definition['function']['description']);
    }

    /**
     * The chat UI attaches the image the user acted on and switches image
     * generation on - the gallery's "remove background" and "change size" tools do
     * exactly that. So an attached image means the call is an edit, and the bytes
     * come from the conversation because the model does not have them.
     */
    public function test_an_attached_image_is_edited_rather_than_replaced(): void
    {
        $this->fakeImage();
        [$tool] = $this->tool('the-png-bytes');

        $result = $tool->execute(['prompt' => 'Remove the background; keep the bicycle unchanged.']);

        $call = Http::recorded()[0][0]->data();
        $this->assertSame('edit_image', $call['params']['name']);
        $this->assertSame('Remove the background; keep the bicycle unchanged.', $call['params']['arguments']['instruction']);
        $this->assertSame(base64_encode('the-png-bytes'), $call['params']['arguments']['image_base64']);

        // The editor decides the dimensions; the size buttons do not apply.
        $this->assertArrayNotHasKey('width', $call['params']['arguments']);
        $this->assertStringContainsString('edited', $result);
    }

    public function test_the_model_can_ask_for_a_new_image_although_one_is_attached(): void
    {
        $this->fakeImage();
        [$tool] = $this->tool('the-png-bytes');

        $tool->execute(['prompt' => 'a lighthouse at night', 'edit' => false]);

        $call = Http::recorded()[0][0]->data();
        $this->assertSame('generate_image', $call['params']['name']);
        $this->assertSame('a lighthouse at night', $call['params']['arguments']['prompt']);
    }

    /**
     * A document among the attachments is not something the editor can work on, so
     * the call stays a generation instead of failing.
     */
    public function test_a_non_image_attachment_does_not_turn_the_call_into_an_edit(): void
    {
        $this->fakeImage();
        [$tool] = $this->tool('a pdf', 'document');

        $tool->execute(['prompt' => 'a lighthouse']);

        $this->assertSame('generate_image', Http::recorded()[0][0]->data()['params']['name']);
    }

    public function test_an_image_too_large_to_edit_is_reported_to_the_model(): void
    {
        $this->fakeImage();
        [$tool] = $this->tool(str_repeat('x', 9 * 1024 * 1024));

        $result = app(ToolCallRunner::class)->run(
            ['image_generation' => $tool],
            'image_generation',
            '{"prompt":"remove the background"}'
        );

        $this->assertStringContainsString('too large to edit', $result);
        // Nothing was sent: the limit is the server's, so the call is not worth making.
        $this->assertCount(0, Http::recorded());
    }

    /**
     * The capability is called 'image_gen' in the model settings and the model
     * library, and 'image_generation' everywhere the tool is named. Without the
     * mapping the tool is never offered to any model, however it is configured.
     */
    public function test_the_tool_uses_the_model_capability_the_settings_know(): void
    {
        $registry = app(HawkiToolRegistry::class);

        $this->assertSame('image_gen', $registry->modelFlag('image_generation'));
        $this->assertSame('web_search', $registry->modelFlag('web_search'));
        $this->assertTrue($registry->isImplemented('image_generation'));
    }
}
