<?php

declare(strict_types=1);

namespace App\Jobs\Transcription;

use App\Models\Transcription\TranscriptionJob;
use App\Services\Transcription\AsyncTranscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 2 of the async transcription workflow: parallel chunk transcription,
 * final whole-file diarization with the user-confirmed speaker references, and
 * the optional LLM speaker cleanup. Queued counterpart of the
 * `transcription:process-job` artisan command (which remains available for
 * manual foreground runs), replacing the previous unsupervised
 * `exec(... > /dev/null 2>&1 &)` dispatch that discarded all log output and
 * left jobs stuck at 'transcribing' forever when the detached process died.
 */
class ProcessTranscriptionJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * No automatic queue-level retry, mirroring AnalyzeSpeakersJob: the
     * provider already retries transient (connect-phase/5xx) failures per
     * request internally, and re-running a pass that legitimately timed out
     * would just burn the same amount of time for the same outcome.
     */
    public int $tries = 1;

    /**
     * Per-job override of the worker's --timeout. Phase 2 runs two
     * long operations back to back — the parallel chunk transcription and the
     * whole-file diarization (each bounded by roughly
     * config('transcription.diarization_timeout_ceiling'), default 3600s) —
     * plus the comparatively quick LLM cleanup and S3 transfers, so its budget
     * is twice AnalyzeSpeakersJob's single-pass ceiling. Must stay below the
     * transcription_process connection's retry_after (see config/queue.php).
     */
    public int $timeout;

    public function __construct(
        public TranscriptionJob $transcriptionJob
    ) {
        $this->timeout = 2 * (int) config('transcription.diarization_timeout_ceiling', 3600) + 300;
    }

    /**
     * Called by Laravel even when the job never reaches handle()'s own error
     * handling — most importantly when the queue worker kills it via its
     * --timeout SIGALRM handler, which exits the process outside normal PHP
     * exception flow. Without this, a queue-level timeout/failure leaves
     * transcription_jobs.status stuck at 'transcribing' forever with no error
     * surfaced to the user (same failure mode AnalyzeSpeakersJob had, verified
     * empirically there). transcribeChunksParallel() marks the job failed
     * itself with a more specific message before rethrowing, so only overwrite
     * when that didn't happen.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("ProcessTranscriptionJob: job {$this->transcriptionJob->id} failed at the queue level: ".$exception->getMessage());

        $job = $this->transcriptionJob->fresh() ?? $this->transcriptionJob;
        if ($job->status !== 'failed') {
            $job->update([
                'status' => 'failed',
                'error_message' => 'Fehler bei der Transkription: '.$exception->getMessage(),
            ]);
        }
    }

    public function handle(AsyncTranscriptionService $service): void
    {
        Log::info("ProcessTranscriptionJob: started for job {$this->transcriptionJob->id}.");

        $service->transcribeChunksParallel($this->transcriptionJob);
    }
}
