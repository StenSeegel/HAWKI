<?php

declare(strict_types=1);

namespace Tests\Feature;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * MinIO refuses DeleteObjects without a Content-MD5 header, which the AWS SDK
 * no longer sends - deleteDirectory() on an S3 disk failed, silently with
 * 'throw' off, and deleted attachments stayed in the bucket.
 */
class S3DeleteObjectsContentMd5Test extends TestCase
{
    public function test_a_directory_delete_carries_the_md5_of_its_body(): void
    {
        $sent = [];
        $mock = new MockHandler();
        $mock->append(new Result(['Contents' => [['Key' => 'private/a/b/c/d/abcd/abcd.pdf']], 'KeyCount' => 1]));
        $mock->append(function (CommandInterface $command, RequestInterface $request) use (&$sent) {
            $sent[$command->getName()] = $request;

            return new Result(['Deleted' => [['Key' => 'private/a/b/c/d/abcd/abcd.pdf']]]);
        });

        $disk = Storage::build([
            'driver' => 's3',
            'key' => 'key',
            'secret' => 'secret',
            'region' => 'us-east-1',
            'bucket' => 'hawki-files-test',
            'endpoint' => 'https://s3.example.test',
            'use_path_style_endpoint' => true,
            'throw' => true,
            'handler' => $mock,
        ]);

        $disk->deleteDirectory('private/a/b/c/d/abcd');

        $this->assertArrayHasKey('DeleteObjects', $sent);
        $request = $sent['DeleteObjects'];
        $this->assertSame(
            base64_encode(md5((string) $request->getBody(), true)),
            $request->getHeaderLine('Content-MD5')
        );
    }
}
