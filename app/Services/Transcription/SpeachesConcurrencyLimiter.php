<?php

declare(strict_types=1);

namespace App\Services\Transcription;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cross-process counting semaphore that bounds the number of concurrent
 * requests sent to the Speaches server.
 *
 * The "max 3 in parallel" constraint is a property of the Speaches server
 * (total in-flight requests), not of any single job. Controlling concurrency
 * at the job level (one job at a time) under-utilises the server, while firing
 * every chunk of a job at once over-runs it. This limiter instead enforces a
 * single global budget at the request level: every transcription request must
 * hold a permit, regardless of which job or chunk it belongs to.
 *
 * It is implemented as N named cache-locks ("slots"). Acquiring a permit grabs
 * the first free slot; releasing frees it. This is backend-agnostic and works
 * with any cache store that supports atomic locks (redis, database, array).
 * Slots auto-expire after $ttl seconds so a crashed worker can never leak a
 * permit permanently.
 */
class SpeachesConcurrencyLimiter
{
    protected int $limit;

    public function __construct(
        int $limit,
        protected string $prefix = 'speaches_transcribe_slot_',
        protected int $ttl = 900,
    ) {
        $this->limit = max(1, $limit);
    }

    /**
     * Acquire up to $max permits, blocking until at least one becomes free
     * (or the wait deadline is reached).
     *
     * Returns the acquired slot locks; the caller MUST pass them back to
     * release() once the requests have completed. May return an empty array if
     * the wait timed out, in which case the caller should fail open (proceed
     * without a permit) rather than drop work.
     *
     * @return Lock[]
     */
    public function acquire(int $max, int $waitSeconds = 600): array
    {
        $max = max(1, min($max, $this->limit));
        $deadline = microtime(true) + $waitSeconds;

        /** @var Lock[] $held */
        $held = [];

        while (true) {
            for ($slot = 0; $slot < $this->limit && count($held) < $max; $slot++) {
                $lock = Cache::lock($this->prefix.$slot, $this->ttl);
                try {
                    if ($lock->get()) {
                        $held[] = $lock;
                    }
                } catch (\Throwable $e) {
                    // Cache/lock backend unavailable: fail open so transcription
                    // can still proceed (bounded by the in-process wave size).
                    Log::warning('SpeachesConcurrencyLimiter: lock backend unavailable, proceeding without permits: '.$e->getMessage());

                    return $held;
                }
            }

            if (! empty($held)) {
                return $held;
            }

            if (microtime(true) >= $deadline) {
                Log::warning('SpeachesConcurrencyLimiter: timed out waiting for a free slot, proceeding without a permit.');

                return [];
            }

            usleep(250_000); // 250ms
        }
    }

    /**
     * Release previously acquired permits.
     *
     * @param  Lock[]  $locks
     */
    public function release(array $locks): void
    {
        foreach ($locks as $lock) {
            try {
                $lock->release();
            } catch (\Throwable $e) {
                Log::warning('SpeachesConcurrencyLimiter: failed to release slot: '.$e->getMessage());
            }
        }
    }
}
