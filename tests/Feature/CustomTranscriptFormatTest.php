<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Transcription\CustomTranscriptFormat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomTranscriptFormatTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'username' => 'testuser',
            'publicKey' => 'test-public-key',
            'employeetype' => 'staff',
            'auth_type' => 'local',
            'approval' => true,
        ]);
    }

    public function test_can_list_custom_formats(): void
    {
        CustomTranscriptFormat::create([
            'user_id' => $this->user->id,
            'name' => 'Format A',
            'speakers' => true,
            'timestamps' => true,
            'avatars' => false,
            'bubbles' => true,
            'anonymize' => false,
            'order' => 'chronological',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/req/transcription/formats');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(1, 'formats');
        $response->assertJsonFragment([
            'name' => 'Format A',
            'speakers' => true,
        ]);
    }

    public function test_cannot_list_other_users_custom_formats(): void
    {
        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'username' => 'otheruser',
            'publicKey' => 'other-public-key',
            'employeetype' => 'staff',
            'auth_type' => 'local',
            'approval' => true,
        ]);

        CustomTranscriptFormat::create([
            'user_id' => $otherUser->id,
            'name' => 'Other Format',
            'speakers' => true,
            'timestamps' => true,
            'avatars' => false,
            'bubbles' => true,
            'anonymize' => false,
            'order' => 'chronological',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/req/transcription/formats');

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'formats');
    }

    public function test_can_create_custom_format(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/req/transcription/formats', [
                'name' => 'My New Format',
                'speakers' => true,
                'timestamps' => false,
                'avatars' => true,
                'bubbles' => false,
                'anonymize' => true,
                'order' => 'speaker',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('custom_transcript_formats', [
            'user_id' => $this->user->id,
            'name' => 'My New Format',
            'speakers' => 1,
            'timestamps' => 0,
            'order' => 'speaker',
        ]);
    }

    public function test_can_update_existing_custom_format(): void
    {
        $format = CustomTranscriptFormat::create([
            'user_id' => $this->user->id,
            'name' => 'Original Format',
            'speakers' => true,
            'timestamps' => true,
            'avatars' => false,
            'bubbles' => true,
            'anonymize' => false,
            'order' => 'chronological',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/req/transcription/formats', [
                'id' => $format->id,
                'name' => 'Updated Format',
                'speakers' => false,
                'timestamps' => true,
                'avatars' => false,
                'bubbles' => true,
                'anonymize' => false,
                'order' => 'chronological',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('custom_transcript_formats', [
            'id' => $format->id,
            'name' => 'Updated Format',
            'speakers' => 0,
        ]);
    }

    public function test_cannot_update_other_users_custom_format(): void
    {
        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'username' => 'otheruser',
            'publicKey' => 'other-public-key',
            'employeetype' => 'staff',
            'auth_type' => 'local',
            'approval' => true,
        ]);

        $format = CustomTranscriptFormat::create([
            'user_id' => $otherUser->id,
            'name' => 'Other Format',
            'speakers' => true,
            'timestamps' => true,
            'avatars' => false,
            'bubbles' => true,
            'anonymize' => false,
            'order' => 'chronological',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/req/transcription/formats', [
                'id' => $format->id,
                'name' => 'Hacked Format',
                'speakers' => false,
                'timestamps' => true,
                'avatars' => false,
                'bubbles' => true,
                'anonymize' => false,
                'order' => 'chronological',
            ]);

        $response->assertStatus(500);
    }

    public function test_can_delete_custom_format(): void
    {
        $format = CustomTranscriptFormat::create([
            'user_id' => $this->user->id,
            'name' => 'Format to Delete',
            'speakers' => true,
            'timestamps' => true,
            'avatars' => false,
            'bubbles' => true,
            'anonymize' => false,
            'order' => 'chronological',
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/req/transcription/formats/{$format->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('custom_transcript_formats', [
            'id' => $format->id,
        ]);
    }
}
