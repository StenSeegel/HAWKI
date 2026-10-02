<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * backup:run, one at a time.
 *
 * Every spatie backup works in the same temporary directory: a run empties it
 * when it starts and deletes it when it ends. Two runs at once therefore
 * destroy each other's dump and zip ("ZipArchive::close(): Renaming temporary
 * file failed") - which is what a second click on "Run backup now" did while
 * the first one was still dumping. The scheduled backup and the admin button
 * both go through this command, so they share the lock.
 */
class RunBackup extends Command
{
    /** Exit code when another backup holds the lock. */
    public const ALREADY_RUNNING = 2;

    public const LOCK = 'hawki:backup-run';

    protected $signature = 'hawki:backup {--only-db : Back up the database only}';

    protected $description = 'Run backup:run unless another backup is already running.';

    public function handle(): int
    {
        // Longer than any backup takes; a run that dies without releasing the
        // lock does not block the next one forever.
        $lock = Cache::lock(self::LOCK, 4 * 3600);

        if (! $lock->get()) {
            $this->warn('Another backup is still running - not starting a second one.');

            return self::ALREADY_RUNNING;
        }

        try {
            return $this->call('backup:run', $this->option('only-db') ? ['--only-db' => true] : []);
        } finally {
            $lock->release();
        }
    }
}
