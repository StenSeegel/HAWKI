<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\User;
use App\Services\Chat\Attachment\AttachmentService;
use App\Services\Chat\Attachment\Handlers\AtchDocumentHandler;
use App\Services\Storage\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * A .drawio diagram edited in HAWKI comes back to the chat as a file the model
 * should go on working with. Its bytes are its content: no converter, no HTML
 * escaping - the model gets the XML in a ```drawio block, as it wrote it.
 */
class TextNativeAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private const XML = '<mxfile host="hawki"><diagram name="Page-1"><mxGraphModel><root><mxCell id="0"/></root></mxGraphModel></diagram></mxfile>';

    public function test_text_files_are_documents_and_images_are_not(): void
    {
        $service = new AttachmentService($this->createMock(FileStorageService::class));

        foreach (['text/xml', 'application/xml', 'text/plain', 'application/json', 'text/csv; charset=utf-8', 'application/vnd.jgraph.mxfile'] as $mime) {
            $this->assertSame('document', $service->convertToAttachmentType($mime), $mime);
        }

        $this->assertSame('image', $service->convertToAttachmentType('image/svg+xml'));
        $this->assertSame('image', $service->convertToAttachmentType('image/png'));
        $this->assertNull($service->convertToAttachmentType('application/zip'));
    }

    public function test_a_drawio_upload_is_recognised_by_its_extension(): void
    {
        // The bytes sniff as octet-stream; the extension says what it is.
        $upload = UploadedFile::fake()->createWithContent('diagram.drawio', self::XML);
        $this->assertSame('application/vnd.jgraph.mxfile', AttachmentService::mimeOfUpload($upload));

        $declared = new UploadedFile($upload->getRealPath(), 'diagram', 'application/xml', null, true);
        $this->assertSame('application/xml', AttachmentService::mimeOfUpload($declared));
    }

    public function test_a_drawio_upload_is_stored_with_its_xml_as_context_and_no_converter(): void
    {
        $written = [];
        $storage = $this->createMock(FileStorageService::class);
        $storage->method('store')->willReturnCallback(function ($file, string $filename, $uuid, string $category, bool $temp = false, string $subFolder = '') use (&$written): bool {
            $written[] = ['name' => $filename, 'sub' => $subFolder, 'content' => is_string($file) ? $file : 'UPLOAD'];

            return true;
        });

        $upload = UploadedFile::fake()->createWithContent('diagram.drawio', self::XML);

        $result = (new AtchDocumentHandler($storage))->store($upload, 'private');

        $this->assertTrue($result['success']);
        $output = collect($written)->firstWhere('name', 'content_markdown.md');
        $this->assertNotNull($output, 'the context file was not written');
        $this->assertSame('/output', $output['sub']);
        $this->assertStringStartsWith("```drawio\n<mxfile", $output['content']);
        $this->assertStringContainsString(self::XML, $output['content']);
    }

    public function test_the_context_of_a_text_file_is_not_html_escaped(): void
    {
        $user = User::factory()->create();
        Attachment::create(['uuid' => 'd1', 'name' => 'diagram.drawio', 'category' => 'private', 'type' => 'document', 'mime' => 'text/xml', 'user_id' => $user->id]);
        Attachment::create(['uuid' => 'p1', 'name' => 'paper.pdf', 'category' => 'private', 'type' => 'document', 'mime' => 'application/pdf', 'user_id' => $user->id]);

        $storage = $this->createMock(FileStorageService::class);
        $storage->method('retrieveOutputFilesByType')->willReturn([
            ['path' => 'content_markdown.md', 'contents' => "```drawio\n".self::XML."\n```"],
        ]);

        $handler = new AtchDocumentHandler($storage);

        $this->assertStringContainsString('<mxfile host="hawki">', $handler->retrieveContext('d1', 'private'));
        // A converted document keeps the escaping it always had.
        $this->assertStringContainsString('&lt;mxfile', $handler->retrieveContext('p1', 'private'));
    }

    public function test_a_fence_is_longer_than_any_backtick_run_in_the_file(): void
    {
        $results = AtchDocumentHandler::textNativeResults("a\n````\nb", 'notes.md');

        $this->assertStringStartsWith("`````markdown\n", $results['content_markdown.md']);
        $this->assertStringEndsWith("\n`````\n", $results['content_markdown.md']);
    }
}
