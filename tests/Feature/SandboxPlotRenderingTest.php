<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\Providers\Responses\Request\ResponsesStreamingRequest;
use App\Services\AI\Tools\SandboxImages;
use App\Services\AI\Value\AiModel;
use App\Services\Chat\Attachment\AttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plots a code sandbox produced have to reach the chat as a picture.
 *
 * They were not: the native code interpreter's image output was turned into the
 * literal text "[image]", and code-exec-mcp writes its PNG as base64 into the
 * printed output, where the tool's 8000 character cap cut it in half - so the
 * model announced a chart, the message showed none, and thousands of tokens went
 * on truncated base64.
 */
class SandboxPlotRenderingTest extends TestCase
{
    use RefreshDatabase;

    /** A 1x1 PNG, complete and decodable, with the signature the extractor looks for. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /**
     * Records what it was asked to store, so the tests can assert on the base64
     * that arrived without touching the filesystem.
     *
     * @var array<int,array{data: string, size: string}>
     */
    private array $stored = [];

    private function images(): SandboxImages
    {
        $attachments = $this->createMock(AttachmentService::class);

        $attachments->method('storeFromBase64')->willReturnCallback(
            function (string $data, string $category, string $filename, string $size = 'medium') {
                $this->stored[] = ['data' => $data, 'size' => $size];

                return [
                    'url' => 'https://hawki.test/files/'.count($this->stored).'.png',
                    'uuid' => 'uuid-'.count($this->stored),
                    'mime' => 'image/png',
                    'name' => $filename,
                ];
            }
        );

        return new SandboxImages($attachments);
    }

    public function test_a_printed_data_uri_becomes_a_stored_image(): void
    {
        $images = $this->images();

        $cleaned = $images->extractFromText("Here is the plot:\ndata:image/png;base64,".self::PNG."\nDone.");

        $collected = $images->drain();

        $this->assertCount(1, $collected);
        $this->assertSame('https://hawki.test/files/1.png', $collected[0]['url']);
        $this->assertStringContainsString('Here is the plot:', $cleaned);
        $this->assertStringContainsString('Done.', $cleaned);
    }

    /**
     * The whole point of extracting early: what goes back to the model must not
     * contain the bytes, or the output cap corrupts the image and the context
     * pays for base64.
     */
    public function test_the_base64_never_stays_in_the_text_the_model_reads(): void
    {
        $images = $this->images();

        $cleaned = $images->extractFromText('data:image/png;base64,'.self::PNG);

        $this->assertStringNotContainsString('iVBORw0KGgo', $cleaned);
        $this->assertStringContainsString('image 1', $cleaned);
    }

    public function test_base64_without_a_data_uri_prefix_is_extracted_too(): void
    {
        // code-exec-mcp has been seen printing the bare base64.
        $images = $this->images();

        $cleaned = $images->extractFromText('plot: '.self::PNG);

        $this->assertCount(1, $images->drain());
        $this->assertStringNotContainsString('iVBORw0KGgo', $cleaned);
    }

    public function test_several_plots_in_one_run_are_all_kept(): void
    {
        $images = $this->images();

        $images->extractFromText(
            "first:\ndata:image/png;base64,".self::PNG."\nsecond:\ndata:image/png;base64,".self::PNG
        );

        $this->assertCount(2, $images->drain());
    }

    public function test_output_without_an_image_is_returned_untouched(): void
    {
        $images = $this->images();

        $this->assertSame('42', $images->extractFromText('42'));
        $this->assertSame([], $images->drain());
    }

    /**
     * A plot has its own aspect ratio. The size presets exist for the image
     * generation tool and the default arm squares anything unknown to 1024x1024,
     * which would stretch every chart.
     */
    public function test_a_plot_is_stored_at_its_own_size(): void
    {
        $images = $this->images();

        $images->extractFromText('data:image/png;base64,'.self::PNG);

        $this->assertSame('original', $this->stored[0]['size']);
    }

    public function test_draining_twice_does_not_repeat_an_image(): void
    {
        // The request drains after every tool call; a second call must not
        // re-emit the previous call's plot.
        $images = $this->images();

        $images->extractFromText('data:image/png;base64,'.self::PNG);

        $this->assertCount(1, $images->drain());
        $this->assertSame([], $images->drain());
    }

    /**
     * 'original' has to reach the attachment service as a no-op resize, or the
     * flag above means nothing.
     */
    public function test_the_attachment_service_leaves_an_original_sized_image_alone(): void
    {
        $service = app(AttachmentService::class);

        $resolve = new \ReflectionMethod($service, 'resolveImageGenerationDimension');
        $resolve->setAccessible(true);

        $this->assertSame(['width' => 0, 'height' => 0], $resolve->invoke($service, 'original'));
        $this->assertSame(['width' => 0, 'height' => 0], $resolve->invoke($service, 'original', '16:9'));

        // The presets themselves must keep working.
        $this->assertSame(['width' => 512, 'height' => 512], $resolve->invoke($service, 'small'));
    }

