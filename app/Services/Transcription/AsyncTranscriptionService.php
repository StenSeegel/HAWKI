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
    public function generateUploadSession(int $userId, string $filename, string $language = 'auto', string $speakerCount = 'auto'): array
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
            'manifest_data' => [
                'settings' => [
                    'language' => $language,
                    'speaker_count' => $speakerCount,
                ],
            ],
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

        $settings = $job->manifest_data['settings'] ?? [];
        $language = $settings['language'] ?? 'auto';

        $payload = [
            'job_id' => $job->id,
            'input_path' => $inputUri,
            'output_path' => $outputUri,
            'language' => $language === 'auto' ? null : $language,
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

            // Preserve settings when worker returns new manifest
            $oldManifest = $job->manifest_data ?? [];
            $newManifest = $payload['manifest'] ?? [];
            $newManifest['settings'] = $oldManifest['settings'] ?? [];

            $job->update([
                'status' => 'preprocessed',
                'manifest_data' => $newManifest,
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

        $settings = $manifest['settings'] ?? [];
        $language = $settings['language'] ?? 'auto';
        $language = $language === 'auto' ? null : $language;
        $speakerCount = $settings['speaker_count'] ?? 'auto';
        $diarize = ($speakerCount === '1') ? false : true;

        $job->update(['status' => 'transcribing']);

        $transcriptionService = app(\App\Services\Transcription\TranscriptionService::class);
        $s3Disk = Storage::disk('s3');
        $allSegments = [];
        $allWords = [];
        $fullText = '';

        $totalChunks = count($manifest['chunks']);
        $currentChunkIndex = 0;

        foreach ($manifest['chunks'] as $chunk) {
            $currentChunkIndex++;
            $manifest['progress'] = [
                'current_chunk' => $currentChunkIndex,
                'total_chunks' => $totalChunks,
                'phase' => 'transcribing',
            ];
            $job->update([
                'status' => 'transcribing',
                'manifest_data' => $manifest,
            ]);

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

                $result = $transcriptionService->transcribeAudio($uploadedFile, $language, function ($state) use ($job, &$manifest, $currentChunkIndex, $totalChunks) {
                    if ($state === 'diarizing') {
                        $manifest['progress'] = [
                            'current_chunk' => $currentChunkIndex,
                            'total_chunks' => $totalChunks,
                            'phase' => 'diarizing',
                        ];
                        $job->update([
                            'status' => 'transcribing',
                            'manifest_data' => $manifest,
                        ]);
                    }
                }, false);

                if (! empty($result['segments'])) {
                    foreach ($result['segments'] as $segment) {
                        $segment['start'] += $startTime;
                        $segment['end'] += $startTime;

                        // Auch die Wörter innerhalb des Segments anpassen, falls vorhanden
                        if (isset($segment['words']) && is_array($segment['words'])) {
                            foreach ($segment['words'] as &$w) {
                                $w['start'] += $startTime;
                                $w['end'] += $startTime;
                            }
                        }

                        $allSegments[] = $segment;
                    }
                }
                if (! empty($result['words'])) {
                    foreach ($result['words'] as $word) {
                        $word['start'] += $startTime;
                        $word['end'] += $startTime;
                        $allWords[] = $word;
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

        $mergedResult = [
            'text' => trim($fullText),
            'segments' => $allSegments,
            'words' => $allWords,
            'language' => $language ?? 'de',
            'success' => true,
        ];

        if ($diarize) {
            // Diarization over the original file
            $manifest['progress'] = [
                'phase' => 'diarizing',
                'current_chunk' => 0,
                'total_chunks' => 0,
            ];
            $job->update([
                'status' => 'transcribing',
                'manifest_data' => $manifest,
            ]);

            $originalKey = $job->file_path;
            $originalExt = pathinfo($originalKey, PATHINFO_EXTENSION);
            $tmpOriginalPath = sys_get_temp_dir().'/'.uniqid('original_').'.'.($originalExt ?: 'wav');

            try {
                $s3Stream = $s3Disk->readStream($originalKey);
                if ($s3Stream) {
                    $tmpStream = fopen($tmpOriginalPath, 'w+');
                    stream_copy_to_stream($s3Stream, $tmpStream);
                    fclose($s3Stream);
                    fclose($tmpStream);

                    $diarizationOptions = [];
                    if ($speakerCount !== 'auto' && is_numeric($speakerCount)) {
                        $diarizationOptions['num_speakers'] = (int) $speakerCount;
                    }

                    $mergedResult = $transcriptionService->diarizeAudio($tmpOriginalPath, $mergedResult, $diarizationOptions);

                    @unlink($tmpOriginalPath);
                }
            } catch (\Exception $e) {
                Log::warning("Failed to run diarization on full file for job {$job->id}: ".$e->getMessage());
            }
        }

        // Job abschließen
        $job->update([
            'status' => 'completed',
            'result_data' => $mergedResult,
        ]);
        Log::info("Job {$job->id} completed transcription successfully.");
    }
}
