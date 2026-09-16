<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\User;
use App\Services\Chat\Attachment\AttachmentService;
use App\Services\Chat\Attachment\SvgRasterizer;
use App\Services\Storage\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * An SVG reaches a model as its markup plus a rendered PNG.
 *
 * Sent as it is, the drawing ended the whole request: vLLM hands an image_url
 * to Pillow, which cannot read SVG and answers 400 "cannot identify image
 * file". The markup is text a model reads well, and the PNG is the picture.
 */
class SvgAttachmentForModelTest extends TestCase
{
    use RefreshDatabase;

    private const DRAWING = '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="40" viewBox="0 0 80 40">'
        .'<rect x="5" y="5" width="70" height="30" fill="#0af"/><text x="10" y="25">Ablauf</text></svg>';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local_file_storage');
        config()->set('filesystems.file_storage', 'local_file_storage');
    }

    private function attachment(string $name, string $mime, string $bytes): Attachment
    {
        $user = User::factory()->create();
        $uuid = (string) \Illuminate\Support\Str::uuid();

        app(FileStorageService::class)->store(
            file: $bytes,
            filename: $name,
            uuid: $uuid,
            category: 'private',
            temp: false,
        );

        return Attachment::create([
            'uuid' => $uuid,
            'name' => $name,
            'category' => 'private',
            'type' => 'image',
            'mime' => $mime,
            'user_id' => $user->id,
        ]);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    public function test_a_raster_image_is_still_inlined_as_itself(): void
    {
        $bytes = $this->png();
        $attachment = $this->attachment('photo.png', 'image/png', $bytes);

        $parts = app(AttachmentService::class)->imagePartsForModel($attachment);

        $this->assertSame('[ATTACHED IMAGE: photo.png]', $parts['label']);
        $this->assertSame('image/png', $parts['mime']);
        $this->assertSame(base64_encode($bytes), $parts['base64']);
    }

    public function test_a_drawing_carries_its_source_in_the_text(): void
    {
        $attachment = $this->attachment('flow.svg', 'image/svg+xml', self::DRAWING);

        $parts = app(AttachmentService::class)->imagePartsForModel($attachment);

        $this->assertStringContainsString('[ATTACHED IMAGE: flow.svg]', $parts['label']);
        $this->assertStringContainsString('```svg', $parts['label']);
        $this->assertStringContainsString('Ablauf', $parts['label'], 'the model must be able to read the labels');
    }

    /**
     * The point of the whole change: whatever is inlined as a picture, it is
     * never the SVG - that is the byte string the gateway refuses.
     */
    public function test_a_drawing_is_never_inlined_as_an_svg(): void
    {
        $attachment = $this->attachment('flow.svg', 'image/svg+xml', self::DRAWING);

        $parts = app(AttachmentService::class)->imagePartsForModel($attachment);

        $this->assertNotSame('image/svg+xml', $parts['mime']);

        if ($parts['base64'] === null) {
            // No renderer in this environment: the markup has to stand alone.
            $this->assertStringContainsString('could not be rendered', $parts['label']);

            return;
        }

        $this->assertSame('image/png', $parts['mime']);
        $this->assertStringStartsWith("\x89PNG", (string) base64_decode($parts['base64'], true));
    }

    public function test_the_renderer_is_not_sent_anything_that_points_outside_the_file(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="10" height="10">'
            .'<image xlink:href="http://169.254.169.254/latest/meta-data/" x="0" y="0"/>'
            .'<image href="file:///etc/passwd" x="0" y="0"/>'
            .'<image href="data:image/png;base64,AAAA" x="0" y="0"/>'
            .'<use href="#shape"/></svg>';

        $stripped = SvgRasterizer::stripExternalReferences($svg);

        $this->assertStringNotContainsString('169.254.169.254', $stripped);
        $this->assertStringNotContainsString('/etc/passwd', $stripped);
        $this->assertStringContainsString('data:image/png;base64,AAAA', $stripped, 'an embedded picture is the file\'s own content');
        $this->assertStringContainsString('#shape', $stripped, 'a fragment points inside the file');
    }

    /**
     * A picture saved under an .svg name - the browser then declares
     * image/svg+xml from the extension, and the row keeps that. Read as a
     * drawing, its bytes would go into the prompt as text, and one invalid
     * UTF-8 byte there empties the whole request body.
     */
    public function test_a_picture_that_only_claims_to_be_a_drawing_is_sent_as_the_picture_it_is(): void
    {
        $webp = base64_decode('UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAQAcJaQAA3AA/vuUAAA=', true);
        $attachment = $this->attachment('logo.svg', 'image/svg+xml', (string) $webp);

        $parts = app(AttachmentService::class)->imagePartsForModel($attachment);

        $this->assertSame('[ATTACHED IMAGE: logo.svg]', $parts['label']);
        $this->assertStringNotContainsString('```svg', $parts['label'], 'binary must never reach the prompt as text');
        $this->assertSame('image/webp', $parts['mime'], 'the bytes decide what the picture is');
        $this->assertSame(base64_encode((string) $webp), $parts['base64']);
        $this->assertTrue(mb_check_encoding(json_encode($parts['label']) ?: '', 'UTF-8'));
    }

    public function test_a_drawing_that_is_not_well_formed_is_not_rendered(): void
    {
        $this->assertNull(SvgRasterizer::toPng('<svg><rect></svg>'));
    }

    public function test_the_svg_mime_is_recognised_with_a_charset(): void
    {
        $this->assertTrue(SvgRasterizer::isSvg('image/svg+xml; charset=utf-8'));
        $this->assertFalse(SvgRasterizer::isSvg('image/png'));
    }
}
