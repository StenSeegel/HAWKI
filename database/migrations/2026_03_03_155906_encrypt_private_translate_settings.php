<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Encrypt any private translate_settings values that are stored as plain text.
 *
 * Safe to re-run: skips values that are already encrypted (Crypt::decryptString
 * succeeds) and skips empty values.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('translate_settings')
            ->where('is_private', true)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->get(['id', 'value']);

        foreach ($rows as $row) {
            // Skip rows that are already encrypted
            try {
                Crypt::decryptString($row->value);

                // If we reach here the value is already a valid ciphertext – skip
                continue;
            } catch (\Exception) {
                // Plain text – needs encrypting
            }

            DB::table('translate_settings')
                ->where('id', $row->id)
                ->update(['value' => Crypt::encryptString($row->value)]);
        }
    }

    public function down(): void
    {
        $rows = DB::table('translate_settings')
            ->where('is_private', true)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->get(['id', 'value']);

        foreach ($rows as $row) {
            try {
                $plain = Crypt::decryptString($row->value);
                DB::table('translate_settings')
                    ->where('id', $row->id)
                    ->update(['value' => $plain]);
            } catch (\Exception) {
                // Already plain text, nothing to do
            }
        }
    }
};
