<?php

declare(strict_types=1);

namespace App\Services\AI\Value;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Session;

/**
 * Resolves admin-entered model text (description, knowledge cutoff) for the
 * language the interface is currently showing.
 *
 * These texts live in the AiModel `settings` JSON blob and are typed in by an
 * administrator, so they cannot come from the language JSON files. The base key
 * holds the German text and `<key>_en` the English one; English falls back to
 * the base text when it was never filled in.
 */
final class LocalizedModelText
{
    /**
     * Suffix per interface language. Languages without an entry use the base key.
     */
    private const SUFFIX_BY_LANGUAGE = [
        'en_US' => '_en',
    ];

    /**
     * Pick the value matching the active interface language.
     *
     * @param  array<string, mixed>  $settings  the model's settings array
     * @param  string  $key  base settings key, e.g. 'description'
     */
    public static function get(array $settings, string $key, ?string $default = null): ?string
    {
        $suffix = self::SUFFIX_BY_LANGUAGE[self::activeLanguage()] ?? '';

        if ($suffix !== '') {
            $localized = $settings[$key.$suffix] ?? null;
            if (is_string($localized) && trim($localized) !== '') {
                return $localized;
            }
        }

        $base = $settings[$key] ?? null;
        if (is_string($base) && trim($base) !== '') {
            return $base;
        }

        return $default;
    }

    /**
     * Format a stored date for the active interface language.
     *
     * The knowledge cutoff is entered once as a date (German `23.10.2025`), so it
     * needs no second input field - only re-formatting per language. Values that
     * are not a recognisable date (legacy free text such as "Oktober 2023") are
     * returned untouched.
     */
    public static function date(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $date = self::parseDate($value);

        if ($date === null) {
            return $value;
        }

        return self::activeLanguage() === 'en_US'
            ? $date->format('F j, Y')
            : $date->format('d.m.Y');
    }

    /**
     * Accept the formats an administrator may plausibly have typed.
     */
    private static function parseDate(string $value): ?Carbon
    {
        foreach (['d.m.Y', 'j.n.Y', 'Y-m-d', 'd/m/Y'] as $format) {
            try {
                // Carbon runs in strict mode here, so a mismatch throws rather
                // than returning false.
                $date = Carbon::createFromFormat('!'.$format, $value);
            } catch (\Throwable $e) {
                continue;
            }

            if ($date instanceof Carbon) {
                return $date;
            }
        }

        return null;
    }

    /**
     * The suffix the frontend should use, so JS resolves these fields the same way.
     */
    public static function activeSuffix(): string
    {
        return self::SUFFIX_BY_LANGUAGE[self::activeLanguage()] ?? '';
    }

    /**
     * The session stores the whole locale config entry, not just its id.
     */
    private static function activeLanguage(): string
    {
        $language = Session::get('language');

        if (is_array($language)) {
            return (string) ($language['id'] ?? '');
        }

        return is_string($language) ? $language : '';
    }
}
