<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FileConverter\Handlers\GwdgDocling;
use App\Services\FileConverter\Handlers\HawkiDocConverter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A conversion leaves nothing behind in /tmp. Every document used to leave an
 * upl_ copy, an empty unzipped_ placeholder and its whole pdf_extract_ tree -
 * about 4 GB in the app container on ki-chat after two weeks.
 */
class FileConverterTempCleanupTest extends TestCase
{
    private function tempEntries(): array
    {
        return glob(sys_get_temp_dir().'/{upl_,unzipped_,pdf_extract_}*', GLOB_BRACE) ?: [];
    }

    private function converterZip(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'testzip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('content_markdown.md', "# Prüfung\n");
        $zip->addFromString('assets/image_0.png', 'png-bytes');
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private function hawkiConverter(): HawkiDocConverter
    {
        return new HawkiDocConverter(['api_key' => 'key', 'api_url' => 'http://file-converter/extract']);
    }

    public function test_a_conversion_returns_the_files_and_leaves_no_temp_files(): void
    {
        Http::fake(['file-converter/*' => Http::response($this->converterZip(), 200)]);
        $before = $this->tempEntries();

        $files = $this->hawkiConverter()->convert('%PDF-1.4 bytes', 'report.pdf');

        $this->assertSame("# Prüfung\n", $files['content_markdown.md']);
        $this->assertSame('png-bytes', $files['assets/image_0.png']);
        $this->assertSame($before, $this->tempEntries());
    }

    public function test_a_failed_conversion_leaves_no_temp_files_either(): void
    {
        Http::fake(['file-converter/*' => Http::response('converter down', 500)]);
        $before = $this->tempEntries();

        try {
            $this->hawkiConverter()->convert('%PDF-1.4 bytes', 'report.pdf');
            $this->fail('the conversion should have failed');
        } catch (\Exception $e) {
            $this->assertStringContainsString('PDF extraction failed', $e->getMessage());
        }

        $this->assertSame($before, $this->tempEntries());
    }

    public function test_a_broken_zip_leaves_no_temp_files(): void
    {
        Http::fake(['file-converter/*' => Http::response('not a zip', 200)]);
        $before = $this->tempEntries();

        try {
            $this->hawkiConverter()->convert('%PDF-1.4 bytes', 'report.pdf');
            $this->fail('the conversion should have failed');
        } catch (\Exception) {
        }

        $this->assertSame($before, $this->tempEntries());
    }

    public function test_docling_leaves_no_upload_copy(): void
    {
        Http::fake(['docling/*' => Http::response(['markdown' => '# Doc', 'filename' => 'report'], 200)]);
        $before = $this->tempEntries();

        $files = (new GwdgDocling(['api_key' => 'key', 'api_url' => 'http://docling/convert']))->convert('%PDF-1.4 bytes', 'report.pdf');

        $this->assertSame('# Doc', $files['report.md']);
        $this->assertSame($before, $this->tempEntries());
    }
}
