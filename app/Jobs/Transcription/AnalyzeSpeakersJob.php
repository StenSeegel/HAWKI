<?php

declare(strict_types=1);

namespace App\Jobs\Transcription;

use App\Models\Transcription\TranscriptionJob;
use App\Services\Transcription\Providers\CustomSpeachesProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AnalyzeSpeakersJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * No automatic queue-level retry: postToServer() already retries transient
     * (connect-phase/5xx) failures internally, and a request that connected
     * fine but ran out of time mid-processing is deliberately not retried
     * (see postToServer's docblock) — firing the identical request again would
     * just burn the same amount of time for the same outcome.
     */
    public int $tries = 1;

    /**
     * Per-job override of the worker's --timeout, since this job runs a
     * whole-file diarization request that can legitimately take up to
     * config('transcription.diarization_timeout_ceiling') (default 3600s) —
     * far longer than the shared queue worker's default timeout, which is
     * sized for quick jobs like mail/broadcast. Laravel's Worker prefers a
     * job's own timeout() over the worker's --timeout flag, so this doesn't
     * require touching shared worker config.
     */
    public int $timeout;

    public function __construct(
        public TranscriptionJob $transcriptionJob
    ) {
        $this->timeout = (int) config('transcription.diarization_timeout_ceiling', 3600) + 300;
    }

    /**
     * Called by Laravel even when the job never reaches its own catch block —
     * e.g. when the queue worker kills it via its --timeout SIGALRM handler,
     * which terminates the process with exit() outside normal PHP exception
     * flow. Without this, a queue-level timeout/failure leaves
     * transcription_jobs.status stuck at 'analyzing_speakers' forever, with
     * no error surfaced to the user (verified empirically: handle()'s own
     * catch never runs in that path, but Laravel still calls failed() on the
     * job instance before the process exits).
     */
    public function failed(Throwable $exception): void
    {
        Log::error("AnalyzeSpeakersJob: job {$this->transcriptionJob->id} failed at the queue level: ".$exception->getMessage());
        $this->transcriptionJob->update([
            'status' => 'failed',
            'error_message' => 'Fehler bei der Sprecher-Analyse: '.$exception->getMessage(),
        ]);
    }

    public function handle(CustomSpeachesProvider $provider): void
    {
        // Breadcrumb timings so a slow run is diagnosable after the fact: the gap
        // between "Dispatched async speaker analysis command" (logged when queued)
        // and this line shows how long the detached CLI process took to actually
        // start; the gap between this and the S3-download line shows download time;
        // anything after that is the diarization request itself (see postToServer).
        $startedAt = microtime(true);
        Log::info("AnalyzeSpeakersJob: started for job {$this->transcriptionJob->id}.");

        $this->transcriptionJob->update(['status' => 'analyzing_speakers']);

        $snippetDuration = 5.0;

        $s3Disk = Storage::disk('s3');
        $originalKey = $this->transcriptionJob->file_path;
        $originalExt = pathinfo($originalKey, PATHINFO_EXTENSION);
        $tmpOriginalPath = sys_get_temp_dir().'/'.uniqid('analyze_').'.'.($originalExt ?: 'wav');

        try {
            $s3Stream = $s3Disk->readStream($originalKey);
            if (! $s3Stream) {
                throw new \Exception('Could not read original file from S3 for analysis');
            }
            $tmpStream = fopen($tmpOriginalPath, 'w+');
            stream_copy_to_stream($s3Stream, $tmpStream);
            fclose($s3Stream);
            fclose($tmpStream);

            $downloadElapsed = microtime(true) - $startedAt;
            Log::info(sprintf(
                "AnalyzeSpeakersJob: original file downloaded from S3 for job %s after %.1fs (%.1f MB), starting diarization request.",
                $this->transcriptionJob->id,
                $downloadElapsed,
                filesize($tmpOriginalPath) / (1024 * 1024)
            ));

            // Call provider to get diarization segments. The client-reported duration
            // (stashed on the job by dispatchAnalyzeJob) sizes the request timeout,
            // since this runs on the whole file rather than a chunk.
            $durationSeconds = $this->transcriptionJob->manifest_data['settings']['duration'] ?? null;
            $segments = $provider->analyzeSpeakers($tmpOriginalPath, [], $durationSeconds !== null ? (float) $durationSeconds : null);

            Log::info(sprintf(
                "AnalyzeSpeakersJob: diarization request finished for job %s after %.1fs total.",
                $this->transcriptionJob->id,
                microtime(true) - $startedAt
            ));

            $speakers = [];
            foreach ($segments as $segment) {
                $speakerName = $segment['speaker'];
                if (! isset($speakers[$speakerName])) {
                    $speakers[$speakerName] = [
                        'id' => $speakerName,
                        'label' => 'Unbekannt',
                        'segments' => [],
                    ];
                }
                $speakers[$speakerName]['segments'][] = [
                    'start' => $segment['start'],
                    'end' => $segment['end'],
                    'duration' => $segment['end'] - $segment['start'],
                ];
            }

            // Find the best snippets for each speaker
            $rawSpeakerList = [];
            foreach ($speakers as $speakerName => $data) {
                // Sort segments by duration descending to find the best samples
                $durationSorted = $data['segments'];
                usort($durationSorted, function ($a, $b) {
                    return $b['duration'] <=> $a['duration'];
                });

                // Pick up to 5 samples
                $topSamples = [];
                foreach ($durationSorted as $seg) {
                    $topSamples[] = [
                        'start' => $seg['start'],
                        'end' => min($seg['start'] + $snippetDuration, $seg['end']),
                    ];
                    if (count($topSamples) >= 5) {
                        break;
                    }
                }

                // Sort the top samples chronologically
                usort($topSamples, function ($a, $b) {
                    return $a['start'] <=> $b['start'];
                });

                // The main anchor is the earliest sample among the best ones
                $bestSegment = $topSamples[0] ?? null;

                if ($bestSegment) {
                    // Generate a presigned URL to the original S3 file so the browser can play it
                    $client = $s3Disk->getClient();
                    $command = $client->getCommand('GetObject', [
                        'Bucket' => config('filesystems.disks.s3.bucket'),
                        'Key' => $originalKey,
                    ]);
                    $request = $client->createPresignedRequest($command, '+120 minutes');
                    $presignedUrl = (string) $request->getUri();

                    // Rewrite internal S3 endpoint to public proxy endpoint to avoid Mixed Content errors
                    $s3Endpoint = config('filesystems.disks.s3.endpoint');
                    $appUrl = config('app.url'); // https://app.hawki.dev
                    if ($s3Endpoint && str_contains($presignedUrl, $s3Endpoint)) {
                        $presignedUrl = str_replace($s3Endpoint, rtrim($appUrl, '/').'/s3', $presignedUrl);
                    }

                    $rawSpeakerList[] = [
                        'id' => $speakerName,
                        'audio_url' => $presignedUrl,
                        'start' => $bestSegment['start'],
                        'end' => $bestSegment['end'],
                        'samples' => $topSamples,
                    ];
                }
            }

            // Sort chronologically by first appearance
            \Illuminate\Support\Facades\Log::info('Sorting speakers chronologically now!', ['count' => count($rawSpeakerList)]);
            usort($rawSpeakerList, function ($a, $b) {
                return $a['start'] <=> $b['start'];
            });

            // Assign labels in chronological order
            $speakerList = [];
            $speakerIndex = 1;
            foreach ($rawSpeakerList as $sp) {
                $sp['label'] = 'Stimme '.$speakerIndex++;
                $speakerList[] = $sp;
            }

            $manifest = $this->transcriptionJob->manifest_data ?? [];
            $manifest['speakers'] = $speakerList;
            $manifest['speaker_count'] = count($speakerList);

            $this->transcriptionJob->update([
                'status' => 'analyzed_speakers',
                'manifest_data' => $manifest,
            ]);

        } catch (Throwable $e) {
            Log::error("Failed to analyze speakers for job {$this->transcriptionJob->id}: ".$e->getMessage());
            $this->transcriptionJob->update([
                'status' => 'failed',
                'error_message' => 'Fehler bei der Sprecher-Analyse: '.$e->getMessage(),
            ]);
        } finally {
            @unlink($tmpOriginalPath);
        }
    }
}
