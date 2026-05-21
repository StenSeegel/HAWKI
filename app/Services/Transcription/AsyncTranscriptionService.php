<?php

declare(strict_types=1);

namespace App\Services\Transcription;

use App\Models\Transcription\TranscriptionJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AsyncTranscriptionService
{
    /**
     * Erzeugt eine S3 Upload-Session inkl. Job-Datenbankeintrag.
     *
     * @param  int  $userId  Der aktuelle Benutzer
     * @param  string  $filename  Der ursprüngliche Dateiname
     * @return array Gibt die Job-ID, den S3 File Path und die Presigned URL (gültig für 60 Minuten) zurück.
     */
    public function generateUploadSession(int $userId, string $filename): array
    {
        $jobId = (string) Str::uuid();

        // Target path inside the S3 bucket
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $s3Path = "uploads/{$jobId}/original.{$extension}";

        // Persist the job
        $job = TranscriptionJob::create([
            'id' => $jobId,
            'user_id' => $userId,
            'file_path' => $s3Path,
            'status' => 'created',
        ]);

        // Generate presigned URL for the client to upload via PUT
        $s3Disk = Storage::disk('s3');
        /** @var \Aws\S3\S3Client $client */
        $client = $s3Disk->getClient();

        $command = $client->getCommand('PutObject', [
            'Bucket' => config('filesystems.disks.s3.bucket'),
            'Key' => $s3Path,
        ]);

        $presignedRequest = $client->createPresignedRequest($command, '+60 minutes');
        $presignedUrl = (string) $presignedRequest->getUri();

        // Rewrite internal S3 endpoint to public proxy endpoint to avoid Mixed Content errors
        $s3Endpoint = config('filesystems.disks.s3.endpoint');
        $appUrl = config('app.url'); // https://app.hawki.dev

        if ($s3Endpoint && str_contains($presignedUrl, $s3Endpoint)) {
            $presignedUrl = str_replace($s3Endpoint, rtrim($appUrl, '/').'/s3', $presignedUrl);
        }

        return [
            'job_id' => $job->id,
            's3_path' => $s3Path,
            'upload_url' => $presignedUrl,
        ];
    }

    /**
     * Reiht den Job in die Redis Queue für den Python Audio Preprocessor ein.
     */
    public function dispatchPreprocessingJob(TranscriptionJob $job): void
    {
        // Wir setzen voraus, dass s3://bucket/... als Pfad erwartet wird.
        $bucket = config('filesystems.disks.s3.bucket');
        $inputUri = "s3://{$bucket}/{$job->file_path}";
        $outputUri = "s3://{$bucket}/jobs/{$job->id}/";

        $payload = [
            'job_id' => $job->id,
            'input_path' => $inputUri,
            'output_path' => $outputUri,
            'language' => 'de',
            'options' => [
                'target_sample_rate' => 16000,
                'channels' => 1,
                'chunk_minutes' => 10,
                'overlap_seconds' => 3,
            ],
        ];

        // Push to Redis list
        $queueName = env('AUDIO_PREPROCESSING_QUEUE', 'audio_preprocessing_jobs');
        Redis::connection('audio_ingest')->lpush($queueName, json_encode($payload));

        $job->update(['status' => 'preprocessing']);
        Log::info("Dispatched async transcription preprocessing for job {$job->id}");
    }

    /**
     * Verarbeitet Status-Updates vom Python Worker, die via Daemon ausgelesen werden.
     */
    public function processStatusUpdate(array $payload): void
    {
        $jobId = $payload['job_id'] ?? null;
        if (! $jobId) {
            Log::warning('Received audio processing status without job_id', ['payload' => $payload]);

            return;
        }

        $job = TranscriptionJob::find($jobId);
        if (! $job) {
            Log::warning("Transcription job {$jobId} not found in database.");

            return;
        }

        $status = $payload['status'] ?? 'unknown';

        if ($status === 'completed') {
            $workerId = $payload['worker_id'] ?? 'unknown';
            $job->update([
                'status' => 'preprocessed',
                'manifest_data' => $payload['manifest'] ?? null,
            ]);
            Log::info("Job {$jobId} preprocessed successfully by worker '{$workerId}'. Ready for transcription.");

            $this->transcribeChunksSequential($job);

        } elseif ($status === 'failed') {
            $errorMessage = $payload['error'] ?? 'Unknown error';
            $job->update([
                'status' => 'failed',
                'error_message' => $errorMessage,
            ]);

            // Fehler explizit werfen, damit der Exception Handler / Daemon ihn registriert
            throw new \RuntimeException("Job {$jobId} preprocessing failed: {$errorMessage}");
        }
    }

    protected function transcribeChunksSequential(TranscriptionJob $job): void
    {
        $manifest = $job->manifest_data;
        if (empty($manifest['chunks'])) {
            $job->update(['status' => 'failed', 'error_message' => 'No chunks in manifest']);
            throw new \RuntimeException("No chunks found in manifest for job {$job->id}");
        }

        $job->update(['status' => 'transcribing']);

        $transcriptionService = app(\App\Services\Transcription\TranscriptionService::class);
        $s3Disk = Storage::disk('s3');
        $allSegments = [];
        $fullText = '';

        foreach ($manifest['chunks'] as $chunk) {
            $chunkPath = $chunk['path']; // e.g. "s3://bucket/jobs/job-id/chunks/chunk_000.wav"
            $bucketPrefix = 's3://'.config('filesystems.disks.s3.bucket').'/';
            $chunkKey = str_replace($bucketPrefix, '', $chunkPath);

            $startTime = (float) ($chunk['start'] ?? 0);
            $fileName = basename($chunkKey);
            $tmpPath = sys_get_temp_dir().'/'.$fileName;

            try {
                $s3Stream = $s3Disk->readStream($chunkKey);
                if (! $s3Stream) {
                    throw new \Exception("Could not read chunk {$fileName} from S3");
                }

                $tmpStream = fopen($tmpPath, 'w+');
                stream_copy_to_stream($s3Stream, $tmpStream);
                fclose($s3Stream);
                fclose($tmpStream);

                $uploadedFile = new \Illuminate\Http\UploadedFile(
                    $tmpPath,
                    $fileName,
                    'audio/wav',
                    null,
                    true
                );

                $result = $transcriptionService->transcribeAudio($uploadedFile, 'de');

                if (! empty($result['segments'])) {
                    foreach ($result['segments'] as $segment) {
                        $segment['start'] += $startTime;
                        $segment['end'] += $startTime;
                        $allSegments[] = $segment;
                    }
                }
                if (! empty($result['text'])) {
                    $fullText .= ' '.trim($result['text']);
                }

                @unlink($tmpPath);
            } catch (\Exception $e) {
                $job->update(['status' => 'failed', 'error_message' => 'Chunk failed: '.$e->getMessage()]);
                @unlink($tmpPath);

                // Fehler weiterwerfen, damit der Daemon ihn melden kann
                throw new \RuntimeException("Failed to transcribe chunk {$fileName} for job {$job->id}: ".$e->getMessage(), 0, $e);
            }
        }

        $job->update([
            'status' => 'completed',
            'result_data' => [
                'text' => trim($fullText),
                'segments' => $allSegments,
                'success' => true,
            ],
        ]);
        Log::info("Job {$job->id} completed transcription successfully.");
    }
}
