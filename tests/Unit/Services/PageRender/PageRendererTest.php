<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PageRender;

use App\Services\PageRender\PageRenderer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The page-render sidecar turns a deck into one PNG per slide. HAWKI asks it
 * only for slide formats, sends the real file name (the sidecar picks its
 * import filter by extension) and treats every failure as "no pages".
 */
class PageRendererTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'page_render.enabled' => true,
            'page_render.api_url' => 'http://page-render',
            'page_render.api_key' => 'secret',
            'page_render.formats' => ['pptx', 'odp', 'key'],
            'page_render.max_pages' => 5,
            'page_render.dpi' => 96,
        ]);
    }

    public function test_only_slide_formats_are_rendered(): void
    {
        $renderer = new PageRenderer();

        $this->assertTrue($renderer->shouldRender('deck.PPTX'));
        $this->assertTrue($renderer->shouldRender('talk.key'));
        $this->assertFalse($renderer->shouldRender('paper.pdf'), 'PDF is opt-in: the converter already extracts its figures');
        $this->assertFalse($renderer->shouldRender('notes.docx'));
        $this->assertFalse($renderer->shouldRender('noextension'));

        config(['page_render.enabled' => false]);
        $this->assertFalse($renderer->shouldRender('deck.pptx'));

        config(['page_render.enabled' => true, 'page_render.api_url' => '']);
        $this->assertFalse($renderer->shouldRender('deck.pptx'));
    }

    public function test_pages_come_back_in_order_with_the_real_file_name_sent(): void
    {
        Http::fake(['http://page-render/render*' => Http::response($this->zip([
            'pages/page_002.png' => 'TWO',
            'meta.json' => '{"pages_total": 2}',
            'pages/page_001.png' => 'ONE',
            'notes.txt' => 'ignored',
        ]))]);

        $pages = (new PageRenderer())->render('raw deck bytes', 'Sicherheitsposter.pptx');

        $this->assertSame(['pages/page_001.png' => 'ONE', 'pages/page_002.png' => 'TWO'], $pages);

        Http::assertSent(function ($request) {
            $part = collect($request->data())->firstWhere('name', 'file');

            return str_starts_with($request->url(), 'http://page-render/render')
                && $request->hasHeader('Authorization', 'Bearer secret')
                && ($part['filename'] ?? null) === 'Sicherheitsposter.pptx'
                && str_contains($request->url(), 'max_pages=5')
                && str_contains($request->url(), 'dpi=96');
        });
    }

    public function test_an_upload_is_streamed_from_its_path(): void
    {
        Http::fake(['http://page-render/render*' => Http::response($this->zip(['pages/page_001.png' => 'P']))]);

        $upload = UploadedFile::fake()->createWithContent('deck.pptx', 'PK-bytes');
        $this->assertSame(['pages/page_001.png' => 'P'], (new PageRenderer())->render($upload, 'deck.pptx'));
    }

    public function test_every_failure_means_no_pages(): void
    {
        Http::fake(fn() => throw new ConnectionException('Connection refused'));
        $this->assertSame([], (new PageRenderer())->render('bytes', 'deck.pptx'));

        Http::fake(['http://page-render/render*' => Http::response('LibreOffice could not open the file', 422)]);
        $this->assertSame([], (new PageRenderer())->render('bytes', 'deck.pptx'));

        Http::fake(['http://page-render/render*' => Http::response('not a zip at all', 200)]);
        $this->assertSame([], (new PageRenderer())->render('bytes', 'deck.pptx'));
    }

    public function test_the_sidecar_announces_its_formats(): void
    {
        Http::fake(['http://page-render/' => Http::response(['version' => '1.0.0', 'supported_formats' => ['.pdf', '.pptx']])]);

        $this->assertSame(['.pdf', '.pptx'], (new PageRenderer())->supportedFormats());
    }

    /** @param array<string,string> $entries */
    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'z').'.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }
}
