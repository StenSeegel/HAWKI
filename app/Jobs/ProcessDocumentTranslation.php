<?php

namespace App\Jobs;

use App\Services\Translation\DocumentTranslationService;
use DeepL\DeepLException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Background job that polls DeepL for document translation status
 * and downloads the result when complete.
 *
 * This ensures translations finish even if the user closes the browser.
 */
class ProcessDocumentTranslation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Max attempts before the job fails permanently.
     */
    public int $tries = 60;

    /**
     * Max time (seconds) the job can run.
     */
    public int $timeout = 600;

    private string $jobId;

    /**
     * Create a new job instance.
     */
    public function __construct(public readonly string $translationJobId)
    {
        $this->jobId = $translationJobId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $cacheKey = 'doc_translation_'.$this->jobId;
        $jobData = Cache::get($cacheKey);

        if (! $jobData) {
            Log::warning('[DocTranslation][Job] Cache entry not found, aborting', [
                'job_id' => $this->jobId,
            ]);

            return;
        }

        // Skip if already completed or errored
        if (in_array($jobData['status'] ?? '', ['done', 'error'])) {
            Log::debug('[DocTranslation][Job] Already finished, skipping', [
                'job_id' => $this->jobId,
                'status' => $jobData['status'],
            ]);

            return;
        }

        try {
            $service = app(DocumentTranslationService::class);
            $result = $service->checkStatus($this->jobId);

            Log::debug('[DocTranslation][Job] Status check result', [
                'job_id' => $this->jobId,
                'status' => $result['status'],
                'seconds_remaining' => $result['seconds_remaining'],
            ]);

            // If not done yet, re-dispatch with delay
            if ($result['status'] !== 'done' && $result['status'] !== 'error') {
                $delay = $result['seconds_remaining']
                    ? max(3, min((int) $result['seconds_remaining'], 10))
                    : 5;

                self::dispatch($this->jobId)->delay(now()->addSeconds($delay));

                Log::debug('[DocTranslation][Job] Re-dispatching', [
                    'job_id' => $this->jobId,
                    'delay_seconds' => $delay,
                ]);
            }

        } catch (DeepLException $e) {
            Log::error('[DocTranslation][Job] DeepL error', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
            ]);

            // Update cache with error status
            $jobData['status'] = 'error';
            $jobData['error_message'] = $e->getMessage();
            Cache::put($cacheKey, $jobData, now()->addHour());

        } catch (\Exception $e) {
            Log::error('[DocTranslation][Job] Unexpected error', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
