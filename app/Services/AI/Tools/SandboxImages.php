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
 * Files are the same story with a different ending. A deck or a spreadsheet the
 * sandbox built has exactly one way out - printed as a data URI - and a 160 kB
 * .pptx is 220,000 characters of base64, which the 8000 character cap would cut
 * into garbage. So a document data URI is lifted out the same way, stored with
 * its name, and announced as a 'container_file': the auxiliary the native code
 * interpreter's files already arrive as, which the frontend turns into a download
 * link. The model is told to link the file as sandbox:/tmp/<name>, the reference
 * that renderer resolves.
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

    /**
     * A data URI of anything that is not an image: the MIME type, optional
     * parameters (RFC 2397 allows them, and `;name=deck.pptx` is how a program
     * names the file it prints - a bare data URI has no filename), then the
     * base64. Images are excluded because they take the picture route above;
     * a PDF is a document here, so text/... and application/... both match.
     *
     * A parameter value runs to the next ';' or ',' - not to the next space.
     * The sandbox percent-encodes the name, but a program may print it raw,
     * and "Anthropomorphisierung von LLMs.pptx" once ended the match at its
     * first space, which left the deck's base64 in the text as if it were prose.
     */
    private const FILE_DATA_URI_PATTERN = '/data:(?!image\/)([a-z0-9.+-]+\/[a-z0-9.+-]+)((?:;[a-z0-9_-]+=[^;,\n]*)*);base64,([A-Za-z0-9+\/=]+)/i';

    /**
     * The extension a file gets when the program printed no name, by MIME type.
     * Anything else is stored under its subtype, which is right often enough
     * (application/zip, application/json, text/csv) and never wrong by much.
     */
    private const EXTENSION_BY_MIME = [
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/pdf' => 'pdf',
        'text/csv' => 'csv',
        'text/plain' => 'txt',
        'text/markdown' => 'md',
        'application/json' => 'json',
        'application/zip' => 'zip',
    ];

    /** @var array<int,array<string,mixed>> */
    private array $collected = [];

    /**
     * Everything stored in this request, kept after {@see drain()}: the code
     * interpreter offers these back to the model as /work/<name> in its next
     * call, and by then the request has long drained them into auxiliaries.
     *
     * @var array<int,array<string,mixed>>
     */
    private array $produced = [];

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

        $this->remember($stored);

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
        $hasFile = stripos($text, ';base64,') !== false
            && preg_match('/data:(?!image\/)[a-z0-9.+-]+\/[a-z0-9.+-]+(?:;[^,\n]*)?;base64,/i', $text) === 1;

        if (! $hasPng && ! $hasSvg && ! $hasFile) {
            return $text;
        }

        $found = 0;
        $filesFound = 0;

        $keep = function (?array $stored) use (&$found): string {
            if ($stored === null) {
                return '[an image was produced but could not be stored]';
            }

            $this->remember($stored);
            $found++;

            return '[image '.$found.' was produced and is shown to the user]';
        };

        $cleaned = $text;

        if ($hasFile) {
            $cleaned = $this->replaceAll(
                self::FILE_DATA_URI_PATTERN,
                function (array $m) use (&$filesFound): string {
                    $stored = $this->storeFile($m[1], $m[2], $m[3]);

                    if ($stored === null) {
                        return '[a file was produced but could not be stored]';
                    }

                    $this->remember($stored);
                    $filesFound++;

                    // The link the model is told to copy has to parse as Markdown:
                    // a destination with a space in it is not a link to CommonMark,
                    // and "Anthropomorphisierung von LLMs.pptx" is a name a model
                    // picks. Percent-encoded, it parses, and the frontend decodes
                    // it back to the file name it remembers.
                    return '[file '.$filesFound.' "'.$stored['filename'].'" was produced and is offered to the user as a download. '
                        .'Link it in your answer exactly as ['.$stored['filename'].'](sandbox:/tmp/'.self::linkSegment($stored['filename']).') - HAWKI points that link at the stored file.]';
                },
                $cleaned
            );
        }

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

    /**
     * Stores a file the program printed as a data URI and describes it the way a
     * 'container_file' auxiliary is built from - the shape ContainerFiles::fetch()
     * returns for the native interpreter's files, so the frontend needs no second
     * renderer. 'kind' tells the request which auxiliary to wrap it in.
     *
     * @param  string  $mime  the MIME type the data URI declared
     * @param  string  $params  its parameters, e.g. ";name=deck.pptx", or ""
     *
     * @return array<string,mixed>|null Null when the file could not be stored.
     */
    public function storeFile(string $mime, string $params, string $base64): ?array
    {
        $bytes = base64_decode(preg_replace('/\s+/', '', $base64) ?? '', true);

        if (! is_string($bytes) || $bytes === '') {
            Log::warning('[SandboxImages] A printed file data URI did not decode', ['mime' => $mime]);

            return null;
        }

        $mime = strtolower($mime);
        $filename = $this->fileName($mime, $params);

        try {
            $stored = $this->attachments->storeGeneratedFile($bytes, $filename, 'private', $mime);
        } catch (\Throwable $e) {
            Log::error('[SandboxImages] Could not store a sandbox file', ['filename' => $filename, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $stored) {
            Log::error('[SandboxImages] The attachment service returned nothing for a sandbox file', ['filename' => $filename]);

            return null;
        }

        return [
            'kind' => 'file',
            'filename' => $stored['name'],
            'url' => $stored['url'],
            'uuid' => $stored['uuid'],
            'mime' => $stored['mime'],
            'name' => $stored['name'],
        ];
    }

    /**
     * The name a printed file is stored under: the `name=` parameter of its data
     * URI if the program gave one (percent-decoded, reduced to a basename), else
     * a generated one with the extension its MIME type implies - so the download
     * opens in the right application either way.
     */
    private function fileName(string $mime, string $params): string
    {
        $given = '';

        if (preg_match('/;name=([^;]+)/i', $params, $m) === 1) {
            $given = basename(trim(rawurldecode($m[1])));
        }

        if ($given !== '' && $given !== '.' && $given !== '..') {
            return $given;
        }

        $extension = self::EXTENSION_BY_MIME[$mime]
            ?? preg_replace('/[^a-z0-9]+/', '', (string) substr($mime, (int) strrpos($mime, '/') + 1))
            ?: 'bin';

        return 'sandbox_'.time().'_'.count($this->collected).'.'.$extension;
    }

    /**
     * A file name as a Markdown link destination segment: percent-encoded, so
     * spaces and umlauts do not end the destination early.
     */
    public static function linkSegment(string $filename): string
    {
        return str_replace('%2F', '/', rawurlencode($filename));
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

    private function remember(array $stored): void
    {
        $this->collected[] = $stored;
        $this->produced[] = $stored;
    }

    /**
     * Every image and file stored in this request so far, drained or not.
     *
     * @return array<int,array<string,mixed>>
     */
    public function produced(): array
    {
        return $this->produced;
    }

    /**
     * Start of a request: nothing has been produced yet. The singleton outlives
     * a request under Octane, so the tool that offers produced files says when
     * a request begins rather than trusting the list to be empty.
     */
    public function forgetProduced(): void
    {
        $this->produced = [];
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
