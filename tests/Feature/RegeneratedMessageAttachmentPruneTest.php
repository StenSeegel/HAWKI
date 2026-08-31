<?php

namespace Tests\Feature;

use App\Models\AiConv;
use App\Models\AiConvMsg;
use App\Models\Attachment;
use App\Models\User;
use App\Services\Chat\Attachment\AttachmentService;
use App\Services\Chat\Message\Handlers\PrivateMessageHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegeneratedMessageAttachmentPruneTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private AiConv $conv;
    private AiConvMsg $message;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->conv = AiConv::create([
            'conv_name' => 'Image chat',
            'slug' => 'image-chat',
            'user_id' => $this->user->id,
            'system_prompt' => '',
        ]);

        $this->message = AiConvMsg::create([
            'conv_id' => $this->conv->id,
            'user_id' => $this->user->id,
            'message_role' => 'assistant',
            'message_id' => '1.000',
            'model' => 'gpt-5',
            'iv' => 'iv',
            'tag' => 'tag',
            'content' => 'ciphertext',
            'completion' => true,
        ]);
    }

    private function attachToMessage(string $uuid): Attachment
    {
        $attachment = Attachment::create([
            'uuid' => $uuid,
            'name' => 'generated_image.png',
            'category' => 'private',
            'type' => 'image',
            'mime' => 'image/png',
            'user_id' => $this->user->id,
        ]);

        // The morph columns are not fillable, so the link is set directly.
        $attachment->attachable()->associate($this->message);
        $attachment->save();

        return $attachment;
    }

    /**
     * Real deletes touch the filesystem, so the service is stubbed down to the
     * database row the handler is expected to drop.
     */
    private function handlerWithStubbedStorage(): PrivateMessageHandler
    {
        $service = $this->createMock(AttachmentService::class);
        $service->method('delete')->willReturnCallback(
            function (Attachment $attachment): bool {
                $attachment->delete();

                return true;
            }
        );
        $service->method('assignToMessage')->willReturn(null);

        return new PrivateMessageHandler($service);
    }

    private function update(array $content): void
    {
        $this->handlerWithStubbedStorage()->update($this->conv, [
            'message_id' => '1.000',
            'model' => 'gpt-5',
            'completion' => true,
            'content' => array_merge([
                'text' => ['ciphertext' => 'c', 'iv' => 'i', 'tag' => 't'],
            ], $content),
        ]);
    }

    public function test_regenerating_without_an_image_drops_the_previous_one(): void
    {
        $this->attachToMessage('uuid-old-image');

        $this->update(['attachments' => []]);

        $this->assertDatabaseMissing('attachments', ['uuid' => 'uuid-old-image']);
    }

    public function test_regenerating_with_a_new_image_drops_only_the_previous_one(): void
    {
        $this->attachToMessage('uuid-old-image');

        $this->update(['attachments' => [
            ['uuid' => 'uuid-new-image', 'name' => 'generated_image.png', 'mime' => 'image/png'],
        ]]);

        $this->assertDatabaseMissing('attachments', ['uuid' => 'uuid-old-image']);
    }

    public function test_an_image_that_is_still_listed_is_kept(): void
    {
        $this->attachToMessage('uuid-kept-image');

        $this->update(['attachments' => [
            ['uuid' => 'uuid-kept-image', 'name' => 'generated_image.png', 'mime' => 'image/png'],
        ]]);

        $this->assertDatabaseHas('attachments', ['uuid' => 'uuid-kept-image']);
    }

    public function test_an_update_without_an_attachments_key_keeps_everything(): void
    {
        $this->attachToMessage('uuid-untouched');

        // This is the shape the plain message edit sends.
        $this->update([]);

        $this->assertDatabaseHas('attachments', ['uuid' => 'uuid-untouched']);
    }
}
