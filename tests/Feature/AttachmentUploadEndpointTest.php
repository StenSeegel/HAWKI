<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Storage\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The upload routes take what the converter reads and refuse the rest. The
 * browser checks the same list first, so a refusal here means someone posted
 * past it - the answer says which extensions are allowed rather than failing
 * with a generic "store failed".
 */
class AttachmentUploadEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'file_converter.default' => 'hawki_converter',
            'file_converter.converters.hawki_converter.api_url' => 'http://file-converter/extract',
            'file_converter.converters.hawki_converter.api_key' => 'secret',
        ]);
        Cache::flush();

        Http::fake([
            'http://file-converter/' => Http::response(['supported_formats' => ['.pdf', '.pptx', '.docx', '.odt', '.eml']]),
            'http://file-converter/extract' => Http::response($this->converterZip()),
        ]);

        $storage = $this->createMock(FileStorageService::class);
        $storage->method('store')->willReturn(true);
        $this->app->instance(FileStorageService::class, $storage);

        $this->user = User::factory()->create();
    }

    private function upload(string $url, UploadedFile $file)
    {
        // Accept: application/json, as the uploader sends it - otherwise a
        // failed validation redirects instead of answering 422.
        return $this->actingAs($this->user)->withoutMiddleware()
            ->post($url, ['file' => $file], ['Accept' => 'application/json']);
    }

    public function test_a_presentation_is_accepted_in_a_private_chat(): void
    {
        $response = $this->upload('/req/conv/attachment/upload', UploadedFile::fake()->createWithContent('deck.pptx', 'x'));

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertNotEmpty($response->json('uuid'));
    }

    public function test_a_presentation_is_accepted_in_a_group_room(): void
    {
        $response = $this->upload('/req/room/attachment/upload/room-1', UploadedFile::fake()->createWithContent('deck.pptx', 'x'));

        $response->assertOk();
        $response->assertJson(['success' => true]);
    }

    public function test_an_executable_is_refused_with_the_accepted_list(): void
    {
        $response = $this->upload('/req/conv/attachment/upload', UploadedFile::fake()->createWithContent('setup.exe', 'MZ'));

        $response->assertStatus(422);
        $message = (string) $response->json('errors.file.0');
        $this->assertStringContainsString('.exe', $message);
        $this->assertStringContainsString('.pptx', $message);
    }

    public function test_an_archive_is_refused_in_both_modules(): void
    {
        foreach (['/req/conv/attachment/upload', '/req/room/attachment/upload/room-1'] as $url) {
            $this->upload($url, UploadedFile::fake()->createWithContent('bundle.zip', 'PK'))->assertStatus(422);
        }
    }

    public function test_media_is_refused_while_it_is_on_the_deny_list(): void
    {
        $this->upload('/req/conv/attachment/upload', UploadedFile::fake()->createWithContent('talk.mp3', 'ID3'))
            ->assertStatus(422);
        $this->upload('/req/conv/attachment/upload', UploadedFile::fake()->createWithContent('clip.mp4', 'ftyp'))
            ->assertStatus(422);
    }

    public function test_a_file_over_the_size_limit_is_refused(): void
    {
        config(['hawki.attachment_max_mb' => 1]);

        $this->upload('/req/conv/attachment/upload', UploadedFile::fake()->create('big.pdf', 2048))
            ->assertStatus(422);
    }

    /** One chunk of Markdown in the shape the converter returns. */
    private function converterZip(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'conv_').'.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('output/chunks/00001.md', "---\npage: 1\n---\nSlide one\n");
        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }
}
