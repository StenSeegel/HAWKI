<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class GalleryDownloadButtonTest extends TestCase
{
    private function renderGallery(): string
    {
        // The real key set, so adding markup to the modal cannot break this.
        $translation = json_decode(file_get_contents(resource_path('language/en_US.json')), true);

        return Blade::render(
            file_get_contents(resource_path('views/partials/home/modals/image-gallery-modal.blade.php')),
            ['translation' => $translation]
        );
    }

    public function test_the_gallery_has_a_download_button(): void
    {
        $html = $this->renderGallery();

        $this->assertStringContainsString('id="gallery-download-btn"', $html);
        $this->assertStringContainsString('onclick="downloadImage(this)"', $html);
        $this->assertStringContainsString('title="Download"', $html);
    }

    public function test_the_arrow_points_down(): void
    {
        $html = $this->renderGallery();

        // download.svg: chevron pointing down, plus a stem running top to bottom.
        $this->assertStringContainsString('polyline points="7 10 12 15 17 10"', $html);
        $this->assertStringContainsString('line x1="12" y1="15" x2="12" y2="3"', $html);

        // Not the share glyph, whose arrow points up.
        $this->assertStringNotContainsString('feather-upload', $html);
        $this->assertStringNotContainsString('feather-share', $html);
    }

    public function test_there_is_no_edit_button(): void
    {
        $html = $this->renderGallery();

        $this->assertStringNotContainsString('feather-edit', $html);
        $this->assertStringNotContainsString('editMessage', $html);
        $this->assertStringNotContainsString('Bearbeiten', $html);
    }

    public function test_the_button_sits_inside_the_frame_that_wraps_the_image(): void
    {
        $html = $this->renderGallery();

        // The frame hugs the picture, so the button lands on it and not in the
        // empty part of the grid track.
        $frameAt = strpos($html, 'gallery-image-frame');
        $imageAt = strpos($html, 'id="gallery-image"');
        $buttonAt = strpos($html, 'id="gallery-download-btn"');

        $this->assertNotFalse($frameAt);
        $this->assertLessThan($imageAt, $frameAt);
        $this->assertLessThan($buttonAt, $imageAt);
    }

    public function test_the_handler_and_the_frame_styles_exist(): void
    {
        $js = file_get_contents(public_path('js/message_functions.js'));
        $this->assertStringContainsString('async function downloadImage(button)', $js);
        $this->assertStringContainsString('link.download = downloadImageFileName(image.src)', $js);

        $css = file_get_contents(public_path('css/chat_modules.css'));
        $this->assertStringContainsString('#image-gallery-modal .gallery-image-frame', $css);
        $this->assertStringContainsString('.image-download-btn {', $css);
    }

    public function test_the_shared_template_carries_the_download_glyph(): void
    {
        $html = Blade::render(
            file_get_contents(resource_path('views/partials/home/templates/image-download-btn-template.blade.php')),
            ['translation' => json_decode(file_get_contents(resource_path('language/en_US.json')), true)]
        );

        $this->assertStringContainsString('id="image-download-btn-template"', $html);
        $this->assertStringContainsString('class="image-download-btn"', $html);
        $this->assertStringContainsString('onclick="downloadImage(this)"', $html);
        $this->assertStringContainsString('polyline points="7 10 12 15 17 10"', $html);
    }

    public function test_the_chat_log_image_gets_the_button_in_a_frame(): void
    {
        $js = file_get_contents(public_path('js/syntax_modifier.js'));

        // The renderer wraps the picture and then hangs the button on the frame.
        $this->assertStringContainsString('class="generated-image-frame"', $js);
        $this->assertStringContainsString(
            "addImageDownloadButton(imageContainer.querySelector('.generated-image-frame'))",
            $js
        );

        $frameAt = strpos($js, 'class="generated-image-frame"');
        $imageAt = strpos($js, 'class="generated-image" data-uuid');
        $this->assertNotFalse($frameAt);
        $this->assertNotFalse($imageAt);
        $this->assertLessThan($imageAt, $frameAt);

        $messages = file_get_contents(public_path('js/message_functions.js'));
        $this->assertStringContainsString('function addImageDownloadButton(frame)', $messages);

        $css = file_get_contents(public_path('css/chat_modules.css'));
        $this->assertStringContainsString('.generated-image-frame {', $css);
    }
}
