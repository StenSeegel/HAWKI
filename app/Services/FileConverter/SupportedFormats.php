<?php

namespace App\Services\FileConverter;

use App\Services\FileConverter\Handlers\Interfaces\FileConverterInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Which file extensions a chat upload may have.
 *
 * The active converter decides: it is asked for its own list and the answer is
 * cached, so a format the converter learns needs no HAWKI release. Two filters
 * sit on top of that list - `never_accept` (archives, not negotiable) and
 * `excluded_extensions` (an admin's env deny list, audio/video by default).
 * When the converter cannot be reached the static snapshot in
 * config/file_converter.php `formats` stands in, so uploads keep working.
 *
 * The converter reports extensions, not MIME types; the MIME of an upload is
 * looked up in that same config table, see mimeFor().
 */
class SupportedFormats
{
    private const CACHE_KEY = 'file_converter.supported_extensions';

    /** @var string[]|null in-request memo, the cache store is hit at most once */
    private ?array $extensions = null;

    /**
     * Every extension a user may upload, lower case, without the dot.
     *
     * @return string[]
     */
    public function extensions(): array
    {
        if ($this->extensions !== null) {
            return $this->extensions;
        }

        $denied = $this->denied();
        $announced = $this->announcedExtensions();

        return $this->extensions = array_values(array_filter(
            $announced,
            static fn(string $ext): bool => ! in_array($ext, $denied, true)
        ));
    }

    /** The extension of $filename is uploadable. */
    public function accepts(string $filename): bool
    {
        $extension = self::extensionOf($filename);

        return $extension !== '' && in_array($extension, $this->extensions(), true);
    }

    /**
     * The MIME type HAWKI stores for an extension. The browser sends none for
     * most of these (.adoc, .typ, .org, .eml) and sniffing reads the zip-based
     * ones (.docx, .epub, .pages) as application/zip, so this table decides.
     */
    public function mimeFor(string $extension): ?string
    {
        $extension = self::extensionOf($extension);

        return config('file_converter.formats')[$extension] ?? null;
    }

    /**
     * The MIME types of every accepted extension - what an attachment may be
     * when only its MIME is known, e.g. when a stored file is linked to a
     * message. Denied extensions are not in it.
     *
     * @return string[]
     */
    public function mimes(): array
    {
        $mimes = [];
        foreach ($this->extensions() as $extension) {
            $mime = $this->mimeFor($extension);
            if ($mime !== null) {
                $mimes[strtolower($mime)] = true;
            }
        }

        return array_keys($mimes);
    }

    /**
     * What the browser needs to gate a file pick: the accepted extensions, the
     * MIME each one stands for, and a ready-made value for an input's accept=.
     *
     * @return array{extensions: string[], mimes: array<string,string>, accept: string}
     */
    public function forFrontend(): array
    {
        $extensions = $this->extensions();
        sort($extensions);

        $mimes = [];
        foreach ($extensions as $extension) {
            $mime = $this->mimeFor($extension);
            if ($mime !== null) {
                $mimes[$extension] = $mime;
            }
        }

        return [
            'extensions' => $extensions,
            'mimes' => $mimes,
            'accept' => implode(',', array_map(static fn(string $ext): string => '.'.$ext, $extensions)),
        ];
    }

    /**
     * The largest upload that can actually get through, in whole megabytes.
     *
     * `hawki.attachment_max_mb` is the ceiling HAWKI chooses. PHP's
     * upload_max_filesize and post_max_size are ceilings the request cannot
     * pass at all: a file over them never reaches Laravel, so the validation
     * message never fires and the user sees an empty failure. The smallest of
     * the three is therefore the only honest number to show and to validate
     * against - and it follows the php.ini of whatever image HAWKI runs in,
     * without a second value to keep in sync.
     */
    public function maxUploadMb(): int
    {
        $limits = [max(1, (int) config('hawki.attachment_max_mb', 20))];

        foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
            $bytes = self::iniBytes((string) ini_get($directive));
            // 0 or unset means no limit for that directive.
            if ($bytes > 0) {
                $limits[] = intdiv($bytes, 1048576);
            }
        }

        return max(1, min($limits));
    }

    /** "256M", "1G", "8192K" or a plain byte count as bytes. */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** Extensions refused whatever the converter offers. */
    public function denied(): array
    {
        $never = array_map('strtolower', (array) config('file_converter.never_accept', []));
        $excluded = array_map('strtolower', (array) config('file_converter.excluded_extensions', []));

        return array_values(array_unique(array_merge($never, $excluded)));
    }

    /** Drops the cached converter answer, so the next call asks again. */
    public function forget(): void
    {
        $this->extensions = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The converter's own list, cached. The fallback is the static snapshot,
     * which is also what a converter that answers with nothing usable gets.
     *
     * @return string[]
     */
    private function announcedExtensions(): array
    {
        $ttl = (int) config('file_converter.formats_cache_ttl', 3600);

        $fetch = function (): array {
            try {
                $converter = FileConverterFactory::create();
                $announced = $converter instanceof FileConverterInterface
                    ? $converter->supportedFormats()
                    : [];
            } catch (Throwable $e) {
                Log::warning('[SupportedFormats] Converter did not report its formats, using the static list: '.$e->getMessage());
                $announced = [];
            }

            $announced = self::normalize($announced);

            if ($announced === []) {
                return $this->staticExtensions();
            }

            // A diagram from HAWKI's own editor is uploadable whatever the
            // converter announces - its bytes never reach the converter.
            return array_values(array_unique(array_merge($announced, ['drawio'])));
        };

        if ($ttl <= 0) {
            return $fetch();
        }

        try {
            return Cache::remember(self::CACHE_KEY, $ttl, $fetch);
        } catch (Throwable $e) {
            // No cache store (tests, a Redis outage) is no reason to refuse uploads.
            Log::warning('[SupportedFormats] Could not cache the format list: '.$e->getMessage());

            return $fetch();
        }
    }

    /** @return string[] */
    private function staticExtensions(): array
    {
        return array_keys((array) config('file_converter.formats', []));
    }

    /**
     * ".PPTX" and "pptx" both mean pptx. A converter's answer is taken as
     * dotted extensions; anything else in the list is dropped.
     *
     * @param  array<int,mixed>  $extensions
     * @return string[]
     */
    private static function normalize(array $extensions): array
    {
        $clean = [];
        foreach ($extensions as $extension) {
            if (! is_string($extension)) {
                continue;
            }
            $extension = strtolower(ltrim(trim($extension), '.'));
            if ($extension !== '' && preg_match('/^[a-z0-9]+$/', $extension) === 1) {
                $clean[$extension] = true;
            }
        }

        return array_keys($clean);
    }

    /** The extension of a filename or of a bare extension, lower case, no dot. */
    public static function extensionOf(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $extension = str_contains($value, '.')
            ? pathinfo($value, PATHINFO_EXTENSION)
            : $value;

        return strtolower(ltrim((string) $extension, '.'));
    }
}
