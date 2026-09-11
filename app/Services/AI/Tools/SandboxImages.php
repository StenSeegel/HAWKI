<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Services\Chat\Attachment\AttachmentService;
use App\Services\Chat\Attachment\SvgSanitizer;
use Illuminate\Support\Facades\Log;

/**
 * Images a code sandbox produced - matplotlib plots, mostly, as PNG or SVG.
 *
 * Both code interpreters can return one, and neither returns it in a form the
 * chat can render: OpenAI hands back a `data:` URI in the finished call's
 * outputs, and code-exec-mcp writes the base64 straight into the text it prints.
 * Left alone, the first is dropped and the second reaches the model as tens of
 * thousands of characters of base64 - which the tool's output cap then truncates
 * into a corrupt image anyway.
 *
 * So an image is taken out of the text as early as possible, stored as an
 * attachment like a generated image is, and put back into the message as a URL.
 * The model is told an image was produced, and never sees the bytes.
 *
 * Registered as a singleton, because the extraction happens inside the tool -
 * which can only return text - while the auxiliaries that persist the
 * attachments are emitted by the request that called it. The request drains what
 * the tool collected.
 */
class SandboxImages
{
    /**
     * A base64 PNG, with or without its data URI prefix. The PNG signature
     * ('iVBORw0KGgo...') is what makes this safe to run over program output:
     * nothing else in a print statement looks like that.
     *
     * Whitespace is deliberately NOT in the character class. Base64 characters
     * are just letters and digits, so a class that also matched whitespace ran
     * straight over the newline and ate the prose after the image - "Done." is
     * four perfectly valid base64 characters. Both sources print the blob on one
     * unbroken line.
     */
    private const PNG_PATTERN = '/(?:data:image\/png;base64,)?(iVBORw0KGgoAAAANSUhEUg[A-Za-z0-9+\/=]+)/';

    /**
     * An SVG, in the three shapes a program prints one: a base64 data URI, a
     * plain data URI with the markup (raw or percent-encoded) behind the comma,
     * or the bare markup itself - fig.savefig(buf, format="svg") decoded and
     * printed. The data URI forms run first, so the markup inside a plain data URI
     * is not caught by the bare pattern with the prefix left standing in the text.
     */
    private const SVG_BASE64_PATTERN = '/data:image\/svg\+xml(?:;charset=[\w-]+)?;base64,([A-Za-z0-9+\/=]+)/i';

    private const SVG_DATA_URI_PATTERN = '/data:image\/svg\+xml(?:;charset=[\w-]+)?(?:;utf8)?,(%3C(?:svg|%3Fxml)[^\s"\'`]*|(?:<\?xml[^>]*\?>\s*)?<svg\b[\s\S]*?<\/svg>)/i';

    private const SVG_MARKUP_PATTERN = '/(?:<\?xml[^>]*\?>\s*)?(?:<!DOCTYPE\s+svg[^>]*>\s*)?<svg\b[^>]*>[\s\S]*?<\/svg>/i';

    /** @var array<int,array<string,mixed>> */
    private array $collected = [];

    public function __construct(
        private readonly AttachmentService $attachments
    ) {}

