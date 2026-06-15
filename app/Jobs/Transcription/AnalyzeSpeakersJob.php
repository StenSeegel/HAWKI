<?php

declare(strict_types=1);

namespace App\Jobs\Transcription;

use App\Models\Transcription\TranscriptionJob;
use App\Services\Transcription\Providers\CustomSpeachesProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AnalyzeSpeakersJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public TranscriptionJob $transcriptionJob
    ) {}

    public function handle(CustomSpeachesProvider $provider): void
    {
        $this->transcriptionJob->update(['status' => 'analyzing_speakers']);

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

            // Call provider to get diarization segments
            $segments = $provider->analyzeSpeakers($tmpOriginalPath);

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
                        'end' => min($seg['start'] + 5.0, $seg['end']),
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
                $sp['label'] = 'Sprecher '.$speakerIndex++;
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
