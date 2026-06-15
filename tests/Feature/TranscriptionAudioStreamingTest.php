<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Transcription\AsyncTranscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranscriptionAudioStreamingTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $email, string $username): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => $email,
            'username' => $username,
            'publicKey' => 'test-public-key',
            'employeetype' => 'staff',
            'auth_type' => 'local',
            'approval' => true,
        ]);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/req/transcription/audio?job_id=job123');
        $response->assertStatus(401);
    }

    public function test_get_presigned_url_with_job_id(): void
    {
        $user = $this->createUser('test@example.com', 'testuser');

        $mockAsyncService = $this->createMock(AsyncTranscriptionService::class);
        $mockAsyncService->expects($this->once())
            ->method('getAudioPresignedUrl')
            ->with($user->id, 'job123', null, null)
            ->willReturn('https://s3.example.com/audio.mp3');

        $this->app->instance(AsyncTranscriptionService::class, $mockAsyncService);

        $response = $this->actingAs($user)
            ->getJson('/req/transcription/audio?job_id=job123');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'url' => 'https://s3.example.com/audio.mp3',
        ]);
    }

    public function test_get_presigned_url_with_slug_and_index(): void
    {
        $user = $this->createUser('test@example.com', 'testuser');

        $mockAsyncService = $this->createMock(AsyncTranscriptionService::class);
        $mockAsyncService->expects($this->once())
            ->method('getAudioPresignedUrl')
            ->with($user->id, null, 'slug123', 1)
            ->willReturn('https://s3.example.com/audio2.mp3');

        $this->app->instance(AsyncTranscriptionService::class, $mockAsyncService);

        $response = $this->actingAs($user)
            ->getJson('/req/transcription/audio?slug=slug123&index=1');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'url' => 'https://s3.example.com/audio2.mp3',
        ]);
    }

    public function test_get_presigned_url_not_found(): void
    {
        $user = $this->createUser('test@example.com', 'testuser');

        $mockAsyncService = $this->createMock(AsyncTranscriptionService::class);
        $mockAsyncService->method('getAudioPresignedUrl')
            ->willReturn(null);

        $this->app->instance(AsyncTranscriptionService::class, $mockAsyncService);

        $response = $this->actingAs($user)
            ->getJson('/req/transcription/audio?job_id=job123');

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Audio-Datei nicht gefunden oder kein Zugriff.',
        ]);
    }

    public function test_resolves_actual_presigned_url_with_chronological_fallback(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        $user = $this->createUser('test@example.com', 'testuser');

        // Create a Transcription with old metadata style (no job_id)
        $transcription = \App\Models\Transcription\Transcription::create([
            'title' => 'Old Transkription',
            'user_id' => $user->id,
            'language' => 'de',
            'user_locale' => 'de',
            'duration' => 300,
            'model_used' => 'test-model',
            'provider' => 'test-provider',
            'original_filename' => 'file1.mp3, file2.mp3',
            'file_size' => 12345,
            'metadata' => [
                'source_files' => [
                    ['name' => 'file1.mp3', 'duration' => 150, 'start_time' => 0, 'end_time' => 150],
                    ['name' => 'file2.mp3', 'duration' => 150, 'start_time' => 150, 'end_time' => 300],
                ],
            ],
        ]);

        // Create matching jobs created around the same time
        $job1 = \App\Models\Transcription\TranscriptionJob::create([
            'id' => 'job-uuid-1',
            'user_id' => $user->id,
            'status' => 'completed',
            'file_path' => 'uploads/job-uuid-1/original.mp3',
            'manifest_data' => [],
            'created_at' => $transcription->created_at->copy()->subMinutes(10),
        ]);
        $job2 = \App\Models\Transcription\TranscriptionJob::create([
            'id' => 'job-uuid-2',
            'user_id' => $user->id,
            'status' => 'completed',
            'file_path' => 'uploads/job-uuid-2/original.mp3',
            'manifest_data' => [],
            'created_at' => $transcription->created_at->copy()->subMinutes(5),
        ]);

        // Put fake files on S3 disk
        \Illuminate\Support\Facades\Storage::disk('s3')->put($job1->file_path, 'fake audio content 1');
        \Illuminate\Support\Facades\Storage::disk('s3')->put($job2->file_path, 'fake audio content 2');

        // Execute endpoint request for index 0
        $response1 = $this->actingAs($user)
            ->getJson("/req/transcription/audio?slug={$transcription->slug}&index=0");

        $response1->assertStatus(200);
        $response1->assertJsonStructure(['success', 'url']);
        $this->assertTrue($response1->json('success'));
        $this->assertStringContainsString('uploads/job-uuid-1/original.mp3', $response1->json('url'));

        // Execute endpoint request for index 1
        $response2 = $this->actingAs($user)
            ->getJson("/req/transcription/audio?slug={$transcription->slug}&index=1");

        $response2->assertStatus(200);
        $response2->assertJsonStructure(['success', 'url']);
        $this->assertTrue($response2->json('success'));
        $this->assertStringContainsString('uploads/job-uuid-2/original.mp3', $response2->json('url'));
    }

    public function test_resolves_actual_presigned_url_with_size_based_matching(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        $user = $this->createUser('test@example.com', 'testuser');

        // Create a Transcription with old metadata style (no job_id), but with size metadata
        $transcription = \App\Models\Transcription\Transcription::create([
            'title' => 'Size Transkription',
            'user_id' => $user->id,
            'language' => 'de',
            'user_locale' => 'de',
            'duration' => 300,
            'model_used' => 'test-model',
            'provider' => 'test-provider',
            'original_filename' => 'file1.mp3, file2.mp3',
            'file_size' => 12345,
            'metadata' => [
                'source_files' => [
                    ['name' => 'file1.mp3', 'size' => 1000, 'duration' => 150, 'start_time' => 0, 'end_time' => 150],
                    ['name' => 'file2.mp3', 'size' => 2000, 'duration' => 150, 'start_time' => 150, 'end_time' => 300],
                ],
            ],
        ]);

        // Create jobs created at the EXACT SAME TIME, which normally might be sorted randomly/alphabetically by UUID.
        $sameTime = $transcription->created_at->copy()->subMinutes(5);

        $job1 = \App\Models\Transcription\TranscriptionJob::create([
            'id' => 'b-job-uuid',
            'user_id' => $user->id,
            'status' => 'completed',
            'file_path' => 'uploads/b-job-uuid/original.mp3',
            'manifest_data' => [],
            'created_at' => $sameTime,
        ]);
        $job2 = \App\Models\Transcription\TranscriptionJob::create([
            'id' => 'a-job-uuid',
            'user_id' => $user->id,
            'status' => 'completed',
            'file_path' => 'uploads/a-job-uuid/original.mp3',
            'manifest_data' => [],
            'created_at' => $sameTime,
        ]);

        // Write fake files with different sizes
        \Illuminate\Support\Facades\Storage::disk('s3')->put($job1->file_path, str_repeat('a', 2000));
        \Illuminate\Support\Facades\Storage::disk('s3')->put($job2->file_path, str_repeat('b', 1000));

        // Execute endpoint request for index 0 (should match job2 which has size 1000)
        $response1 = $this->actingAs($user)
            ->getJson("/req/transcription/audio?slug={$transcription->slug}&index=0");

        $response1->assertStatus(200);
        $response1->assertJsonStructure(['success', 'url']);
        $this->assertTrue($response1->json('success'));
        $this->assertStringContainsString('uploads/a-job-uuid/original.mp3', $response1->json('url'));

        // Execute endpoint request for index 1 (should match job1 which has size 2000)
        $response2 = $this->actingAs($user)
            ->getJson("/req/transcription/audio?slug={$transcription->slug}&index=1");

        $response2->assertStatus(200);
        $response2->assertJsonStructure(['success', 'url']);
        $this->assertTrue($response2->json('success'));
        $this->assertStringContainsString('uploads/b-job-uuid/original.mp3', $response2->json('url'));
    }
}
