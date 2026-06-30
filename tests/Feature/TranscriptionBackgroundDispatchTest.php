<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Transcription\TranscriptionJob;
use App\Models\User;
use App\Services\Transcription\AsyncTranscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranscriptionBackgroundDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_analyze_job_spawns_background_process(): void
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
            'id' => 'job-analyze-test',
            'user_id' => $user->id,
            'status' => 'created',
            'file_path' => 'uploads/test/original.mp3',
        ]);

        $mockService = $this->getMockBuilder(AsyncTranscriptionService::class)
            ->onlyMethods(['executeShellCommand', 'getPhpBinary', 'shouldRunSynchronously'])
            ->getMock();

        $mockService->method('getPhpBinary')->willReturn('/usr/bin/php');
        $mockService->method('shouldRunSynchronously')->willReturn(false);

        $mockService->expects($this->once())
            ->method('executeShellCommand')
            ->with($this->callback(function ($command) {
                return str_contains($command, '/usr/bin/php')
                    && str_contains($command, 'artisan')
                    && str_contains($command, 'transcription:analyze-speakers')
                    && str_contains($command, 'job-analyze-test')
                    && str_contains($command, '>');
            }));

        $mockService->dispatchAnalyzeJob($job);

        $this->assertEquals('analyzing_speakers_queued', $job->fresh()->status);
    }

    public function test_process_status_update_spawns_background_process(): void
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
            'id' => 'job-process-test',
            'user_id' => $user->id,
            'status' => 'preprocessing',
            'file_path' => 'uploads/test/original.mp3',
        ]);

        $mockService = $this->getMockBuilder(AsyncTranscriptionService::class)
            ->onlyMethods(['executeShellCommand', 'getPhpBinary', 'shouldRunSynchronously'])
            ->getMock();

        $mockService->method('getPhpBinary')->willReturn('/usr/bin/php');
        $mockService->method('shouldRunSynchronously')->willReturn(false);

        $mockService->expects($this->once())
            ->method('executeShellCommand')
            ->with($this->callback(function ($command) {
                return str_contains($command, '/usr/bin/php')
                    && str_contains($command, 'artisan')
                    && str_contains($command, 'transcription:process-job')
                    && str_contains($command, 'job-process-test')
                    && str_contains($command, '>');
            }));

        $mockService->processStatusUpdate([
            'job_id' => 'job-process-test',
            'status' => 'completed',
            'worker_id' => 'test-worker',
            'manifest' => [
                'chunks' => [],
            ],
        ]);

        $this->assertEquals('preprocessed', $job->fresh()->status);
    }

    public function test_transcribe_chunks_parallel_runs_when_concurrency_limit_not_exceeded(): void
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

        $newJob = TranscriptionJob::create([
            'id' => 'job-new-2',
            'user_id' => $user->id,
            'status' => 'preprocessed',
            'file_path' => 'uploads/test/new.mp3',
            'manifest_data' => [
                'chunks' => [],
            ],
        ]);

        $service = app(AsyncTranscriptionService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No chunks found in manifest');

        $service->transcribeChunksParallel($newJob);
    }
}
