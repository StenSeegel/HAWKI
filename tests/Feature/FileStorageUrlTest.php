<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Storage\UrlGenerator;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Where a browser is sent to fetch a stored file. A private file goes through
 * HAWKI's signed route - which checks the owner - on every disk, so moving
 * uploads to S3 does not point browsers at the object store itself.
 */
class FileStorageUrlTest extends TestCase
{
    private const PATH = 'private/a/b/c/d/abcd-uuid/report.pdf';

    private function urlFor(array $config): string
    {
        return (new UrlGenerator($config, Storage::fake('files')))->generate(self::PATH, 'abcd-uuid', 'private');
    }

    public function test_a_private_file_on_s3_is_served_through_the_signed_route(): void
    {
        $url = $this->urlFor(['driver' => 's3', 'visibility' => 'private']);

        $this->assertStringStartsWith(url('/files/abcd-uuid/private/'), $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString('X-Amz-', $url);
    }

    public function test_a_private_local_file_is_served_through_the_signed_route_as_before(): void
    {
        $url = $this->urlFor(['driver' => 'local', 'visibility' => 'private']);

        $this->assertStringStartsWith(url('/files/abcd-uuid/private/'), $url);
        $this->assertStringContainsString('signature=', $url);
    }

    public function test_a_public_local_file_keeps_its_direct_url(): void
    {
        $url = $this->urlFor(['driver' => 'local', 'visibility' => 'public']);

        $this->assertStringNotContainsString('signature=', $url);
        $this->assertStringEndsWith(self::PATH, $url);
    }
}
