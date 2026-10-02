<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\RunBackup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Two backups at once destroy each other's temporary files, so hawki:backup
 * refuses to start while another one holds the lock - whether that one came
 * from the schedule or from "Run backup now".
 */
class RunBackupLockTest extends TestCase
{
    private int $backupRuns = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Stands in for spatie's backup:run, which would really dump the database.
        $runs = &$this->backupRuns;
        Artisan::command('backup:run {--only-db}', function () use (&$runs) {
            $runs++;

            return Cache::lock(RunBackup::LOCK)->get() ? 1 : 0; // 0 = the lock is held while it runs
        });
    }

    protected function tearDown(): void
    {
        Cache::lock(RunBackup::LOCK)->forceRelease();

        parent::tearDown();
    }

    public function test_a_backup_runs_under_the_lock_and_releases_it(): void
    {
        $this->assertSame(0, Artisan::call('hawki:backup', ['--only-db' => true]));
        $this->assertSame(1, $this->backupRuns);

        $this->assertTrue(Cache::lock(RunBackup::LOCK)->get(), 'the lock must be free again after the run');
    }

    public function test_a_second_backup_is_refused_while_one_is_running(): void
    {
        $this->assertTrue(Cache::lock(RunBackup::LOCK, 60)->get());

        $this->assertSame(RunBackup::ALREADY_RUNNING, Artisan::call('hawki:backup', ['--only-db' => true]));
        $this->assertSame(0, $this->backupRuns, 'backup:run must not start');
    }

    public function test_a_failed_backup_releases_the_lock_too(): void
    {
        Artisan::command('backup:run {--only-db}', fn () => throw new \RuntimeException('dump failed'));

        try {
            Artisan::call('hawki:backup', ['--only-db' => true]);
        } catch (\RuntimeException) {
        }

        $this->assertTrue(Cache::lock(RunBackup::LOCK)->get(), 'a failure must not leave the lock behind');
    }
}
