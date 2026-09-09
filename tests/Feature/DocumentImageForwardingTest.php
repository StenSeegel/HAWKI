<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\User;
use App\Services\AI\Providers\OpenAi\OpenAiRequestConverter;
use App\Services\AI\Value\AiModel;
use App\Services\Chat\Attachment\DocumentImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The file converter (>= 3.x with SAVE_DOCUMENT_IMAGE_REFS=true) leaves the
 * figures of a document as webp files next to the markdown chunks. Vision
 * models should receive them; text-only models and decorative images not.
 */
class DocumentImageForwardingTest extends TestCase
{
    use RefreshDatabase;

    private Attachment $attachment;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local_file_storage');
        config()->set('filesystems.file_storage', 'local_file_storage');
        config()->set('file_converter.document_images', [
            'enabled' => true,
            'max_per_document' => 3,
            'max_dimension' => 200,
            'min_dimension' => 50,
        ]);

        $user = User::factory()->create();
        $this->attachment = Attachment::create([
            'uuid' => 'abcd1234-0000-4000-8000-000000000001',
            'name' => 'report.pdf',
            'category' => 'documents',
            'type' => 'document',
            'mime' => 'application/pdf',
            'user_id' => $user->id,
        ]);
    }

    private function outputFolder(): string
    {
        return 'documents/a/b/c/d/' . $this->attachment->uuid . '/output';
    }

    private function writeOutput(string $name, string $contents): void
    {
        Storage::disk('local_file_storage')->put($this->outputFolder() . '/' . $name, $contents);
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function converterOutput(array $extraImages = []): void
    {
        $this->writeOutput('00001.md', "---\nfile: 00001.md\n---\n\n> [Image: ../assets/image_0.webp]\n\nHello");
        $this->writeOutput('meta.json', '{}');
        $this->writeOutput('image_0.webp', $this->png(400, 300));
        foreach ($extraImages as $name => $binary) {
            $this->writeOutput($name, $binary);
        }
    }

    public function test_collects_figures_in_document_order_downscaled_and_capped(): void
    {
        $this->converterOutput([
            'image_10.webp' => $this->png(120, 120),
            'image_2.webp' => $this->png(90, 90),
            'image_1.webp' => $this->png(150, 60),
            'image_3.webp' => $this->png(300, 300),
        ]);

        $images = app(DocumentImageService::class)->collect($this->attachment);

        // Natural order, capped at three: image_0, image_1, image_2 (not image_10 first, not image_3).
        $this->assertSame(['image_0.webp', 'image_1.webp', 'image_2.webp'], array_column($images, 'name'));

        // The 400x300 figure is downscaled to the configured 200px longest side.
        $size = getimagesizefromstring($images[0]['data']);
        $this->assertSame([200, 150], [$size[0], $size[1]]);
        $this->assertSame($size['mime'], $images[0]['mime']);

        // Small images are passed through untouched.
        $this->assertSame([150, 60], array_slice(getimagesizefromstring($images[1]['data']), 0, 2));
    }

    public function test_drops_decorative_images_below_min_dimension(): void
    {
        $this->converterOutput([
            'image_1.webp' => $this->png(30, 30),   // bullet / rule
            'image_2.webp' => $this->png(500, 20),  // horizontal rule
        ]);

        $images = app(DocumentImageService::class)->collect($this->attachment);

        $this->assertSame(['image_0.webp'], array_column($images, 'name'));
    }

    public function test_sends_a_repeated_image_only_once(): void
    {
        // A logo that the converter extracted from every page.
        $logo = $this->png(160, 80);
        $this->converterOutput([
            'image_1.webp' => $logo,
            'image_2.webp' => $logo,
            'image_3.webp' => $this->png(160, 90),
        ]);

        $images = app(DocumentImageService::class)->collect($this->attachment);

        $this->assertSame(['image_0.webp', 'image_1.webp', 'image_3.webp'], array_column($images, 'name'));
    }

    public function test_disabled_or_zero_cap_returns_nothing(): void
    {
        $this->converterOutput();

        config()->set('file_converter.document_images.enabled', false);
        $this->assertSame([], app(DocumentImageService::class)->collect($this->attachment));

        config()->set('file_converter.document_images.enabled', true);
        config()->set('file_converter.document_images.max_per_document', 0);
        $this->assertSame([], app(DocumentImageService::class)->collect($this->attachment));
    }

    public function test_openai_converter_appends_figures_only_for_vision_models(): void
    {
        $this->converterOutput();
        $attachmentsMap = [$this->attachment->uuid => $this->attachment];

        $format = function (AiModel $model) use ($attachmentsMap): array {
            $converter = app(OpenAiRequestConverter::class);
            $method = new \ReflectionMethod($converter, 'processAttachments');
            $content = [];
            $method->invokeArgs($converter, [[$this->attachment->uuid], $attachmentsMap, $model, &$content]);

            return $content;
        };

        $visionModel = $this->model(tools: ['file_upload' => true, 'vision' => true], input: ['text', 'image']);
        $content = $format($visionModel);

        $this->assertCount(3, $content, 'document text, figure note, one image');
        $this->assertSame('text', $content[0]['type']);
        $this->assertStringContainsString('[ATTACHED FILE: report.pdf]', $content[0]['text']);
        $this->assertStringContainsString('[FIGURES FROM report.pdf', $content[1]['text']);
        $this->assertStringContainsString('image_0.webp', $content[1]['text']);
        $this->assertSame('image_url', $content[2]['type']);
        $this->assertStringStartsWith('data:image/', $content[2]['image_url']['url']);

        $textModel = $this->model(tools: ['file_upload' => true, 'vision' => false], input: ['text']);
        $content = $format($textModel);

        $this->assertCount(1, $content, 'text-only models get the document text and nothing else');
        $this->assertSame('text', $content[0]['type']);
    }

    private function model(array $tools, array $input): AiModel
    {
        return new AiModel([
            'id' => 'test-model',
            'label' => 'Test',
            'input' => $input,
            'output' => ['text'],
            'tools' => $tools,
        ]);
    }
}
