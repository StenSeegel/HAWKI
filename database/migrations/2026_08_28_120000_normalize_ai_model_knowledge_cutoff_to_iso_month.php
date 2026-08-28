<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The model card's knowledge cutoff is now entered through a month picker, which
 * reads and writes an ISO month such as `2023-10`. Existing records hold whatever
 * an administrator typed into the old free-text field - most commonly a German
 * month name ("Oktober 2025"), sometimes a full date.
 *
 * A month input silently shows nothing for a value it cannot parse, so saving the
 * model afterwards would wipe the cutoff. Normalising the stored values keeps them
 * visible and editable.
 */
return new class extends Migration
{
    /**
     * Month names in both interface languages, lowercased.
     */
    private const MONTHS = [
        'januar' => 1, 'january' => 1, 'jan' => 1,
        'februar' => 2, 'february' => 2, 'feb' => 2,
        'märz' => 3, 'maerz' => 3, 'march' => 3, 'mar' => 3, 'mrz' => 3,
        'april' => 4, 'apr' => 4,
        'mai' => 5, 'may' => 5,
        'juni' => 6, 'june' => 6, 'jun' => 6,
        'juli' => 7, 'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8,
        'september' => 9, 'sep' => 9, 'sept' => 9,
        'oktober' => 10, 'october' => 10, 'okt' => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11,
        'dezember' => 12, 'december' => 12, 'dez' => 12, 'dec' => 12,
    ];

    public function up(): void
    {
        foreach (DB::table('ai_models')->select('id', 'settings')->get() as $row) {
            $settings = json_decode((string) $row->settings, true);

            if (! is_array($settings)) {
                continue;
            }

            $current = $settings['knowledge_cutoff'] ?? null;

            if (! is_string($current) || trim($current) === '') {
                continue;
            }

            $normalized = $this->toIsoMonth(trim($current));

            if ($normalized === null || $normalized === $current) {
                if ($normalized === null) {
                    Log::warning('Could not normalize knowledge_cutoff, leaving it untouched', [
                        'ai_model_id' => $row->id,
                        'value' => $current,
                    ]);
                }

                continue;
            }

            $settings['knowledge_cutoff'] = $normalized;

            DB::table('ai_models')
                ->where('id', $row->id)
                ->update(['settings' => json_encode($settings)]);
        }
    }

    /**
     * Irreversible on purpose: the original free-text spelling is not recoverable,
     * and the ISO value stays readable, so there is nothing useful to roll back to.
     */
    public function down(): void {}

    private function toIsoMonth(string $value): ?string
    {
        // Already an ISO month.
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $value, $m)) {
            return sprintf('%04d-%02d', (int) $m[1], (int) $m[2]);
        }

        // ISO date -> keep year and month.
        if (preg_match('/^(\d{4})-(\d{1,2})-\d{1,2}$/', $value, $m)) {
            return sprintf('%04d-%02d', (int) $m[1], (int) $m[2]);
        }

        // German style full date, e.g. 23.10.2025
        if (preg_match('/^\d{1,2}\.(\d{1,2})\.(\d{4})$/', $value, $m)) {
            return sprintf('%04d-%02d', (int) $m[2], (int) $m[1]);
        }

        // Month name and year, e.g. "Oktober 2025" or "October 2025".
        if (preg_match('/^([\p{L}]+)\.?\s+(\d{4})$/u', $value, $m)) {
            $month = self::MONTHS[mb_strtolower($m[1])] ?? null;

            if ($month !== null) {
                return sprintf('%04d-%02d', (int) $m[2], $month);
            }
        }

        // Numeric month and year, e.g. "10/2025" or "10.2025".
        if (preg_match('/^(\d{1,2})[.\/](\d{4})$/', $value, $m)) {
            return sprintf('%04d-%02d', (int) $m[2], (int) $m[1]);
        }

        return null;
    }
};
