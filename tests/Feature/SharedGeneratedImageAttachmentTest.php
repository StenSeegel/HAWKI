<?php

namespace Tests\Feature;

use App\Models\AiConv;
use App\Models\AiConvMsg;
use App\Models\Attachment;
use App\Models\User;
use App\Services\Chat\Attachment\AttachmentService;
use App\Services\Storage\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedGeneratedImageAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private AiConv $conv;
    private AiConvMsg $assistant;
    private AiConvMsg $userMessage;

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

        $this->assistant = $this->message('assistant', '1.000');
        $this->userMessage = $this->message('user', '2.000');
    }

    private function message(string $role, string $id): AiConvMsg
    {
        return AiConvMsg::create([
            'conv_id' => $this->conv->id,
            'user_id' => $this->user->id,
            'message_role' => $role,
            'message_id' => $id,
            'model' => 'gpt-5',
            'iv' => 'iv',
            'tag' => 'tag',
            'content' => 'ciphertext',
            'completion' => true,
        ]);
    }

    /** A generated image, already linked to the assistant turn that produced it. */
    private function generatedImage(): Attachment
    {
        $attachment = Attachment::create([
            'uuid' => 'uuid-generated',
            'name' => 'generated_image.png',
            'category' => 'private',
            'type' => 'image',
            'mime' => 'image/png',
            'user_id' => $this->user->id,
        ]);
        $attachment->attachable()->associate($this->assistant);
        $attachment->save();

        return $attachment;
    }

    private function serviceWithFakeStorage(): AttachmentService
    {
        $storage = $this->createMock(FileStorageService::class);
        $storage->method('retrieve')->willReturn('PNGBYTES');
        $storage->method('store')->willReturn(true);
        $storage->method('moveFileToPersistentFolder')->willReturn(true);

        return new AttachmentService($storage);
    }

    public function test_sending_a_generated_image_as_context_leaves_the_original_in_place(): void
    {
        $original = $this->generatedImage();

        $this->serviceWithFakeStorage()->assignToMessage($this->userMessage, [
            'uuid' => $original->uuid,
            'name' => $original->name,
            'mime' => $original->mime,
        ]);

        // The assistant keeps the image it generated...
        $this->assertSame(1, $this->assistant->attachments()->count());
        $this->assertSame('uuid-generated', $this->assistant->attachments()->first()->uuid);

        // ...and the user message gets a visible attachment of its own.
        $this->assertSame(1, $this->userMessage->attachments()->count());
        $this->assertNotSame('uuid-generated', $this->userMessage->attachments()->first()->uuid);
        $this->assertSame(2, Attachment::count());
    }

    public function test_the_copy_keeps_the_name_type_and_mime(): void
    {
        $original = $this->generatedImage();

        $this->serviceWithFakeStorage()->assignToMessage($this->userMessage, [
            'uuid' => $original->uuid,
            'name' => $original->name,
            'mime' => $original->mime,
        ]);

        $copy = $this->userMessage->attachments()->first();

        $this->assertSame('generated_image.png', $copy->name);
        $this->assertSame('image/png', $copy->mime);
        $this->assertSame('image', $copy->type);
        $this->assertSame('private', $copy->category);
        $this->assertSame($this->user->id, $copy->user_id);
    }

    public function test_the_user_message_reports_the_attachment_to_the_client(): void
    {
        $original = $this->generatedImage();

        $this->serviceWithFakeStorage()->assignToMessage($this->userMessage, [
            'uuid' => $original->uuid,
            'name' => $original->name,
            'mime' => $original->mime,
        ]);

        $attachments = $this->userMessage->fresh()->createMessageObject()['content']['attachments'];

        $this->assertCount(1, $attachments);
        $this->assertSame('generated_image.png', $attachments[0]['fileData']['name']);
        $this->assertSame('image', $attachments[0]['fileData']['type']);
    }

    public function test_an_orphan_attachment_is_still_adopted_rather_than_copied(): void
    {
        // This is the shape storeFromBase64() leaves behind before linking.
        Attachment::create([
            'uuid' => 'uuid-orphan',
            'name' => 'generated_image.png',
            'category' => 'private',
            'type' => 'image',
            'mime' => 'image/png',
            'user_id' => $this->user->id,
        ]);

        $this->serviceWithFakeStorage()->assignToMessage($this->assistant, [
            'uuid' => 'uuid-orphan',
            'name' => 'generated_image.png',
            'mime' => 'image/png',
        ]);

        $this->assertSame(1, Attachment::count());
        $this->assertSame('uuid-orphan', $this->assistant->attachments()->first()->uuid);
    }

    public function test_reassigning_to_the_same_message_does_not_duplicate(): void
    {
        $original = $this->generatedImage();

        $this->serviceWithFakeStorage()->assignToMessage($this->assistant, [
            'uuid' => $original->uuid,
            'name' => $original->name,
            'mime' => $original->mime,
        ]);

        $this->assertSame(1, Attachment::count());
        $this->assertSame(1, $this->assistant->attachments()->count());
    }
}
