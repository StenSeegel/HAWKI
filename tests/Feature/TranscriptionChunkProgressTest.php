<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Transcription\TranscriptionJob;
use App\Models\User;
use App\Services\Transcription\AsyncTranscriptionService;
use App\Services\Transcription\TranscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TranscriptionChunkProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_transcribe_chunks_sequential_updates_progress_in_manifest(): void
    {
        // 1. Setup Fake S3
        Storage::fake('s3');
        config(['filesystems.disks.s3.bucket' => 'audio-ingest']);

        // Write dummy chunks to fake S3
        $bucketPrefix = 's3://audio-ingest/';
        $chunkPaths = [
            'jobs/job123/chunks/chunk_000.wav',
            'jobs/job123/chunks/chunk_001.wav',
            'jobs/job123/chunks/chunk_002.wav',
        ];

        foreach ($chunkPaths as $path) {
            Storage::disk('s3')->put($path, 'dummy audio content');
        }

        // 2. Create User and Job
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'username' => 'testuser',
            'publicKey' => 'test-public-key',
            'employeetype' => 'staff',
            'auth_type' => 'local',
            'approval' => true,
        ]);

        $manifestData = [
            'job_id' => 'job123',
            'chunks' => [
                ['index' => 0, 'path' => $bucketPrefix.$chunkPaths[0], 'start' => 0, 'end' => 10],
                ['index' => 1, 'path' => $bucketPrefix.$chunkPaths[1], 'start' => 10, 'end' => 20],
                ['index' => 2, 'path' => $bucketPrefix.$chunkPaths[2], 'start' => 20, 'end' => 30],
            ],
        ];

        $job = TranscriptionJob::create([
            'id' => 'job123',
            'user_id' => $user->id,
            'status' => 'preprocessing',
            'file_path' => 'uploads/job123/original.mp3',
            'manifest_data' => $manifestData,
        ]);

        // 3. Mock TranscriptionService
        $mockTranscriptionService = $this->createMock(TranscriptionService::class);
        $mockTranscriptionService->expects($this->once())
            ->method('transcribeAudioParallel')
            ->willReturnCallback(function (array $audioFiles, ?string $language = null) {
                // Verify we check the database state *during* the execution of transcribeAudioParallel
                $job = TranscriptionJob::find('job123');
                $manifest = $job->manifest_data;

                $this->assertArrayHasKey('progress', $manifest);
                $this->assertIsArray($manifest['progress']);
                $this->assertEquals(3, $manifest['progress']['total_chunks']);
                $this->assertEquals('transcribing', $manifest['progress']['phase']);

                return [
                    0 => [
                        'segments' => [
                            ['start' => 0.0, 'end' => 5.0, 'text' => 'Hello', 'speaker' => 'SPEAKER_00'],
                        ],
                        'text' => 'Hello',
                    ],
                    1 => [
                        'segments' => [
                            ['start' => 0.0, 'end' => 5.0, 'text' => 'Hello', 'speaker' => 'SPEAKER_00'],
                        ],
                        'text' => 'Hello',
                    ],
                    2 => [
                        'segments' => [
                            ['start' => 0.0, 'end' => 5.0, 'text' => 'Hello', 'speaker' => 'SPEAKER_00'],
                        ],
                        'text' => 'Hello',
                    ],
                ];
            });

        $this->app->instance(TranscriptionService::class, $mockTranscriptionService);

        // 4. Run the parallel transcription
        $asyncService = app(AsyncTranscriptionService::class);

        $asyncService->processStatusUpdate([
            'job_id' => 'job123',
            'status' => 'completed',
            'worker_id' => 'test-worker',
            'manifest' => $manifestData,
        ]);

        // 5. Final assertions
        $finalJob = $job->fresh();
        $this->assertEquals('completed', $finalJob->status);
        $this->assertNotNull($finalJob->result_data);
        $this->assertEquals('Hello Hello Hello', $finalJob->result_data['text']);
    }
}
