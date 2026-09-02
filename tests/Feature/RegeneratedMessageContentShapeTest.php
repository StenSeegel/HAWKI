<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiConv;
use App\Models\AiConvMsg;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regenerating an answer updates the message through updateMessage, and the
 * frontend re-renders it from messageData.content.text. When that endpoint
 * answered with a raw toArray() - where 'content' is the ciphertext string
 * rather than the nested content object - the freshly streamed answer was
 * rendered away and the message was left with an empty content container.
 */
class RegeneratedMessageContentShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These routes sit behind signature_check and chatAccess; this test is
        // about the shape of the response body, not about access control.
        $this->withoutMiddleware();
    }

    private function conversationWithMessage(User $user): array
    {
        $conv = AiConv::create([
            'user_id' => $user->id,
            'slug' => 'test-conv-slug',
            'conv_name' => 'Test',
            'system_prompt' => '',
        ]);

        $message = AiConvMsg::create([
            'conv_id' => $conv->id,
            'user_id' => $user->id,
            'message_role' => 'assistant',
            'message_id' => '1.000',
            'model' => 'jlu/gemma-4-26b-it',
            'content' => 'old-ciphertext',
            'iv' => 'old-iv',
            'tag' => 'old-tag',
            'completion' => true,
        ]);

        return [$conv, $message];
    }

    public function test_update_message_returns_the_nested_content_object(): void
    {
        $user = User::factory()->create();
        [$conv, $message] = $this->conversationWithMessage($user);

        $response = $this->actingAs($user)->postJson("/req/conv/updateMessage/{$conv->slug}", [
            'isAi' => true,
            'content' => [
                'text' => [
                    'ciphertext' => 'new-ciphertext',
                    'iv' => 'new-iv',
                    'tag' => 'new-tag',
                ],
            ],
            'model' => 'jlu/gemma-4-26b-it',
            'completion' => true,
            'message_id' => $message->message_id,
        ]);

        $response->assertOk();

        $content = $response->json('messageData.content');

        // The shape the frontend renders from, identical to sendMessage.
        $this->assertIsArray($content, 'content must be the nested object, not the ciphertext string');
        $this->assertSame('new-ciphertext', $content['text']['ciphertext']);
        $this->assertSame('new-iv', $content['text']['iv']);
        $this->assertSame('new-tag', $content['text']['tag']);
        $this->assertArrayHasKey('attachments', $content);
    }

    public function test_update_message_shape_matches_send_message(): void
    {
        $user = User::factory()->create();
        [$conv, $message] = $this->conversationWithMessage($user);

        $sent = $this->actingAs($user)->postJson("/req/conv/sendMessage/{$conv->slug}", [
            'isAi' => true,
            'threadId' => 0,
            'content' => [
                'text' => ['ciphertext' => 'c', 'iv' => 'i', 'tag' => 't'],
            ],
            'model' => 'jlu/gemma-4-26b-it',
            'completion' => true,
        ]);

        $updated = $this->actingAs($user)->postJson("/req/conv/updateMessage/{$conv->slug}", [
            'isAi' => true,
            'content' => [
                'text' => ['ciphertext' => 'c2', 'iv' => 'i2', 'tag' => 't2'],
            ],
            'model' => 'jlu/gemma-4-26b-it',
            'completion' => true,
            'message_id' => $message->message_id,
        ]);

        $sent->assertOk();
        $updated->assertOk();

        $this->assertSame(
            array_keys($sent->json('messageData')),
            array_keys($updated->json('messageData')),
            'both endpoints must hand the frontend the same message shape'
        );
    }
}
