<?php

use Illuminate\Support\Facades\Schedule;

// Backup runs at the configured interval and time if enabled in settings
$backupInterval = config('scheduler.backup.schedule_interval');
$backupTime = config('scheduler.backup.schedule_time', '02:00');
$includeFiles = config('scheduler.backup.include_files', false);

// Build backup command with appropriate flags
// hawki:backup wraps backup:run in the lock the admin button shares, so the
// two can never run at once and destroy each other's temporary files.
$backupCommand = $includeFiles ? 'hawki:backup' : 'hawki:backup --only-db';
$backupSchedule = Schedule::command($backupCommand);

// Apply interval
if (in_array($backupInterval, ['everyMinute', 'everyFiveMinutes', 'everyTenMinutes', 'everyFifteenMinutes', 'everyThirtyMinutes', 'hourly'])) {
    // For frequent intervals, don't apply time
    $backupSchedule->$backupInterval();
} else {
    // For daily, weekly, monthly - apply time
    $backupSchedule->$backupInterval()->at($backupTime);
}

// Only run if enabled
$backupSchedule->when(function () {
    return config('scheduler.backup.enabled') == true;
});

// Cleanup runs daily at 01:00 if enabled in settings
Schedule::command('backup:clean')
    ->daily()
    ->at('01:00')
    ->when(function () {
        return config('scheduler.cleanup.enabled') == true;
    });

// Model status check runs every minute if enabled
Schedule::command('check:model-status')
    ->everyMinute()
    ->when(function () {
        return config('scheduler.model_status_check.enabled', true) == true;
    });

// File storage cleanup runs daily if enabled
Schedule::command('filestorage:cleanup')
    ->daily()
    ->when(function () {
        return config('scheduler.filestorage_cleanup.enabled', true) == true;
    });

// Self-registered accounts that never confirmed their e-mail address are purged daily,
// which frees their username and address again.
Schedule::command('hawki:purge-unverified-users')
    ->daily()
    ->at('03:00');

// Transcription jobs no transcription can reach anymore are deleted with their
// S3 audio once they have been untouched for the retention period.
Schedule::command('transcription:prune-jobs')
    ->daily()
    ->at('03:30');