    /**
     * Stores one base64 image - with or without a data URI prefix - and returns
     * the attachment description the 'generated_image' auxiliary is built from.
     *
     * @return array<string,mixed>|null Null when the image could not be stored.
     */
    public function store(string $base64OrDataUri, string $label = 'Plot', string $filePrefix = 'sandbox'): ?array
    {
        if (preg_match(self::SVG_BASE64_PATTERN, $base64OrDataUri, $svg) === 1) {
            $markup = base64_decode(preg_replace('/\s+/', '', $svg[1]) ?? '', true);

            return is_string($markup) ? $this->storeSvg($markup, $label, $filePrefix) : null;
        }

        if (SvgSanitizer::looksLikeSvg($base64OrDataUri)) {
            return $this->storeSvg($base64OrDataUri, $label, $filePrefix);
        }

        $data = preg_replace('/\s+/', '', $base64OrDataUri) ?? $base64OrDataUri;

        if ($data === '') {
            return null;
        }

        try {
            /*
             * 'original' rather than a size preset: these presets exist for the
             * image generation tool, where the user picks the format, and the
             * default squares anything unknown to 1024x1024. A plot has its own
             * aspect ratio and has to keep it.
             */
            $stored = $this->attachments->storeFromBase64(
                $data,
                'private',
                $filePrefix.'_'.time().'_'.count($this->collected).'.png',
                'original'
            );
        } catch (\Throwable $e) {
            Log::error('[SandboxImages] Could not store a sandbox image', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $stored) {
            Log::error('[SandboxImages] The attachment service returned nothing for a sandbox image');

            return null;
        }

        return [
            'url' => $stored['url'],
            'uuid' => $stored['uuid'],
            'mime' => $stored['mime'],
            'name' => $stored['name'],
            'prompt' => $label,
        ];
    }

    /**
     * Stores SVG markup as a file of its own kind. Not through storeFromBase64():
     * that path sniffs and resizes raster images, and a bare <svg> without an XML
     * prolog sniffs as text. The extension settles the type, and the attachment
     * service strips scripts before writing.
     */
    public function storeSvg(string $markup, string $label = 'Plot', string $filePrefix = 'sandbox'): ?array
    {
        $markup = trim($markup);

        if ($markup === '') {
            return null;
        }

        try {
            $stored = $this->attachments->storeGeneratedFile(
                $markup,
                $filePrefix.'_'.time().'_'.count($this->collected).'.svg',
                'private',
                'image/svg+xml'
            );
        } catch (\Throwable $e) {
            Log::error('[SandboxImages] Could not store a sandbox SVG', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $stored) {
            Log::error('[SandboxImages] The attachment service returned nothing for a sandbox SVG');

            return null;
        }

        return [
            'url' => $stored['url'],
            'uuid' => $stored['uuid'],
            'mime' => $stored['mime'],
            'name' => $stored['name'],
            'prompt' => $label,
        ];
    }

    /**
     * Stores an image a tool got as bytes rather than as text, and collects it
     * for the request to pick up.
     *
     * This is the image generation tool's way into the same pipeline: its MCP
     * server answers with an image block, so there is no text to run
     * {@see extractFromText()} over, but the picture has to reach the message
     * exactly the same way a plot does.
     */
    public function collect(string $base64OrDataUri, string $label, string $filePrefix = 'generated'): bool
    {
        $stored = $this->store($base64OrDataUri, $label, $filePrefix);

        if ($stored === null) {
            return false;
        }

        $this->collected[] = $stored;

        return true;
    }

    /**
     * Takes every base64 image out of program output, stores it, and collects it
     * for the request to pick up.
     *
     * Returns the text with each image replaced by a one line note, so the model
     * knows an image exists - and that it does not need to describe or re-emit it
     * - without paying for the bytes.
     */
    public function extractFromText(string $text): string
    {
        $hasPng = str_contains($text, 'iVBORw0KGgo');
        $hasSvg = stripos($text, '<svg') !== false || stripos($text, 'image/svg+xml') !== false;

        if (! $hasPng && ! $hasSvg) {
            return $text;
        }

        $found = 0;

        $keep = function (?array $stored) use (&$found): string {
            if ($stored === null) {
                return '[an image was produced but could not be stored]';
            }

            $this->collected[] = $stored;
            $found++;

            return '[image '.$found.' was produced and is shown to the user]';
        };

        $cleaned = $text;

        if ($hasSvg) {
            $cleaned = $this->replaceAll(
                self::SVG_BASE64_PATTERN,
                fn (array $m): string => $keep($this->storeSvgBase64($m[1])),
                $cleaned
            );

            $cleaned = $this->replaceAll(
                self::SVG_DATA_URI_PATTERN,
                fn (array $m): string => $keep($this->storeSvg(str_starts_with($m[1], '%') ? rawurldecode($m[1]) : $m[1])),
                $cleaned
            );

            $cleaned = $this->replaceAll(
                self::SVG_MARKUP_PATTERN,
                fn (array $m): string => $keep($this->storeSvg($m[0])),
                $cleaned
            );
        }

        if ($hasPng) {
            $cleaned = $this->replaceAll(
                self::PNG_PATTERN,
                fn (array $m): string => $keep($this->store($m[1])),
                $cleaned
            );
        }

        return trim($cleaned);
    }

    private function storeSvgBase64(string $base64): ?array
    {
        $markup = base64_decode(preg_replace('/\s+/', '', $base64) ?? '', true);

        return is_string($markup) ? $this->storeSvg($markup) : null;
    }

    /**
     * preg_replace_callback that never loses the output: on a preg failure the
     * text is returned as it was, blob and all - the lesser evil.
     */
    private function replaceAll(string $pattern, callable $callback, string $text): string
    {
        $result = preg_replace_callback($pattern, $callback, $text);

        if ($result === null) {
            Log::warning('[SandboxImages] Image extraction failed, leaving the output untouched');

            return $text;
        }

        return $result;
    }

    /**
     * Everything collected since the last call, and forget it.
     *
     * @return array<int,array<string,mixed>>
     */
    public function drain(): array
    {
        $collected = $this->collected;
        $this->collected = [];

        return $collected;
    }
}
