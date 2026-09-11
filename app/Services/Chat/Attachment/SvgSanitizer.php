<?php

declare(strict_types=1);

namespace App\Services\Chat\Attachment;

/**
 * Makes an SVG a model or a sandbox produced safe to keep and to serve.
 *
 * A stored SVG is served from HAWKI's own origin with its real content type, so
 * opening its link in a tab would run any script it carries with the user's
 * session. The picture itself never needs one: scripts, event handlers,
 * foreignObject (HTML inside the drawing) and javascript: links are dropped
 * before the file is written. The download route adds a Content-Security-Policy
 * on top, for the case that something slips through.
 */
final class SvgSanitizer
{
    /**
     * Sent with every SVG the file routes serve: nothing may load or run, only
     * the drawing's own inline styles and embedded data: images are allowed.
     */
    public const CONTENT_SECURITY_POLICY = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; font-src data:";

    private const FORBIDDEN_ELEMENTS = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'html', 'body'];

    public static function looksLikeSvg(string $bytes): bool
    {
        return preg_match('/^\s*(?:<\?xml[^>]*\?>\s*)?(?:<!DOCTYPE[^>]*>\s*)?<svg\b/i', $bytes) === 1;
    }

    /**
     * The markup without anything that could run, or null when it is not
     * well-formed XML - a browser would refuse to draw that as well.
     */
    public static function sanitize(string $svg): ?string
    {
        if (trim($svg) === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new \DOMDocument();
            $loaded = $document->loadXML(self::declareNamespaces($svg), LIBXML_NONET | LIBXML_NOBLANKS);

            if ($loaded === false || $document->documentElement === null
                || strtolower($document->documentElement->localName ?? '') !== 'svg') {
                return null;
            }

            self::clean($document->documentElement);
            self::giveIntrinsicSize($document->documentElement);

            $document->formatOutput = false;
            $result = $document->saveXML();

            return is_string($result) ? $result : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * A drawing served as image/svg+xml is parsed as XML, and XML puts an <svg>
     * without xmlns into no namespace at all - the browser shows nothing. Models
     * leave the declaration out often enough; it is added, and xmlns:xlink with
     * it when xlink: attributes are used.
     */
    public static function declareNamespaces(string $svg): string
    {
        if (preg_match('/<svg\b[^>]*>/i', $svg, $root, PREG_OFFSET_CAPTURE) !== 1) {
            return $svg;
        }

        $tag = $root[0][0];
        $fixed = $tag;

        if (preg_match('/\sxmlns\s*=/i', $tag) !== 1) {
            $fixed = preg_replace('/^<svg\b/i', '<svg xmlns="http://www.w3.org/2000/svg"', $fixed, 1) ?? $fixed;
        }

        if (str_contains($svg, 'xlink:') && preg_match('/\sxmlns:xlink\s*=/i', $tag) !== 1) {
            $fixed = preg_replace('/^<svg\b/i', '<svg xmlns:xlink="http://www.w3.org/1999/xlink"', $fixed, 1) ?? $fixed;
        }

        if ($fixed === $tag) {
            return $svg;
        }

        return substr($svg, 0, $root[0][1]).$fixed.substr($svg, $root[0][1] + strlen($tag));
    }

    /**
     * A drawing with a viewBox but no width and height has a shape and no size.
     * Shown as an <img> that is fine in a block, but inside the shrink-to-fit
     * frame the download button sits on it measures 0x0 - which is why a plot
     * was visible while it streamed and vanished the moment the finished message
     * got its buttons. The viewBox becomes the size, in px.
     */
    public static function giveIntrinsicSize(\DOMElement $root): void
    {
        if ($root->hasAttribute('width') || $root->hasAttribute('height')) {
            return;
        }

        $viewBox = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox'))) ?: [];
        if (count($viewBox) !== 4) {
            return;
        }

        $width = (float) $viewBox[2];
        $height = (float) $viewBox[3];
        if ($width <= 0 || $height <= 0) {
            return;
        }

        $root->setAttribute('width', self::number($width));
        $root->setAttribute('height', self::number($height));
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    private static function clean(\DOMElement $element): void
    {
        // Children first, and from a copy: removing while iterating skips nodes.
        foreach (iterator_to_array($element->childNodes) as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            if (in_array(strtolower($child->localName ?? ''), self::FORBIDDEN_ELEMENTS, true)) {
                $element->removeChild($child);
                continue;
            }

            self::clean($child);
        }

        foreach (iterator_to_array($element->attributes) as $attribute) {
            /** @var \DOMAttr $attribute */
            $name = strtolower($attribute->localName ?? $attribute->name);

            if (str_starts_with($name, 'on') || self::isActiveUrl($name, $attribute->value)) {
                $element->removeAttributeNode($attribute);
            }
        }
    }

    private static function isActiveUrl(string $attribute, string $value): bool
    {
        if (! in_array($attribute, ['href', 'src', 'xlink:href', 'action', 'formaction'], true)) {
            return false;
        }

        // Control characters and whitespace are how "java\nscript:" gets past a prefix check.
        $normalized = strtolower(preg_replace('/[\s\x00-\x1F]+/', '', $value) ?? $value);

        return str_starts_with($normalized, 'javascript:')
            || str_starts_with($normalized, 'vbscript:')
            || str_starts_with($normalized, 'data:text/html')
            || str_starts_with($normalized, 'data:application/xhtml');
    }
}
