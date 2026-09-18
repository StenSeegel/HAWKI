<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeUnverifiedUsers extends Command
{
    protected $signature = 'hawki:purge-unverified-users
                            {--days= : Override the configured retention period}
                            {--dry-run : List the accounts without deleting them}';

    protected $description = 'Delete self-registered local accounts that never confirmed their e-mail address, freeing username and address.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('auth.local_verification_purge_days', 7));

        if ($days < 1) {
            $this->error('The retention period must be at least one day.');

            return self::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        // An account an admin confirmed by hand has email_verified_at set and is never touched here.
        $stale = User::where('auth_type', 'local')
            ->whereNull('email_verified_at')
            ->where('created_at', '<', $cutoff)
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No unverified accounts older than '.$days.' day(s) found.');

            return self::SUCCESS;
        }

        $this->info("Found {$stale->count()} unverified account(s) older than {$days} day(s).");

        $deleted = 0;

        foreach ($stale as $user) {
            if ($isDryRun) {
                $this->line("  [DRY-RUN] Would delete user ID {$user->id} (created {$user->created_at})");

                continue;
            }

            $userId = $user->id;
            $user->delete();
            $deleted++;

            Log::info('Purged unverified local account', [
                'user_id' => $userId,
                'retention_days' => $days,
            ]);
        }

        if (! $isDryRun) {
            $this->info("Deleted {$deleted} unverified account(s).");
        }

        return self::SUCCESS;
    }
}
