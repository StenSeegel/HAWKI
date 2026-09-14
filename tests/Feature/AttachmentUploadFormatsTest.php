<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Chat\Attachment\AttachmentService;
use App\Services\FileConverter\Handlers\HawkiDocConverter;
use App\Services\FileConverter\SupportedFormats;
use App\Services\Storage\FileStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Everything the file converter reads is uploadable in a chat: a deck, a
 * spreadsheet, an ODF document, an EPUB, a mail. What is not uploadable is an
 * archive - never - and whatever an admin turned off, audio and video today.
 */
class AttachmentUploadFormatsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'file_converter.default' => 'hawki_converter',
            'file_converter.converters.hawki_converter.api_url' => 'http://file-converter/extract',
            'file_converter.converters.hawki_converter.api_key' => 'secret',
        ]);

        Cache::flush();
        // No converter reachable in a test: the static snapshot is the list.
        Http::fake(['http://file-converter/' => Http::response('', 503)]);
    }

    private function service(): AttachmentService
    {
        return new AttachmentService($this->createMock(FileStorageService::class));
    }

    public static function documentFormats(): array
    {
        return [
            'presentation' => ['deck.pptx'],
            'spreadsheet' => ['numbers.xlsx'],
            'open document' => ['notes.odt'],
            'ebook' => ['book.epub'],
            'mail' => ['thread.eml'],
            'asciidoc' => ['manual.adoc'],
            'rich text' => ['letter.rtf'],
            'yaml' => ['values.yaml'],
            'keynote' => ['talk.key'],
            'html' => ['page.html'],
        ];
    }

    #[DataProvider('documentFormats')]
    public function test_a_converter_format_becomes_a_document(string $filename): void
    {
        $upload = UploadedFile::fake()->createWithContent($filename, 'x');

        $this->assertSame('document', $this->service()->convertToAttachmentType(
            AttachmentService::mimeOfUpload($upload),
            $filename
        ), $filename);
    }

    public function test_a_picture_is_an_image_and_an_unknown_binary_is_nothing(): void
    {
        $service = $this->service();

        $this->assertSame('image', $service->convertToAttachmentType('image/png', 'photo.png'));
        $this->assertNull($service->convertToAttachmentType('application/octet-stream', 'setup.exe'));
        $this->assertNull($service->convertToAttachmentType('application/octet-stream', 'blob.bin'));
    }

    public function test_archives_are_refused_even_with_an_empty_deny_list(): void
    {
        config(['file_converter.excluded_extensions' => []]);
        app()->forgetInstance(SupportedFormats::class);

        $service = $this->service();

        foreach (['a.zip', 'a.7z', 'mail.pst', 'a.tar', 'a.gz', 'a.tgz'] as $archive) {
            $this->assertNull($service->convertToAttachmentType('application/zip', $archive), $archive);
        }
    }

    public function test_audio_and_video_are_refused_by_the_default_deny_list(): void
    {
        $service = $this->service();

        foreach (['talk.mp3', 'clip.mp4', 'talk.wav', 'clip.webm'] as $media) {
            $this->assertNull($service->convertToAttachmentType('audio/mpeg', $media), $media);
        }
    }

    public function test_the_mime_of_an_upload_is_read_from_the_extension_when_the_bytes_say_nothing(): void
    {
        // The browser sends no type for an .adoc and sniffing reads it as text.
        $adoc = UploadedFile::fake()->createWithContent('manual.adoc', "= Title\n\nBody");
        $this->assertSame('text/asciidoc', AttachmentService::mimeOfUpload($adoc));

        // An iWork document is a zip on disk; only its name says what it is.
        $pages = new UploadedFile(
            $this->zipFixture('pages'),
            'letter.pages',
            'application/zip',
            null,
            true
        );
        $this->assertSame('application/x-iwork-pages-sffpages', AttachmentService::mimeOfUpload($pages));

        // A real archive keeps its own type - and is refused elsewhere.
        $zip = new UploadedFile($this->zipFixture('zip'), 'bundle.zip', 'application/zip', null, true);
        $this->assertSame('application/zip', AttachmentService::mimeOfUpload($zip));
    }

    public function test_markup_that_only_looks_like_text_goes_through_the_converter(): void
    {
        // Raw tags and RTF control words are worth far less than the Markdown
        // the converter makes of them. An .rtf even sniffs as text/rtf.
        $this->assertFalse(AttachmentService::isTextNativeMime('text/html'));
        $this->assertFalse(AttachmentService::isTextNativeMime('text/rtf'));
        $this->assertFalse(AttachmentService::isTextNativeMime('application/rtf'));

        $this->assertTrue(AttachmentService::isTextNativeMime('text/markdown'));
        $this->assertTrue(AttachmentService::isTextNativeMime('text/csv; charset=utf-8'));
        $this->assertTrue(AttachmentService::isTextNativeMime('application/vnd.jgraph.mxfile'));
    }

    public function test_the_converter_is_sent_the_real_file_name_when_it_gets_raw_bytes(): void
    {
        Http::fake(['http://file-converter/extract' => Http::response($this->emptyZip())]);

        (new HawkiDocConverter([
            'api_url' => 'http://file-converter/extract',
            'api_key' => 'secret',
            'timeout' => 5,
        ]))->convert('raw bytes', 'report.docx');

        Http::assertSent(function ($request) {
            if ($request->url() !== 'http://file-converter/extract') {
                return false;
            }

            // The converter validates by file name: calling this file.pdf made
            // the re-extraction of a stored .docx fail with a 400.
            $part = collect($request->data())->firstWhere('name', 'file');

            return ($part['filename'] ?? null) === 'report.docx';
        });
    }

    public function test_the_browser_gates_on_the_same_list_the_server_injects(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/home.blade.php'));
        $this->assertStringContainsString('window.uploadFormats = uploadFormats;', $layout);
        $this->assertStringContainsString('const attachmentMaxMb =', $layout);

        // The file picker only offers what the list allows.
        $input = file_get_contents(resource_path('views/partials/home/input-field.blade.php'));
        $this->assertStringContainsString("accept=\"{{ \$uploadFormats['accept'] ?? '' }}\"", $input);

        // ... and a drop or a pick is checked against it by extension.
        $js = file_get_contents(public_path('js/attachment_handler.js'));
        $this->assertStringContainsString('function isUploadable(file)', $js);
        $this->assertStringContainsString('if (!isUploadable(file))', $js);
        $this->assertStringContainsString('window.attachmentMaxMb', $js);
        $this->assertStringNotContainsString('const allowedTypes', $js);
    }

    /** A zip whose first bytes make finfo call it application/zip. */
    private function zipFixture(string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'atch_'.$suffix);
        file_put_contents($path, $this->emptyZip());

        return $path;
    }

    private function emptyZip(): string
    {
        return base64_decode('UEsFBgAAAAAAAAAAAAAAAAAAAAAAAA==');
    }
}
