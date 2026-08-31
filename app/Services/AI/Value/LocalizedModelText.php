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
     * Resolve a model's description across every place one may be stored.
     *
     * Returns null when the model has no description at all, which is what gates
     * whether a model card is shown for it.
     *
     * @param  array<string, mixed>  $model  one entry of the available-models list
     */
    public static function description(array $model): ?string
    {
        $settings = $model['settings'] ?? [];
        $settings = is_array($settings) ? $settings : [];

        // Preferred language first, then the other variant: a card that exists only
        // because the English text was filled in must still show that text rather
        // than a "no description" placeholder.
        foreach ([
            self::get($settings, 'description'),
            $settings['description'] ?? null,
            $settings['description_en'] ?? null,
            // Provider-synced fallbacks, which carry no language variants.
            $model['information']['model_display_info']['description'] ?? null,
            $model['information']['description'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Render a context window compactly: 128000 -> "128K", 1000000 -> "1M".
     *
     * A fraction is only kept below 10, where it still carries information
     * (8192 -> "8,2K"), and uses the decimal separator of the active language.
     * Non-numeric values (such as the "?" placeholder) are passed through.
     */
    public static function contextSize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return is_string($value) ? $value : null;
        }

        $number = (int) $value;

        if ($number >= 1_000_000) {
            $scaled = $number / 1_000_000;
            $unit = 'M';
        } elseif ($number >= 1_000) {
            $scaled = $number / 1_000;
            $unit = 'K';
        } else {
            return (string) $number;
        }

        $scaled = round($scaled, 1);
        $decimals = ($scaled < 10 && fmod($scaled, 1.0) !== 0.0) ? 1 : 0;
        $separator = self::activeLanguage() === 'en_US' ? '.' : ',';

        return number_format($scaled, $decimals, $separator, '').$unit;
    }

    /**
     * Format the stored knowledge cutoff for the active interface language.
     *
     * The month picker stores an ISO month (`2023-10`), which carries no language
     * of its own, so it is rendered as "Oktober 2023" or "October 2023" from the
     * same value - no second input field needed. Values saved before the field
     * became a month picker (a full date, or free text someone typed) are still
     * understood, and anything unrecognisable is returned untouched.
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

        $locale = self::activeLanguage() === 'en_US' ? 'en' : 'de';

        // translatedFormat gives the month name in the target language.
        return $date->locale($locale)->translatedFormat('F Y');
    }

    /**
     * Accept the month picker's value first, then the formats older records use.
     */
    private static function parseDate(string $value): ?Carbon
    {
        foreach (['Y-m', 'Y-m-d', 'd.m.Y', 'j.n.Y', 'd/m/Y'] as $format) {
            try {
                // Carbon runs in strict mode here, so a mismatch throws rather
                // than returning false. '!' resets unspecified parts to zero.
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
