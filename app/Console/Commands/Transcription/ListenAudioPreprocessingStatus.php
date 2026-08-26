<?php

declare(strict_types=1);

namespace App\Console\Commands\Transcription;

use App\Services\Transcription\AsyncTranscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ListenAudioPreprocessingStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transcription:listen-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Listen to the Redis queue for audio preprocessing status updates from the Python worker';

    /**
     * Execute the console command.
     */
    public function handle(AsyncTranscriptionService $transcriptionService): void
    {
        $queueName = env('AUDIO_STATUS_QUEUE', 'audio_preprocessing_status');
        $this->info("Listening for status updates on Redis queue: {$queueName}");

        while (true) {
            try {
                // Block until an item is available on the list, timeout after 60s to prevent idle connection drops.
                // Returned format is usually [queue_name, item]
                $result = Redis::connection('audio_ingest')->blpop($queueName, 60);

                if ($result) {
                    $payloadJson = $result[1];
                    $payload = json_decode($payloadJson, true);

                    if (is_array($payload)) {
                        $this->info('Received update for job: '.($payload['job_id'] ?? 'unknown'));
                        $transcriptionService->processStatusUpdate($payload);
                    } else {
                        Log::warning("Received invalid JSON payload on {$queueName}: {$payloadJson}");
                    }
                }
            } catch (\Exception $e) {
                Log::error('Error in ListenAudioPreprocessingStatus daemon: '.$e->getMessage());
                $this->error('Error: '.$e->getMessage());

                // Explizit an den Laravel Exception Handler (z.B. Sentry/Bugsnag) melden
                report($e);

                // Sleep to avoid tight loops on connection issues
                sleep(5);
            }

            // Prevent memory leaks by exiting if we use too much memory.
            // Supervisor will automatically restart the daemon.
            if (memory_get_usage(true) > 256 * 1024 * 1024) {
                Log::warning('ListenAudioPreprocessingStatus: Memory limit exceeded (256MB). Exiting to allow Supervisor to restart.');
                exit(1);
            }
        }
    }
}
