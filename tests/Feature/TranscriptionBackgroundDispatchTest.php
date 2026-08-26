<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\Transcription\AnalyzeSpeakersJob;
use App\Jobs\Transcription\ProcessTranscriptionJob;
use App\Models\Transcription\TranscriptionJob;
use App\Models\User;
use App\Services\Transcription\AsyncTranscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TranscriptionBackgroundDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_analyze_job_dispatches_to_transcription_queue(): void
    {
        Queue::fake();

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
            ->onlyMethods(['shouldRunSynchronously'])
            ->getMock();

        $mockService->method('shouldRunSynchronously')->willReturn(false);

        $mockService->dispatchAnalyzeJob($job, 1486.9);

        Queue::assertPushedOn('transcription', AnalyzeSpeakersJob::class, function ($dispatchedJob) use ($job) {
            return $dispatchedJob->transcriptionJob->id === $job->id
                && $dispatchedJob->connection === 'transcription';
        });

        $this->assertEquals('analyzing_speakers_queued', $job->fresh()->status);
        $this->assertEquals(1486.9, $job->fresh()->manifest_data['settings']['duration']);
    }

    public function test_analyze_speakers_job_failed_hook_marks_transcription_job_failed(): void
    {
        // Laravel calls a queued job's failed() method even when the queue
        // worker kills it via its --timeout SIGALRM handler, which bypasses
        // handle()'s own try/catch entirely (verified against a real
        // queue:work process during the investigation that motivated this
        // job's failed() hook). Without it, a queue-level timeout leaves
        // transcription_jobs.status stuck at 'analyzing_speakers' forever
        // with no error surfaced to the user.
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
            'id' => 'job-failed-hook-test',
            'user_id' => $user->id,
            'status' => 'analyzing_speakers',
            'file_path' => 'uploads/test/original.mp3',
        ]);

        (new AnalyzeSpeakersJob($job))->failed(new \Illuminate\Queue\TimeoutExceededException('AnalyzeSpeakersJob has timed out.'));

        $job->refresh();
        $this->assertEquals('failed', $job->status);
        $this->assertStringContainsString('timed out', $job->error_message);
    }

    public function test_process_status_update_dispatches_to_transcription_process_queue(): void
    {
        Queue::fake();

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
            ->onlyMethods(['shouldRunSynchronously'])
            ->getMock();

        $mockService->method('shouldRunSynchronously')->willReturn(false);

        $mockService->processStatusUpdate([
            'job_id' => 'job-process-test',
            'status' => 'completed',
            'worker_id' => 'test-worker',
            'manifest' => [
                'chunks' => [],
            ],
        ]);

        Queue::assertPushedOn('transcription_process', ProcessTranscriptionJob::class, function ($dispatchedJob) use ($job) {
            return $dispatchedJob->transcriptionJob->id === $job->id
                && $dispatchedJob->connection === 'transcription_process';
        });

        $this->assertEquals('preprocessed', $job->fresh()->status);
    }

    public function test_process_transcription_job_failed_hook_marks_transcription_job_failed(): void
    {
        // Same rationale as the AnalyzeSpeakersJob failed() test above: a
        // worker --timeout SIGALRM kill bypasses handle() entirely, so
        // without the failed() hook the job would sit at 'transcribing'
        // forever.
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
            'id' => 'job-process-failed-hook-test',
            'user_id' => $user->id,
            'status' => 'transcribing',
            'file_path' => 'uploads/test/original.mp3',
        ]);

        (new ProcessTranscriptionJob($job))->failed(new \Illuminate\Queue\TimeoutExceededException('ProcessTranscriptionJob has timed out.'));

        $job->refresh();
        $this->assertEquals('failed', $job->status);
        $this->assertStringContainsString('timed out', $job->error_message);
    }

    public function test_process_transcription_job_failed_hook_preserves_existing_failure_message(): void
    {
        // transcribeChunksParallel() marks the job failed itself with a more
        // specific message before rethrowing; the queue-level hook must not
        // overwrite it.
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
            'id' => 'job-process-failed-preserve-test',
            'user_id' => $user->id,
            'status' => 'failed',
            'error_message' => 'Transcription failed: chunk 3 could not be read from S3',
            'file_path' => 'uploads/test/original.mp3',
        ]);

        (new ProcessTranscriptionJob($job))->failed(new \RuntimeException('Failed to transcribe in parallel'));

        $job->refresh();
        $this->assertEquals('failed', $job->status);
        $this->assertEquals('Transcription failed: chunk 3 could not be read from S3', $job->error_message);
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
