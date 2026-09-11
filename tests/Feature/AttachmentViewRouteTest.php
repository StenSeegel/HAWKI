<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiConv;
use App\Models\AiConvMsg;
use App\Models\Attachment;
use App\Models\User;
use App\Services\Chat\Attachment\AttachmentService;
use App\Services\Storage\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Generated images, plots and container files were written into the message
 * with the signed storage url, which expires after 24 hours - a day later the
 * picture was an "invalid signature" page. They are now shown at a stable url
 * that carries only the uuid and is checked against the session and the owner.
 */
class AttachmentViewRouteTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();

        $storage = $this->createMock(FileStorageService::class);
        $storage->method('retrieve')->willReturnCallback(
            fn (string $uuid, string $category, bool $temp = false) => match (true) {
                $uuid === 'persistent-uuid' && ! $temp => 'PNGBYTES',
                $uuid === 'temp-uuid' && $temp => 'TEMPBYTES',
                $uuid === 'svg-uuid' && ! $temp => '<svg xmlns="http://www.w3.org/2000/svg"/>',
                default => null,
            }
        );
        $this->app->instance(FileStorageService::class, $storage);
    }

    private function attachment(string $uuid, string $mime = 'image/png', string $name = 'plot.png', ?User $user = null): Attachment
    {
        return Attachment::create([
            'uuid' => $uuid,
            'name' => $name,
            'category' => 'private',
            'type' => 'image',
            'mime' => $mime,
            'user_id' => ($user ?? $this->owner)->id,
        ]);
    }

    public function test_the_owner_gets_the_file_inline_with_its_name_and_a_cache_header(): void
    {
        $this->attachment('persistent-uuid');

        $response = $this->actingAs($this->owner)->withoutMiddleware()
            ->get('/req/conv/attachment/view/persistent-uuid');

        $response->assertOk();
        $this->assertSame('PNGBYTES', $response->getContent());
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline; filename="plot.png"', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('max-age=86400', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    /**
     * A file reaches its persistent folder only when the message is saved; the
     * picture is shown while the answer still streams.
     */
    public function test_a_file_still_in_temp_storage_is_served_too(): void
    {
        $this->attachment('temp-uuid');

        $response = $this->actingAs($this->owner)->withoutMiddleware()
            ->get('/req/conv/attachment/view/temp-uuid');

        $response->assertOk();
        $this->assertSame('TEMPBYTES', $response->getContent());
    }

    public function test_an_svg_is_served_with_the_content_security_policy(): void
    {
        $this->attachment('svg-uuid', 'image/svg+xml', 'chart.svg');

        $response = $this->actingAs($this->owner)->withoutMiddleware()
            ->get('/req/conv/attachment/view/svg-uuid');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_somebody_else_does_not_get_the_file(): void
    {
        $this->attachment('persistent-uuid');

        $this->actingAs(User::factory()->create())->withoutMiddleware()
            ->get('/req/conv/attachment/view/persistent-uuid')
            ->assertForbidden();
    }

    public function test_an_unknown_or_missing_file_is_a_404(): void
    {
        $this->attachment('gone-uuid');

        $this->actingAs($this->owner)->withoutMiddleware()
            ->get('/req/conv/attachment/view/gone-uuid')
            ->assertNotFound();

        $this->actingAs($this->owner)->withoutMiddleware()
            ->get('/req/conv/attachment/view/never-existed')
            ->assertNotFound();
    }

    public function test_messages_hand_the_stable_url_to_the_frontend(): void
    {
        $conv = AiConv::create(['slug' => 'conv-1', 'user_id' => $this->owner->id, 'conv_name' => 'x']);
        $message = $conv->messages()->create([
            'user_id' => $this->owner->id, 'message_id' => '1.000', 'message_role' => 'assistant', 'model' => 'gpt', 'iv' => 'iv', 'tag' => 'tag', 'content' => 'c', 'completion' => true,
        ]);
        $attachment = $this->attachment('persistent-uuid');
        $attachment->attachable()->associate($message);
        $attachment->save();

        $array = AiConvMsg::find($message->id)->attachmentsAsArray();

        $this->assertStringEndsWith('/req/conv/attachment/view/persistent-uuid', $array[0]['fileData']['url']);
    }

    public function test_the_view_url_falls_back_to_storage_for_other_categories(): void
    {
        $storage = $this->createMock(FileStorageService::class);
        $storage->method('getUrl')->willReturn('https://storage.test/avatar.png');

        $service = new AttachmentService($storage);

        $this->assertStringEndsWith('/req/conv/attachment/view/u1', $service->viewUrl('u1', 'private'));
        $this->assertStringEndsWith('/req/room/attachment/view/u1', $service->viewUrl('u1', 'group'));
        $this->assertSame('https://storage.test/avatar.png', $service->viewUrl('u1', 'profile_avatars'));
    }
}
