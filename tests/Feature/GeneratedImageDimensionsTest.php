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
}
