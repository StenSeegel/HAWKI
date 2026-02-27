<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExtensionSetting;
use Illuminate\Support\Collection;

class ExtensionSettingService
{
    /** @var array<string, Collection<string, ExtensionSetting>> */
    private array $cache = [];

    /**
     * Get a setting value for a given extension package and key.
     */
    public function get(string $package, string $key, mixed $default = null): mixed
    {
        $setting = $this->loadForPackage($package)->get($key);

        return $setting ? $setting->getTypedValue() : $default;
    }

    /**
     * Set a setting value for a given extension package and key.
     */
    public function set(string $package, string $key, mixed $value): void
    {
        ExtensionSetting::updateOrCreate(
            ['package' => $package, 'key' => $key],
            ['value' => $this->castToString($value), 'type' => $this->detectType($value)]
        );

        // Bust cache for this package
        unset($this->cache[$package]);
    }

    /**
     * Get all settings for a given extension package as key => value pairs.
     *
     * @return array<string, mixed>
     */
    public function all(string $package): array
    {
        return $this->loadForPackage($package)
            ->mapWithKeys(fn (ExtensionSetting $s) => [$s->key => $s->getTypedValue()])
            ->all();
    }

    /**
     * @return Collection<string, ExtensionSetting>
     */
    private function loadForPackage(string $package): Collection
    {
        if (! isset($this->cache[$package])) {
            $this->cache[$package] = ExtensionSetting::where('package', $package)
                ->get()
                ->keyBy('key');
        }

        return $this->cache[$package];
    }

    private function detectType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_array($value) => 'json',
            default => 'string',
        };
    }

    private function castToString(mixed $value): ?string
    {
        if (is_null($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }
}
