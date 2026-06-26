<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Transcription\TranscriptionJob;
use App\Models\Transcription\TranscriptionSetting;
use App\Models\User;
use App\Services\Transcription\AsyncTranscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TranscriptionChunkParallelTest extends TestCase
{
    use RefreshDatabase;

    public function test_transcribe_chunks_parallel_distributes_requests_and_merges_properly(): void
    {
        // 1. Setup Fake S3
        Storage::fake('s3');
        config(['filesystems.disks.s3.bucket' => 'audio-ingest']);

        $bucketPrefix = 's3://audio-ingest/';
        $chunkPaths = [
            'jobs/job123/chunks/chunk_000.wav',
            'jobs/job123/chunks/chunk_001.wav',
            'jobs/job123/chunks/chunk_002.wav',
        ];

        foreach ($chunkPaths as $path) {
            Storage::disk('s3')->put($path, 'dummy audio content');
        }

        // 2. Setup Multi-Worker Settings in Database
        TranscriptionSetting::where('key', 'base_url')->update([
            'value' => 'http://134.176.150.177:8001/v1, http://134.176.150.177:8002/v1',
        ]);
        TranscriptionSetting::where('key', 'api_key')->update([
            'value' => 'test-api-key',
        ]);
        TranscriptionSetting::where('key', 'model')->update([
            'value' => 'Systran/faster-whisper-large-v3',
        ]);

        // 3. Create User and Job (disable diarization for simplicity by setting speaker_count to '1')
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
            'settings' => [
                'language' => 'de',
                'speaker_count' => '1', // 1 speaker disables full-file diarization
            ],
            'chunks' => [
                ['index' => 0, 'path' => $bucketPrefix.$chunkPaths[0], 'start' => 0.0, 'end' => 10.0],
                ['index' => 1, 'path' => $bucketPrefix.$chunkPaths[1], 'start' => 10.0, 'end' => 20.0],
                ['index' => 2, 'path' => $bucketPrefix.$chunkPaths[2], 'start' => 20.0, 'end' => 30.0],
            ],
        ];

        $job = TranscriptionJob::create([
            'id' => 'job123',
            'user_id' => $user->id,
            'status' => 'preprocessing',
            'file_path' => 'uploads/job123/original.mp3',
            'manifest_data' => $manifestData,
        ]);

        // 4. Setup Http::fake to mock parallel requests
        $requestedUrls = [];
        Http::fake([
            '134.176.150.177:8001/*' => function ($request) use (&$requestedUrls) {
                $requestedUrls[] = $request->url();
                if (str_contains($request->body(), 'chunk_002.wav')) {
                    return Http::response([
                        'text' => 'Segment 2 text',
                        'segments' => [
                            ['start' => 0.0, 'end' => 5.0, 'text' => 'Segment 2 text', 'speaker' => 'SPEAKER_00'],
                        ],
                    ], 200);
                }

                return Http::response([
                    'text' => 'Segment 0 text',
                    'segments' => [
                        ['start' => 0.0, 'end' => 5.0, 'text' => 'Segment 0 text', 'speaker' => 'SPEAKER_00'],
                    ],
                ], 200);
            },
            '134.176.150.177:8002/*' => function ($request) use (&$requestedUrls) {
                $requestedUrls[] = $request->url();

                return Http::response([
                    'text' => 'Segment 1 text',
                    'segments' => [
                        ['start' => 0.0, 'end' => 5.0, 'text' => 'Segment 1 text', 'speaker' => 'SPEAKER_00'],
                    ],
                ], 200);
            },
        ]);

        // 5. Run parallel transcription
        $asyncService = app(AsyncTranscriptionService::class);
        $asyncService->processStatusUpdate([
            'job_id' => 'job123',
            'status' => 'completed',
            'worker_id' => 'test-worker',
            'manifest' => $manifestData,
        ]);

        // 6. Final assertions
        $finalJob = $job->fresh();
        $this->assertEquals('completed', $finalJob->status);
        $this->assertNotNull($finalJob->result_data);

        $result = $finalJob->result_data;

        // Verify segments text and timestamps are merged and shifted correctly
        $this->assertCount(3, $result['segments']);
        $this->assertEquals('Segment 0 text', $result['segments'][0]['text']);
        $this->assertEquals(0.0, $result['segments'][0]['start']);
        $this->assertEquals(5.0, $result['segments'][0]['end']);

        $this->assertEquals('Segment 1 text', $result['segments'][1]['text']);
        $this->assertEquals(10.0, $result['segments'][1]['start']);
        $this->assertEquals(15.0, $result['segments'][1]['end']);

        $this->assertEquals('Segment 2 text', $result['segments'][2]['text']);
        $this->assertEquals(20.0, $result['segments'][2]['start']);
        $this->assertEquals(25.0, $result['segments'][2]['end']);

        $this->assertEquals('Segment 0 text Segment 1 text Segment 2 text', $result['text']);

        // Verify requests were distributed round-robin:
        // Chunk 0 -> worker 1 (port 8001)
        // Chunk 1 -> worker 2 (port 8002)
        // Chunk 2 -> worker 1 (port 8001)
        $this->assertCount(3, $requestedUrls);
        $this->assertEquals('http://134.176.150.177:8001/v1/audio/transcriptions', $requestedUrls[0]);
        $this->assertEquals('http://134.176.150.177:8002/v1/audio/transcriptions', $requestedUrls[1]);
        $this->assertEquals('http://134.176.150.177:8001/v1/audio/transcriptions', $requestedUrls[2]);

        // Verify temporary files on disk are deleted
        $tmpWav0 = sys_get_temp_dir().'/chunk_000.wav';
        $tmpWav1 = sys_get_temp_dir().'/chunk_001.wav';
        $tmpWav2 = sys_get_temp_dir().'/chunk_002.wav';
        $this->assertFileDoesNotExist($tmpWav0);
        $this->assertFileDoesNotExist($tmpWav1);
        $this->assertFileDoesNotExist($tmpWav2);
    }
}
