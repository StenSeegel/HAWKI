<?php

declare(strict_types=1);

namespace App\Console\Commands\Transcription;

use App\Services\Transcription\TranscriptionArtifacts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneTranscriptionJobs extends Command
{
    protected $signature = 'transcription:prune-jobs
                            {--days= : Override the configured retention period}
                            {--orphans-in= : Also delete audio whose job row does not exist; name the bucket to confirm this host has it to itself}
                            {--dry-run : List what would be deleted without deleting it}';

    protected $description = 'Delete transcription jobs no transcription can reach anymore, with their S3 audio.';

    public function handle(TranscriptionArtifacts $artifacts): int
    {
        $days = (int) ($this->option('days') ?? config('transcription.abandoned_job_retention_days', 7));
        if ($days < 1) {
            $this->error('The retention period must be at least one day.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        // In a bucket shared with another installation (dev and prod once
        // shared audio-ingest), that installation's audio has no job row here
        // and would be deleted as orphaned. Naming the bucket makes that a
        // decision instead of a flag - checked before anything is deleted.
        $orphanBucket = $this->option('orphans-in');
        $configured = (string) config('filesystems.disks.s3.bucket');
        if ($orphanBucket !== null && $orphanBucket !== $configured) {
            $this->error("--orphans-in={$orphanBucket} does not match this host's audio bucket ({$configured}).");

            return self::FAILURE;
        }

        $jobs = $artifacts->abandonedJobs($days);
        $this->info(($dryRun ? '[DRY-RUN] ' : '')."{$jobs->count()} abandoned job(s) older than {$days} day(s).");
        foreach ($jobs as $job) {
            $this->line("  job {$job->id} ({$job->status}, last change {$job->updated_at})");
            if (! $dryRun) {
                $artifacts->purgeJob($job);
            }
        }

        $orphans = [];
        if ($orphanBucket !== null) {
            $orphans = $artifacts->orphanedJobIds();
            $this->info(($dryRun ? '[DRY-RUN] ' : '').count($orphans).' job id(s) with audio but no job row.');
            foreach ($orphans as $id) {
                $this->line("  orphan {$id}");
                if (! $dryRun) {
                    $artifacts->purgeOrphan($id);
                }
            }
        }

        if (! $dryRun && ($jobs->isNotEmpty() || $orphans !== [])) {
            Log::info('Pruned transcription jobs', ['jobs' => $jobs->count(), 'orphans' => count($orphans), 'bucket' => config('filesystems.disks.s3.bucket'), 'retention_days' => $days]);
        }

        return self::SUCCESS;
    }
}
