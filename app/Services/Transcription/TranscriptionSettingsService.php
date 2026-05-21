<?php

declare(strict_types=1);

namespace App\Services\Transcription;

use App\Models\Transcription\TranscriptionSetting;

class TranscriptionSettingsService
{
    /**
     * Get a setting by key.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $setting = TranscriptionSetting::where('key', $key)->first();

        return $setting ? $setting->typed_value : $default;
    }

    /**
     * Get multiple settings as an associative array.
     */
    public function getMany(array $keys): array
    {
        return TranscriptionSetting::whereIn('key', $keys)
            ->get()
            ->keyBy('key')
            ->map(fn ($setting) => $setting->typed_value)
            ->toArray();
    }

    /**
     * Set a setting value.
     */
    public function set(string $key, mixed $value): void
    {
        $setting = TranscriptionSetting::where('key', $key)->first();

        if ($setting) {
            $setting->value = $value;
            $setting->save();
        }
    }
}
