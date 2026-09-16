<?php

declare(strict_types=1);

namespace App\Services\Chat\Attachment;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Draws an SVG into a PNG, for the models.
 *
 * A vision model is given its pictures as raster bytes. An SVG handed over as
 * it is reaches Pillow on the vLLM side, which cannot read one and answers the
 * whole request with "cannot identify image file" - a 400 that takes the
 * message with it. So the drawing is rendered here, and its markup travels
 * beside the picture as text.
 *
 * Rendering happens in rsvg-convert (librsvg2-bin, in the app image). It is a
 * best effort: without the binary, or on a drawing it cannot read, this returns
 * null and the model is left with the markup alone.
 */
final class SvgRasterizer
{
    /**
     * The drawing is fitted into a box of this many pixels on the longest edge.
     * It matches what the image tool produces, see [[hawki-image-cost-policy]]:
     * more pixels cost tokens without showing a model more of a line drawing.
     */
    public const MAX_EDGE = 1024;

    private const TIMEOUT_SECONDS = 20;

    /**
     * Anything the renderer has not finished by then is a drawing no model
     * needs to wait for.
     */
    private const MAX_SOURCE_BYTES = 8 * 1024 * 1024;

    public static function isSvg(?string $mime): bool
    {
        return strtolower(trim(explode(';', (string) $mime)[0])) === 'image/svg+xml';
    }

    /**
     * PNG bytes for the drawing, or null when it cannot be rendered.
     */
    public static function toPng(string $svg, int $maxEdge = self::MAX_EDGE): ?string
    {
        if (strlen($svg) > self::MAX_SOURCE_BYTES) {
            Log::warning('[SVG RASTERIZER] The drawing is too large to render', ['bytes' => strlen($svg)]);

            return null;
        }

        // Scripts and event handlers go first, then everything that would make
        // the renderer fetch a URL or read a file of its own - librsvg follows
        // those, and the browser's Content-Security-Policy does not reach it.
        $clean = SvgSanitizer::sanitize($svg);
        if ($clean === null) {
            Log::warning('[SVG RASTERIZER] The drawing is not well-formed and was not rendered');

            return null;
        }

        $clean = self::stripExternalReferences($clean);

        $source = tempnam(sys_get_temp_dir(), 'hawki_svg_');
        if ($source === false) {
            return null;
        }
        $target = $source.'.png';

        try {
            if (file_put_contents($source, $clean) === false) {
                return null;
            }

            $result = Process::timeout(self::TIMEOUT_SECONDS)->run([
                'rsvg-convert',
                '--width='.$maxEdge,
                '--height='.$maxEdge,
                '--keep-aspect-ratio',
                // A line drawing is usually transparent, and transparency is
                // flattened to black by the model side - the lines vanish.
                '--background-color=white',
                '--format=png',
                '--output='.$target,
                $source,
            ]);

            if (! $result->successful()) {
                Log::warning('[SVG RASTERIZER] rsvg-convert could not draw the file', [
                    'exit_code' => $result->exitCode(),
                    'error' => trim($result->errorOutput()),
                ]);

                return null;
            }

            $png = @file_get_contents($target);
            if (! is_string($png) || $png === '') {
                return null;
            }

            return $png;
        } catch (\Throwable $e) {
            // No binary in this image, or it was killed by the timeout.
            Log::warning('[SVG RASTERIZER] The drawing could not be rendered', ['error' => $e->getMessage()]);

            return null;
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }

    /**
     * Drops every reference that points outside the file. A drawing is data
     * from a chat, and librsvg would happily open the http:// or file:// URL it
     * names. Fragments (<use href="#arrow">) and embedded data: pictures stay -
     * they are the file's own content.
     */
    public static function stripExternalReferences(string $svg): string
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new \DOMDocument();
            if ($document->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS) === false
                || $document->documentElement === null) {
                return $svg;
            }

            $xpath = new \DOMXPath($document);
            /** @var \DOMAttr $attribute */
            foreach (iterator_to_array($xpath->query('//@*') ?: []) as $attribute) {
                $name = strtolower($attribute->localName ?? $attribute->name);
                if (! in_array($name, ['href', 'src'], true)) {
                    continue;
                }

                $value = ltrim($attribute->value);
                if (str_starts_with($value, '#') || stripos($value, 'data:') === 0) {
                    continue;
                }

                $attribute->ownerElement?->removeAttributeNode($attribute);
            }

            $result = $document->saveXML();

            return is_string($result) ? $result : $svg;
        } catch (\Throwable $e) {
            return $svg;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
