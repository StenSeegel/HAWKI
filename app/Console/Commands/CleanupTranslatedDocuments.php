<?php

namespace App\Console\Commands;

use App\Models\TranslateDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupTranslatedDocuments extends Command
{
    protected $signature = 'translate:cleanup-documents
                            {--dry-run : List expired documents without deleting them}';

    protected $description = 'Delete expired translated documents from storage and mark them as deleted in the database.';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        $expired = TranslateDocument::expired()->get();

        if ($expired->isEmpty()) {
            $this->info('No expired documents found.');

            return self::SUCCESS;
        }

        $this->info("Found {$expired->count()} expired document(s).");

        $deleted = 0;
        $missing = 0;

        foreach ($expired as $record) {
            if ($isDryRun) {
                $this->line("  [DRY-RUN] Would delete: {$record->original_name} (ID: {$record->id}, expired: {$record->expires_at})");

                continue;
            }

            if (! empty($record->file_path) && file_exists($record->file_path)) {
                @unlink($record->file_path);
                $deleted++;
            } else {
                $missing++;
            }

            $record->update(['status' => 'deleted']);
        }

        if (! $isDryRun) {
            Log::info('[TranslateCleanup] Expired documents cleaned up', [
                'deleted' => $deleted,
                'already_missing' => $missing,
                'total' => $expired->count(),
            ]);

            $this->info("Deleted {$deleted} file(s), {$missing} were already missing.");
        }

        return self::SUCCESS;
    }
}
