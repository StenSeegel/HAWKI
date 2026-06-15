<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Transcription\TranscriptionJob;
use App\Models\User;
use App\Services\Transcription\AsyncTranscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LlmCorrectionTranscriptionJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_job_saves_llm_correction_in_manifest(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'username' => 'testuser',
            'publicKey' => 'test-public-key',
            'employeetype' => 'staff',
            'auth_type' => 'local',
            'approval' => true,
        ]);

        $job = TranscriptionJob::create([
            'id' => 'job123',
            'user_id' => $user->id,
            'status' => 'preprocessing',
            'file_path' => 'uploads/job123/original.mp3',
            'manifest_data' => [],
        ]);

        $mockAsyncService = $this->createMock(AsyncTranscriptionService::class);
        $mockAsyncService->expects($this->once())
            ->method('dispatchPreprocessingJob')
            ->with($this->callback(function ($passedJob) {
                return $passedJob->id === 'job123';
            }));
        $this->app->instance(AsyncTranscriptionService::class, $mockAsyncService);

        $response = $this->actingAs($user)
            ->postJson("/req/transcription/async/dispatch/{$job->id}", [
                'speaker_count' => 'auto',
                'llm_correction' => 1,
            ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $job->refresh();
        $this->assertTrue($job->manifest_data['settings']['llm_correction']);
    }

    public function test_optimize_speakers_endpoint_delegates_to_async_transcription_service(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'username' => 'testuser',
            'publicKey' => 'test-public-key',
            'employeetype' => 'staff',
            'auth_type' => 'local',
            'approval' => true,
        ]);

        $segments = [
            ['id' => 1, 'start' => 0.0, 'end' => 5.0, 'text' => 'Hello', 'speaker' => 'Sprecher 1'],
        ];

        $mockAsyncService = $this->createMock(AsyncTranscriptionService::class);
        $mockAsyncService->expects($this->once())
            ->method('optimizeTranscriptSpeakers')
            ->with($segments, 'gpt-4o')
            ->willReturn([
                ['id' => 1, 'start' => 0.0, 'end' => 5.0, 'text' => 'Hello Optimized', 'speaker' => 'Sprecher 1'],
            ]);
        $this->app->instance(AsyncTranscriptionService::class, $mockAsyncService);

        $response = $this->actingAs($user)
            ->postJson('/req/transcription/optimize-speakers', [
                'segments' => $segments,
                'model' => 'gpt-4o',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'segments' => [
                ['id' => 1, 'start' => 0.0, 'end' => 5.0, 'text' => 'Hello Optimized', 'speaker' => 'Sprecher 1'],
            ],
        ]);
    }
}
