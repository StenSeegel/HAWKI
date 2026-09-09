<?php

namespace Tests\Feature;

use App\Services\Storage\FileStorageService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A document upload lands in temp/ together with the converter output below
 * output/. Moving it to the persistent folder has to carry the whole tree,
 * on object stores without real directories too.
 */
class FileStorageMoveToPersistentTest extends TestCase
{
    private const UUID = 'abcd1234-0000-4000-8000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local_file_storage');
        config()->set('filesystems.file_storage', 'local_file_storage');
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