    /**
     * Runs one finished code_interpreter_call through the streaming handler and
     * returns the response it produced.
     */
    private function finishedCall(array $outputs): \App\Services\AI\Value\AiResponse
    {
        $attachments = $this->createMock(AttachmentService::class);
        $attachments->method('storeFromBase64')->willReturn([
            'url' => 'https://hawki.test/files/plot.png',
            'uuid' => 'plot-uuid',
            'mime' => 'image/png',
            'name' => 'plot.png',
        ]);
        $this->app->instance(SandboxImages::class, new SandboxImages($attachments));

        $request = new ResponsesStreamingRequest(['model' => 'test'], static function (): void {});

        $chunk = json_encode([
            'type' => 'response.output_item.done',
            'output_index' => 1,
            'item' => [
                'type' => 'code_interpreter_call',
                'status' => 'completed',
                'code' => "import matplotlib.pyplot as plt\nplt.plot([1,2])",
                'container_id' => 'cntr_test',
                'outputs' => $outputs,
            ],
        ]);

        $method = new \ReflectionMethod($request, 'chunkToResponse');
        $method->setAccessible(true);

        return $method->invoke($request, $this->createMock(AiModel::class), $chunk);
    }

    /**
     * A chart belongs under the code that drew it. The frontend's own image
     * container is inserted before .message-content, so a plot rendered through it
     * appears above the code and above the answer - which is where it was landing.
     */
    /**
     * The picture is placed by the frontend - where the model referenced it, or
     * under the code as the fallback - from the auxiliary's url. Writing it into
     * the text here as well showed every plot the model mentioned twice.
     */
    public function test_the_plot_is_announced_but_not_written_into_the_text(): void
    {
        $response = $this->finishedCall([
            ['type' => 'image', 'url' => 'data:image/png;base64,'.self::PNG],
        ]);

        $text = $response->content['text'];

        $this->assertStringContainsString('```python', $text);
        $this->assertStringNotContainsString('![Plot]', $text);
        $this->assertStringNotContainsString('https://hawki.test/files/plot.png', $text);

        $images = array_values(array_filter(
            $response->content['auxiliaries'],
            static fn (array $aux): bool => $aux['type'] === 'generated_image'
        ));
        $this->assertCount(1, $images);
        $this->assertSame('https://hawki.test/files/plot.png', json_decode($images[0]['content'], true)['url']);
    }

    /**
     * Both together would show the picture twice: once inline, once in the
     * container above the whole message.
     */
    public function test_the_plot_auxiliary_is_marked_inline_so_it_is_not_drawn_twice(): void
    {
        $response = $this->finishedCall([
            ['type' => 'image', 'url' => 'data:image/png;base64,'.self::PNG],
        ]);

        $images = array_values(array_filter(
            $response->content['auxiliaries'],
            static fn (array $aux) => $aux['type'] === 'generated_image'
        ));

        $this->assertCount(1, $images, 'The plot must still be announced, or the file is never linked to the message.');

        $image = json_decode($images[0]['content'], true);

        $this->assertTrue($image['inline'], 'Without this flag the frontend draws a second copy above the message.');
        $this->assertSame('plot-uuid', $image['uuid']);
    }

    public function test_the_frontend_skips_drawing_a_container_for_an_inline_plot(): void
    {
        $script = file_get_contents(public_path('js/syntax_modifier.js'));

        $this->assertStringContainsString('if (inline === true) {', $script);
        // ...and places the plot itself: at the model's reference, else under the code.
        $this->assertStringContainsString('function syncInlinePlots(', $script);
        $this->assertStringContainsString('rememberInlinePlot(messageElement, url, output_index)', $script);
    }

    public function test_a_call_that_printed_text_still_renders_its_output(): void
    {
        $response = $this->finishedCall([
            ['type' => 'logs', 'logs' => "42\n"],
        ]);

        $text = $response->content['text'];

        $this->assertStringContainsString("```output\n42\n```", $text);
        $this->assertStringNotContainsString('![Plot]', $text);
    }

    /**
     * The retry hint has to name the way out, or a round is wasted. Measured:
     * qwen3-coder-next answered the generic "print what should be returned" by
     * retrying the same bare expression, and a model that had called plt.show()
     * got no hint that an image needs the data URI route at all.
     */
    public function test_the_empty_output_hint_is_tailored_to_what_the_code_did(): void
    {
        $tool = app(\App\Services\AI\Tools\CodeInterpreterTool::class);

        $hint = new \ReflectionMethod($tool, 'emptyOutputHint');
        $hint->setAccessible(true);

        $plot = $hint->invoke($tool, "import matplotlib.pyplot as plt\nplt.plot([1,2])\nplt.show()");
        $this->assertStringContainsString('data:image/png;base64,', $plot);

        $plain = $hint->invoke($tool, "import hashlib\nhashlib.sha256(b'x').hexdigest()");
        $this->assertStringContainsString('print(', $plain);
        $this->assertStringNotContainsString('data:image', $plain);
    }

    /**
     * The convention is measured, not assumed: the sandbox working directory is
     * read-only, plt.show() produces nothing, and a printed data URI is the only
     * way an image gets out. A model that is not told this writes
     * savefig('plot.png') and the run fails.
     *
     * It is pinned in the argument schema rather than the awareness prompt
     * because the prompt is editable per installation and stored in app_settings.
     */
    public function test_the_model_is_told_how_to_return_an_image(): void
    {
        $description = app(\App\Services\AI\Tools\CodeInterpreterTool::class)
            ->getArgumentSchema()['properties']['code']['description'];

        $this->assertStringContainsString('data:image/png;base64,', $description);
        $this->assertStringContainsString('/tmp', $description);

        // Stated positively on purpose. Told only what does NOT work, a weaker
        // model concludes the environment cannot do images at all and answers
        // "I could not run the code with image output" - measured on gemma.
        $this->assertStringContainsString('supported', $description);
    }
}
