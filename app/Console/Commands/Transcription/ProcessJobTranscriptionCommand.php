<?php

declare(strict_types=1);

namespace App\Console\Commands\Transcription;

use App\Models\Transcription\TranscriptionJob;
use App\Services\Transcription\AsyncTranscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessJobTranscriptionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transcription:process-job {jobId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process and transcribe audio chunks for a job in the background';

    /**
     * Execute the console command.
     */
    public function handle(AsyncTranscriptionService $transcriptionService): int
    {
        $jobId = $this->argument('jobId');
        $job = TranscriptionJob::find($jobId);

        if (! $job) {
            $this->error("Job {$jobId} not found.");
            Log::error("ProcessJobTranscriptionCommand: Job {$jobId} not found.");

            return 1;
        }

        $this->info("Starting parallel transcription for job {$jobId}...");
        try {
            $transcriptionService->transcribeChunksParallel($job);
            $this->info("Completed parallel transcription for job {$jobId}.");

            return 0;
        } catch (\Throwable $e) {
            $this->error('Failed: '.$e->getMessage());
            Log::error("ProcessJobTranscriptionCommand failed for job {$jobId}: ".$e->getMessage(), ['exception' => $e]);

            return 1;
        }
    }
}
