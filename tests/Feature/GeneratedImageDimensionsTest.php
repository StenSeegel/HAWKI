<?php

namespace Tests\Feature;

use App\Services\Chat\Attachment\AttachmentService;
use Tests\TestCase;

class GeneratedImageDimensionsTest extends TestCase
{
    private function resolve(string $preset, ?string $ratio = null): array
    {
        $method = new \ReflectionMethod(AttachmentService::class, 'resolveImageGenerationDimension');

        return $method->invoke(app(AttachmentService::class), $preset, $ratio);
    }

    public function test_the_preset_sets_the_longest_edge_and_the_ratio_the_shape(): void
    {
        // medium => 1024 on the long edge
        $this->assertSame(['width' => 1024, 'height' => 576], $this->resolve('medium', '16:9'));
        $this->assertSame(['width' => 576, 'height' => 1024], $this->resolve('medium', '9:16'));
        $this->assertSame(['width' => 1024, 'height' => 768], $this->resolve('medium', '4:3'));
        $this->assertSame(['width' => 768, 'height' => 1024], $this->resolve('medium', '3:4'));
        $this->assertSame(['width' => 1024, 'height' => 1024], $this->resolve('medium', '1:1'));
    }

    public function test_each_preset_scales_the_same_ratio(): void
    {
        $this->assertSame(['width' => 512, 'height' => 288], $this->resolve('small', '16:9'));
        $this->assertSame(['width' => 1024, 'height' => 576], $this->resolve('medium', '16:9'));
        $this->assertSame(['width' => 1536, 'height' => 864], $this->resolve('big', '16:9'));
    }

    public function test_without_a_ratio_the_presets_keep_their_old_dimensions(): void
    {
        $this->assertSame(['width' => 512, 'height' => 512], $this->resolve('small'));
        $this->assertSame(['width' => 1024, 'height' => 1024], $this->resolve('medium'));
        $this->assertSame(['width' => 1536, 'height' => 1024], $this->resolve('big'));
    }

    public function test_a_malformed_ratio_falls_back_to_the_preset(): void
    {
        $this->assertSame(['width' => 1024, 'height' => 1024], $this->resolve('medium', 'nonsense'));
        $this->assertSame(['width' => 1024, 'height' => 1024], $this->resolve('medium', '0:0'));
        $this->assertSame(['width' => 1536, 'height' => 1024], $this->resolve('big', ''));
    }

    /**
     * A picture from the provider's image tool is stored at the resolution the
     * API returned. The gallery's ratio still reshapes it, on its own long edge.
     */
    public function test_an_original_sized_image_keeps_its_resolution_and_takes_the_ratio(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not available.');
        }

        $method = new \ReflectionMethod(AttachmentService::class, 'resizeGeneratedImage');
        $service = app(AttachmentService::class);

        $source = imagecreatetruecolor(1536, 1024);
        ob_start();
        imagepng($source);
        $png = ob_get_clean();
        imagedestroy($source);

        $kept = $method->invoke($service, $png, 'image/png', 'original', null);
        $this->assertSame([1536, 1024], array_slice(getimagesizefromstring($kept), 0, 2));

        $reshaped = $method->invoke($service, $png, 'image/png', 'original', '16:9');
        $this->assertSame([1536, 864], array_slice(getimagesizefromstring($reshaped), 0, 2));

        $portrait = $method->invoke($service, $png, 'image/png', 'original', '9:16');
        $this->assertSame([864, 1536], array_slice(getimagesizefromstring($portrait), 0, 2));
    }

    /**
     * The OpenAI tool picks the shape from the prompt and returns at least 1024
     * pixels; "small" scales that down to a 512 long edge without squaring it.
     */
    public function test_a_preset_scales_the_picture_down_and_keeps_the_shape_the_api_chose(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not available.');
        }

        $method = new \ReflectionMethod(AttachmentService::class, 'resizeGeneratedImage');
        $service = app(AttachmentService::class);

        $landscape = $this->png(1536, 1024);
        $this->assertSame([512, 341], $this->dimensionsOf($method->invoke($service, $landscape, 'image/png', 'small', null)));

        $portrait = $this->png(1024, 1536);
        $this->assertSame([341, 512], $this->dimensionsOf($method->invoke($service, $portrait, 'image/png', 'small', null)));

        $square = $this->png(1024, 1024);
        $this->assertSame([512, 512], $this->dimensionsOf($method->invoke($service, $square, 'image/png', 'small', null)));

        // A gallery ratio still wins over the source's shape.
        $this->assertSame([512, 288], $this->dimensionsOf($method->invoke($service, $square, 'image/png', 'small', '16:9')));
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return ob_get_clean();
    }

    private function dimensionsOf(string $png): array
    {
        return array_slice(getimagesizefromstring($png), 0, 2);
    }
}
