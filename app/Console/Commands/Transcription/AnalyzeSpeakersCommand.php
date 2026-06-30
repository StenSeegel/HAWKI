<?php

declare(strict_types=1);

namespace App\Console\Commands\Transcription;

use App\Jobs\Transcription\AnalyzeSpeakersJob;
use App\Models\Transcription\TranscriptionJob;
use App\Services\Transcription\Providers\CustomSpeachesProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AnalyzeSpeakersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transcription:analyze-speakers {jobId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze speakers for a transcription job in the background';

    /**
     * Execute the console command.
     */
    public function handle(CustomSpeachesProvider $provider): int
    {
        $jobId = $this->argument('jobId');
        $job = TranscriptionJob::find($jobId);

        if (! $job) {
            $this->error("Job {$jobId} not found.");
            Log::error("AnalyzeSpeakersCommand: Job {$jobId} not found.");

            return 1;
        }

        $this->info("Starting speaker analysis for job {$jobId}...");
        try {
            $jobJob = new AnalyzeSpeakersJob($job);
            $jobJob->handle($provider);
            $this->info("Completed speaker analysis for job {$jobId}.");

            return 0;
        } catch (\Throwable $e) {
            $this->error('Failed: '.$e->getMessage());
            Log::error("AnalyzeSpeakersCommand failed for job {$jobId}: ".$e->getMessage(), ['exception' => $e]);

            return 1;
        }
    }
}
