<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * SettingsService used to convert database keys to config keys by splitting at the first
     * underscore, which works for every single-word config file but mangles "open_id_connect_*"
     * into "open.id_connect_*" - a key nothing reads. OIDC settings stored in app_settings were
     * therefore inert, and OIDC was effectively configured through .env only.
     *
     * Now that the conversion is fixed, those rows start overriding the config. On an installation
     * that configured OIDC via .env while the rows still hold empty defaults, that would replace a
     * working configuration with blanks. This migration writes the currently effective (file/.env
     * derived) value into every affected row first, so switching the mapping on is behavior neutral.
     * Admins can then edit the values in the panel and have them actually take effect.
     */
    public function up(): void
    {
        if (app()->configurationIsCached()) {
            // With a cached config, .env is not loaded, so re-reading the config files would
            // resolve every env() call to null and we would write those nulls into the rows.
            Log::warning('Skipped aligning OIDC settings with the effective config because the config is cached. Run "php artisan config:clear", re-run this migration, or verify the OpenID Connect settings in the admin panel manually.');

            return;
        }

        $fileValues = [];
        $aligned = 0;

        foreach (DB::table('app_settings')->get() as $row) {
            // Only rows whose config file name contains an underscore were affected.
            if (empty($row->source) || ! str_contains($row->source, '_')) {
                continue;
            }
            if (! str_starts_with($row->key, $row->source.'_')) {
                continue;
            }

            $path = config_path($row->source.'.php');
            if (! array_key_exists($row->source, $fileValues)) {
                // Re-read the config file so env() is evaluated fresh, bypassing the values the
                // service provider already loaded from the database.
                $fileValues[$row->source] = file_exists($path) ? require $path : null;
            }
            if (! is_array($fileValues[$row->source])) {
                continue;
            }

            $realKey = substr($row->key, strlen($row->source) + 1);
            $value = data_get($fileValues[$row->source], $realKey);
            $value = $this->encodeValue($value, $row->type);

            if ($value === $row->value) {
                continue;
            }

            DB::table('app_settings')
                ->where('id', $row->id)
                ->update(['value' => $value, 'updated_at' => now()]);

            $aligned++;
        }

        if ($aligned > 0) {
            Log::info("Aligned {$aligned} settings with the effective configuration after fixing the config key mapping.");
        }
    }

    /**
     * Reverse the migrations.
     *
     * Nothing to undo: the rows now hold the values that were already in effect.
     */
    public function down(): void {}

    private function encodeValue(mixed $value, ?string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'json', 'array' => json_encode($value),
            'boolean' => $value ? 'true' : 'false',
            default => is_array($value) ? json_encode($value) : (string) $value,
        };
    }
};
