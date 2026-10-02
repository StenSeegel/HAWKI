<?php

declare(strict_types=1);

namespace App\Services\Transcription;

use App\Models\Transcription\Transcription;
use App\Models\Transcription\TranscriptionJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The S3 audio behind transcription jobs: the uploaded original
 * (uploads/{job id}/...) and the preprocessed chunks (jobs/{job id}/...).
 *
 * A saved transcription finds its audio through its jobs, and deleting the
 * transcription used to leave both behind for good - the job row lost its
 * transcription_id and nothing ever looked at it again. So did every result
 * that was never saved, and every queue delete whose chunk folder could not
 * be removed.
 */
class TranscriptionArtifacts
{
    /**
     * Half the window in which getAudioPresignedUrl() matches a job to an old
     * transcription that names none (by filename, size or order). A job inside
     * it may still be that transcription's audio and is never pruned.
     */
    private const FALLBACK_WINDOW_HOURS = 2;

    /**
     * Deletes the job's audio and then the job. The audio is removed on a
     * best-effort basis: an S3 failure is logged and must not keep the row.
     */
    public function purgeJob(TranscriptionJob $job): void
    {
        try {
            $s3 = Storage::disk('s3');
            if ($job->file_path && $s3->exists($job->file_path)) {
                $s3->delete($job->file_path);
            }
            $s3->deleteDirectory("uploads/{$job->id}");
            $s3->deleteDirectory("jobs/{$job->id}");
        } catch (\Throwable $e) {
            Log::warning("Could not clean up S3 artifacts for transcription job {$job->id}: ".$e->getMessage());
        }

        $job->delete();
    }

    /**
     * The jobs a transcription owns: linked by transcription_id, or named in
     * its metadata (job_id, source_files[].job_id) and linked to no other.
     *
     * @return Collection<int, TranscriptionJob>
     */
    public function jobsOf(Transcription $transcription): Collection
    {
        $named = $this->jobIdsIn($transcription->metadata);

        return TranscriptionJob::where('user_id', $transcription->user_id)
            ->where(function ($query) use ($transcription, $named) {
                $query->where('transcription_id', $transcription->id)
                    ->orWhere(function ($byMetadata) use ($transcription, $named) {
                        $byMetadata->whereIn('id', $named)
                            ->where(fn ($q) => $q->whereNull('transcription_id')->orWhere('transcription_id', $transcription->id));
                    });
            })
            ->get();
    }

    /**
     * Jobs no transcription can reach anymore: no transcription_id, untouched
     * for $days, named in no transcription's metadata and outside the window
     * in which an old transcription of the same user could match them.
     *
     * @return Collection<int, TranscriptionJob>
     */
    public function abandonedJobs(int $days): Collection
    {
        $candidates = TranscriptionJob::whereNull('transcription_id')
            ->where('updated_at', '<', now()->subDays($days))
            ->get();

        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $named = [];
        Transcription::whereNotNull('metadata')->select(['id', 'metadata'])->chunkById(500, function ($transcriptions) use (&$named) {
            foreach ($transcriptions as $transcription) {
                foreach ($this->jobIdsIn($transcription->metadata) as $id) {
                    $named[$id] = true;
                }
            }
        });

        return $candidates->reject(function (TranscriptionJob $job) use ($named) {
            if (isset($named[(string) $job->id])) {
                return true;
            }

            return Transcription::where('user_id', $job->user_id)
                ->whereBetween('created_at', [
                    $job->created_at->copy()->subHours(self::FALLBACK_WINDOW_HOURS),
                    $job->created_at->copy()->addHours(self::FALLBACK_WINDOW_HOURS),
                ])
                ->exists();
        })->values();
    }

    /**
     * Job ids that own audio in the bucket but have no row on this host.
     *
     * Only meaningful when the bucket belongs to this host alone: in a bucket
     * shared with another installation, that installation's audio looks
     * orphaned from here.
     *
     * @return list<string>
     */
    public function orphanedJobIds(): array
    {
        $s3 = Storage::disk('s3');
        $ids = [];
        foreach (['uploads', 'jobs'] as $prefix) {
            foreach ($s3->allFiles($prefix) as $path) {
                $id = explode('/', $path)[1] ?? null;
                if ($id !== null && $id !== '') {
                    $ids[$id] = true;
                }
            }
        }

        $known = TranscriptionJob::whereIn('id', array_keys($ids))->pluck('id')->map(fn ($id) => (string) $id)->flip();

        return array_values(array_filter(array_keys($ids), fn ($id) => ! isset($known[$id])));
    }

    public function purgeOrphan(string $jobId): void
    {
        $s3 = Storage::disk('s3');
        $s3->deleteDirectory("uploads/{$jobId}");
        $s3->deleteDirectory("jobs/{$jobId}");
    }

    /** @return list<string> */
    private function jobIdsIn(?array $metadata): array
    {
        $ids = [];
        if (! empty($metadata['job_id']) && is_string($metadata['job_id'])) {
            $ids[] = $metadata['job_id'];
        }
        foreach ((array) ($metadata['source_files'] ?? []) as $file) {
            if (is_array($file) && ! empty($file['job_id']) && is_string($file['job_id'])) {
                $ids[] = $file['job_id'];
            }
        }

        return array_values(array_unique($ids));
    }
}
