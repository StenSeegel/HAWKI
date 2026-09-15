<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiConv;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A message whose content does not fit is refused with a 422, not a 500.
 *
 * The case that found this: a .drawio attached in the chat. No browser knows
 * the extension, so file.type is "" and the send payload carried mime "". The
 * content validator swallowed the failure and returned null, the message
 * handler crashed on the missing content, and the frontend hung on a response
 * without a message in it.
 */
class MessageContentValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
    }

    private function conversation(User $user): AiConv
    {
        return AiConv::create([
            'user_id' => $user->id,
            'slug' => 'test-conv-slug',
            'conv_name' => 'Test',
            'system_prompt' => '',
        ]);
    }

    private function payload(array $content): array
    {
        return [
            'isAi' => false,
            'threadId' => 0,
            'content' => $content,
            'completion' => true,
        ];
    }

    public function test_an_attachment_without_a_mime_is_refused_with_422(): void
    {
        $user = User::factory()->create();
        $conv = $this->conversation($user);

        $response = $this->actingAs($user)->postJson("/req/conv/sendMessage/{$conv->slug}", $this->payload([
            'text' => ['ciphertext' => 'c', 'iv' => 'i', 'tag' => 't'],
            'attachments' => [
                ['uuid' => 'some-uuid', 'name' => 'diagram.drawio', 'mime' => ''],
            ],
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['attachments.0.mime']);
        $this->assertSame(0, $conv->messages()->count(), 'a refused message must not be stored');
    }

    public function test_a_content_with_neither_text_nor_attachments_is_refused_with_422(): void
    {
        $user = User::factory()->create();
        $conv = $this->conversation($user);

        $response = $this->actingAs($user)->postJson("/req/conv/sendMessage/{$conv->slug}", $this->payload([
            'text' => null,
            'attachments' => [],
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['content']);
    }

    public function test_a_well_formed_message_is_still_accepted(): void
    {
        $user = User::factory()->create();
        $conv = $this->conversation($user);

        $response = $this->actingAs($user)->postJson("/req/conv/sendMessage/{$conv->slug}", $this->payload([
            'text' => ['ciphertext' => 'c', 'iv' => 'i', 'tag' => 't'],
        ]));

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }
}
