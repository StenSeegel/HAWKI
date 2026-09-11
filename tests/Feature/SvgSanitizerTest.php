<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Chat\Attachment\AttachmentService;
use App\Services\Chat\Attachment\SvgSanitizer;
use App\Services\Storage\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A stored SVG is served from HAWKI's own origin as image/svg+xml. Opened in a
 * tab, a script inside it would run with the user's session - so nothing that
 * can run survives storage, and the file route says so again with a CSP.
 */
class SvgSanitizerTest extends TestCase
{
    use RefreshDatabase;

    private const DRAWING = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><circle cx="5" cy="5" r="4" fill="#f00"/></svg>';

    public function test_a_plain_drawing_keeps_its_content(): void
    {
        $clean = SvgSanitizer::sanitize(self::DRAWING);

        $this->assertNotNull($clean);
        $this->assertStringContainsString('<circle cx="5" cy="5" r="4" fill="#f00"/>', $clean);
        $this->assertStringContainsString('width="10"', $clean);
    }

    public function test_scripts_handlers_and_javascript_links_are_dropped(): void
    {
        $hostile = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" onload="alert(1)">'
            .'<script>alert(2)</script>'
            .'<a xlink:href="java&#10;script:alert(3)"><text x="1" y="9">click</text></a>'
            .'<foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><img src="x" onerror="alert(4)"/></body></foreignObject>'
            .'<rect width="5" height="5" onclick="alert(5)"/>'
            .'</svg>';

        $clean = SvgSanitizer::sanitize($hostile);

        $this->assertNotNull($clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('alert(', $clean);
        $this->assertStringNotContainsString('onload', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('foreignObject', $clean);
        $this->assertStringNotContainsString('script:', $clean);

        // The drawing itself is untouched.
        $this->assertStringContainsString('<rect width="5" height="5"/>', $clean);
        $this->assertStringContainsString('<text x="1" y="9">click</text>', $clean);
    }

    public function test_markup_that_is_not_well_formed_is_refused(): void
    {
        $this->assertNull(SvgSanitizer::sanitize('<svg><rect></svg>'));
        $this->assertNull(SvgSanitizer::sanitize('<div>not an svg</div>'));
        $this->assertNull(SvgSanitizer::sanitize(''));
    }

    /**
     * Models leave out xmlns as often as not. As XML that makes the root element
     * a nobody and the browser shows a blank - so it is put back.
     */
    public function test_a_missing_namespace_is_declared(): void
    {
        $clean = SvgSanitizer::sanitize('<svg width="4" height="4"><use xlink:href="#a"/></svg>');

        $this->assertNotNull($clean);
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $clean);
        $this->assertStringContainsString('xmlns:xlink="http://www.w3.org/1999/xlink"', $clean);

        // A declared namespace is not declared twice.
        $twice = SvgSanitizer::declareNamespaces(self::DRAWING);
        $this->assertSame(1, substr_count($twice, 'xmlns='));
    }

    /**
     * A plot from matplotlib or a model often has a viewBox and no size. As an
     * <img> in the frame that carries the download button - a shrink-to-fit
     * inline block - that measures 0x0: the picture was there while the answer
     * streamed and gone once the finished message got its buttons.
     */
    public function test_a_drawing_with_only_a_view_box_gets_its_size_from_it(): void
    {
        $clean = SvgSanitizer::sanitize('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600.5"><rect width="1" height="1"/></svg>');

        $this->assertStringContainsString('width="800"', $clean);
        $this->assertStringContainsString('height="600.5"', $clean);
        $this->assertStringContainsString('viewBox="0 0 800 600.5"', $clean);

        // A declared size is left alone, and a drawing with neither stays as it is.
        $sized = SvgSanitizer::sanitize('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600" width="100%"><rect width="1" height="1"/></svg>');
        $this->assertStringContainsString('width="100%"', $sized);
        $this->assertDoesNotMatchRegularExpression('/<svg[^>]*\sheight=/', $sized);

        $bare = SvgSanitizer::sanitize('<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>');
        $this->assertStringNotContainsString('<svg xmlns="http://www.w3.org/2000/svg" width', $bare);
    }

    public function test_looks_like_svg_accepts_a_prolog_and_rejects_other_markup(): void
    {
        $this->assertTrue(SvgSanitizer::looksLikeSvg(self::DRAWING));
        $this->assertTrue(SvgSanitizer::looksLikeSvg("<?xml version=\"1.0\"?>\n<!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 1.1//EN\" \"x\">\n<svg/>"));
        $this->assertFalse(SvgSanitizer::looksLikeSvg('<html><svg/></html>'));
        $this->assertFalse(SvgSanitizer::looksLikeSvg('iVBORw0KGgo'));
    }

    public function test_a_generated_svg_is_sanitized_before_it_is_written(): void
    {
        $this->actingAs(User::factory()->create());

        $written = null;
        $storage = $this->createMock(FileStorageService::class);
        $storage->method('store')->willReturnCallback(function (string $file) use (&$written): bool {
            $written = $file;

            return true;
        });
        $storage->method('getUrl')->willReturn('https://hawki.test/files/chart.svg');

        $stored = (new AttachmentService($storage))->storeGeneratedFile(
            '<svg width="1" height="1"><script>alert(1)</script><rect width="1" height="1"/></svg>',
            'chart.svg',
            'private'
        );

        $this->assertNotNull($stored);
        $this->assertSame('image/svg+xml', $stored['mime']);
        $this->assertIsString($written);
        $this->assertStringNotContainsString('<script', $written);
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $written);
        $this->assertStringContainsString('<rect width="1" height="1"/>', $written);
    }

    public function test_a_broken_svg_is_not_stored_at_all(): void
    {
        $this->actingAs(User::factory()->create());

        $storage = $this->createMock(FileStorageService::class);
        $storage->expects($this->never())->method('store');

        $stored = (new AttachmentService($storage))->storeGeneratedFile('<svg><rect></svg>', 'chart.svg', 'private');

        $this->assertNull($stored);
    }

    public function test_the_file_routes_lock_an_svg_down_with_a_csp(): void
    {
        $policy = SvgSanitizer::CONTENT_SECURITY_POLICY;

        $this->assertStringContainsString("default-src 'none'", $policy);
        $this->assertStringNotContainsString('script-src', $policy);

        foreach (['AiConvController', 'RoomController'] as $controller) {
            $source = file_get_contents(app_path('Http/Controllers/'.$controller.'.php'));

            $this->assertStringContainsString('SvgSanitizer::CONTENT_SECURITY_POLICY', $source, $controller);
            $this->assertStringContainsString("'X-Content-Type-Options' => 'nosniff'", $source, $controller);
        }
    }
}
