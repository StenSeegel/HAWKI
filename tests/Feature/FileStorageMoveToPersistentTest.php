<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\User;
use App\Services\Chat\Attachment\AttachmentService;
use App\Services\Storage\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A document upload lands in temp/ together with the converter output below
 * output/. Moving it to the persistent folder has to carry the whole tree,
 * on object stores without real directories too.
 */
class FileStorageMoveToPersistentTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = 'abcd1234-0000-4000-8000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local_file_storage');
        config()->set('filesystems.file_storage', 'local_file_storage');
    }

    /**
     * Reading an attachment must not depend on the move having happened.
     *
     * A generated image is attached to the next message the moment it appears -
     * to be edited, or as context for a vision model - and that can be before the
     * message it came from was saved, which is what moves the file. The
     * persistent read finds nothing then, and every consumer saw an empty file:
     * the image tool reported "could not be read" and the vision path sent an
     * empty data url.
     */
    public function test_an_attachment_still_in_temp_is_read_from_there(): void
    {
        $disk = Storage::disk('local_file_storage');
        $uuid = 'abcd1234-0000-4000-8000-000000000003';
        $disk->put('temp/private/a/b/c/d/' . $uuid . '/' . $uuid . '.png', 'PNGBYTES');

        $attachment = Attachment::create([
            'uuid' => $uuid,
            'name' => 'generated.png',
            'category' => 'private',
            'type' => 'image',
            'mime' => 'image/png',
            'user_id' => User::factory()->create()->id,
        ]);

        $this->assertSame('PNGBYTES', app(AttachmentService::class)->retrieve($attachment));

        // And the persistent copy wins once it exists.
        $disk->put('private/a/b/c/d/' . $uuid . '/' . $uuid . '.png', 'MOVEDBYTES');
        $this->assertSame('MOVEDBYTES', app(AttachmentService::class)->retrieve($attachment));
    }

    public function test_moves_the_upload_and_the_output_subfolder(): void
    {
        $disk = Storage::disk('local_file_storage');
        $temp = 'temp/documents/a/b/c/d/' . self::UUID;
        $disk->put($temp . '/' . self::UUID . '.pdf', 'pdf');
        $disk->put($temp . '/output/00001.md', 'chunk one');
        $disk->put($temp . '/output/00002.md', 'chunk two');
        $disk->put($temp . '/output/image_0.webp', 'webp');
        $disk->put($temp . '/output/meta.json', '{}');

        $storage = app(FileStorageService::class);
        $this->assertTrue($storage->moveFileToPersistentFolder(self::UUID, 'documents'));

        $target = 'documents/a/b/c/d/' . self::UUID;
        $this->assertSame(
            [
                $target . '/' . self::UUID . '.pdf',
                $target . '/output/00001.md',
                $target . '/output/00002.md',
                $target . '/output/image_0.webp',
                $target . '/output/meta.json',
            ],
            collect($disk->allFiles($target))->sort()->values()->all()
        );
        $this->assertSame([], $disk->allFiles($temp), 'temp folder is emptied');

        $md = $storage->retrieveOutputFilesByType(self::UUID, 'documents', 'md');
        $this->assertCount(2, $md);
        $this->assertSame(
            [$target . '/output/image_0.webp'],
            $storage->listOutputFilesByType(self::UUID, 'documents', 'webp')
        );
        $this->assertSame('webp', $storage->readFile($target . '/output/image_0.webp'));
    }
}
