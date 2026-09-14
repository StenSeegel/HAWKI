<?php

namespace App\Services\Chat\Attachment;

use App\Models\Attachment;
use App\Services\Storage\FileStorageService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collects the figures the file converter extracted from a document so a
 * vision-capable model can see them next to the document text.
 *
 * The HAWKI file converter (>= 3.x, SAVE_DOCUMENT_IMAGE_REFS=true) writes
 * every figure as assets/image_N.webp and marks its position in the markdown
 * with "> [Image: ../assets/image_N.webp]". AtchDocumentHandler flattens the
 * zip into the attachment's output/ folder, so the images sit next to the
 * markdown chunks. This service reads them back, drops duplicates and
 * decorative images, downscales the rest and caps the count, all driven by
 * config('file_converter.document_images').
 */
class DocumentImageService
{
    private const IMAGE_EXTENSIONS = ['webp', 'png', 'jpg', 'jpeg'];

    public function __construct(
        protected FileStorageService $storageService
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) config('file_converter.document_images.enabled', true);
    }

    /**
     * Flags the user messages whose document figures should travel with the
     * request by setting `include_figures` on them. Chat APIs are stateless,
     * so the images would otherwise be re-sent on every turn for as long as
     * the document sits in the history. With `recent_turns` = 1 (default) only
     * the newest user message carries them, the text of older turns keeps the
     * "[Image: ...]" markers and the assistant's earlier answer about the
     * figures stays in the history. 0 sends them on every turn.
     *
     * @param array<int, array<string, mixed>> $messages Messages with a `role` key, in conversation order.
     * @return array<int, array<string, mixed>>
     */
    public static function markMessagesWithFigures(array $messages): array
    {
        $recentTurns = self::recentTurns();
        if ($recentTurns <= 0) {
            foreach ($messages as $key => $message) {
                $messages[$key]['include_figures'] = true;
            }
            return $messages;
        }

        $remaining = $recentTurns;
        foreach (array_reverse(array_keys($messages)) as $key) {
            if ($remaining === 0) {
                break;
            }
            if (($messages[$key]['role'] ?? null) === 'user') {
                $messages[$key]['include_figures'] = true;
                $remaining--;
            }
        }

        return $messages;
    }

    /**
     * The converters are also exercised outside a booted application (plain
     * PHPUnit tests), where no config repository exists: fall back to 1 there.
     */
    private static function recentTurns(): int
    {
        try {
            if (\Illuminate\Container\Container::getInstance()->bound('config')) {
                return (int) config('file_converter.document_images.recent_turns', 1);
            }
        } catch (Throwable) {
            // no container or config available
        }

        return 1;
    }

    /**
     * Shrinks the converter output before it is stored. The converter writes
     * figures as lossless webp (a 5 MB PDF came back with 18 MB of images), and
     * many of them are page decorations or the same logo on every page. Every
     * image is decoded once here: duplicates and images below `min_dimension`
     * are dropped, the rest is downscaled to `max_dimension` and re-encoded as
     * lossy webp. Text files (chunks, meta.json) pass through untouched.
     *
     * @param array<string, string> $outputs relative path => contents, as returned by the converter
     * @return array<string, string>
     */
    public function optimizeForStorage(array $outputs): array
    {
        if (!$this->isEnabled()) {
            return $outputs;
        }

        $optimized = [];
        $seen = [];
        foreach ($outputs as $relativePath => $contents) {
            $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
            if (!in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                $optimized[$relativePath] = $contents;
                continue;
            }

            $hash = sha1((string) $contents);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;

            $prepared = $this->prepare((string) $contents, true);
            if ($prepared === null) {
                continue;
            }

            // Re-encoding always yields webp; keep the file name in sync so the
            // "[Image: ../assets/<file>]" markers and the mime stay correct.
            $storedPath = $prepared['mime'] === 'image/webp'
                ? preg_replace('/\.[^.]+$/', '.webp', $relativePath)
                : $relativePath;
            $optimized[$storedPath] = $prepared['data'];
        }

        return $optimized;
    }

    /**
     * Returns the figures of a document attachment, ready to be embedded in a
     * model request.
     *
     * @return array<int, array{name: string, mime: string, data: string}> data is the raw (downscaled) image binary
     */
    public function collect(Attachment $attachment): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $maxCount = max(0, (int) config('file_converter.document_images.max_per_document', 10));
        if ($maxCount === 0) {
            return [];
        }

        try {
            $paths = [];
            foreach (self::IMAGE_EXTENSIONS as $extension) {
                $paths = array_merge(
                    $paths,
                    $this->storageService->listOutputFilesByType($attachment->uuid, $attachment->category, $extension)
                );
            }
        } catch (Throwable $e) {
            Log::warning('[DocumentImageService] Could not list document images', [
                'uuid' => $attachment->uuid,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        if ($paths === []) {
            return [];
        }

        // Rendered pages before extracted figures, each in document order:
        // page_001, page_002, ... then image_0, image_1, ... image_10 (natural,
        // not lexical).
        usort($paths, static function (string $a, string $b): int {
            $kindA = self::classify(basename($a))['kind'];
            $kindB = self::classify(basename($b))['kind'];
            if ($kindA !== $kindB) {
                return $kindA === 'page' ? -1 : 1;
            }

            return strnatcmp(basename($a), basename($b));
        });

        // Pages have their own cap: a deck is its slides, and ten of thirty
        // would be a truncated deck, whereas ten of thirty decorative figures
        // is plenty.
        $maxPages = max(0, (int) config('page_render.max_pages', 20));

        // Files are read one by one and only until the caps are reached, so a
        // document with 30 figures costs about 10 reads per request, not 30.
        // That matters when the storage disk is S3.
        $images = [];
        $seen = [];
        $counts = ['page' => 0, 'figure' => 0];
        foreach ($paths as $path) {
            $classified = self::classify(basename($path));
            $cap = $classified['kind'] === 'page' ? $maxPages : $maxCount;
            if ($counts[$classified['kind']] >= $cap) {
                continue;
            }

            $contents = $this->storageService->readFile($path);
            if ($contents === null || $contents === '') {
                continue;
            }

            // Logos and headers repeat on every page; send each distinct image once.
            $hash = sha1($contents);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;

            $prepared = $this->prepare($contents);
            if ($prepared === null) {
                continue;
            }
            $images[] = [
                'name' => basename($path),
                'kind' => $classified['kind'],
                'number' => $classified['number'],
                'mime' => $prepared['mime'],
                'data' => $prepared['data'],
            ];
            $counts[$classified['kind']]++;
        }

        return $images;
    }

    /**
     * A one-line note telling the model what the images that follow are: the
     * rendered pages of the document, the figures cut out of it, or both -
     * and how they map to the "[Page N of <file>]" / "[Figure N of <file>]"
     * markers in the document text.
     *
     * Named by number, never by stored file name: the model was shown
     * "image_2.webp" here and in the markers, and then used that as the name
     * of the uploaded file. The only file name it should ever repeat back is
     * the one the user uploaded.
     *
     * @param array<int, array{name: string, kind: string, number: int, mime: string, data: string}> $images
     */
    public function describe(Attachment $attachment, array $images): string
    {
        $name = $attachment->name;
        $pages = array_values(array_filter($images, static fn(array $i): bool => ($i['kind'] ?? 'figure') === 'page'));
        $figures = array_values(array_filter($images, static fn(array $i): bool => ($i['kind'] ?? 'figure') !== 'page'));

        $clauses = [];
        if ($pages !== []) {
            $numbers = implode(', ', array_map(static fn(array $i): string => 'Page '.$i['number'], $pages));
            $clauses[] = count($pages) === 1
                ? sprintf('the image that follows is %s as laid out, marked [%s of %s] in the text', $numbers, $numbers, $name)
                : sprintf('the %d images that follow are its pages as laid out, marked [Page N of %s] in the text, in this order: %s', count($pages), $name, $numbers);
        }
        if ($figures !== []) {
            $numbers = implode(', ', array_map(static fn(array $i): string => 'Figure '.$i['number'], $figures));
            $lead = $pages === [] ? 'the' : 'then the';
            $clauses[] = count($figures) === 1
                ? sprintf('%s image that follows is %s, marked [%s of %s] in the text', $lead, $numbers, $numbers, $name)
                : sprintf('%s %d images that follow are the figures marked [Figure N of %s] in the text, in this order: %s', $lead, count($figures), $name, $numbers);
        }

        return sprintf('[IMAGES FROM %s: %s]', $name, implode('; ', $clauses));
    }

    /**
     * What a stored image is, from its name: a rendered page ("page_003") or
     * a figure the converter cut out ("image_2"), and its 1-based number.
     *
     * The converter names figures image_0, image_1, ... in document order and
     * the renderer names pages page_001, page_002, ... so the number is in
     * the name. AtchDocumentHandler derives it the same way for the markers
     * in the text, which keeps the two in step even though
     * optimizeForStorage() drops duplicate and decorative figures - a gap in
     * the numbering is correct, a renumbering would point the model at the
     * wrong picture.
     *
     * @return array{kind: 'page'|'figure', number: int}
     */
    public static function classify(string $assetName): array
    {
        $stem = pathinfo($assetName, PATHINFO_FILENAME);

        if (preg_match('/^page[_-]?(\d+)$/i', $stem, $m) === 1) {
            return ['kind' => 'page', 'number' => max(1, (int) $m[1])];
        }

        return [
            'kind' => 'figure',
            'number' => preg_match('/(\d+)/', $stem, $m) === 1 ? ((int) $m[1]) + 1 : 1,
        ];
    }

    /**
     * Validates, filters and downscales one image. Returns null when the image
     * cannot be decoded or is too small to carry information. With
     * $forceReencode the image is re-encoded as lossy webp even when it
     * already fits, which is what shrinks the converter's lossless output.
     *
     * @return array{mime: string, data: string}|null
     */
    protected function prepare(string $binary, bool $forceReencode = false): ?array
    {
        if ($binary === '' || !function_exists('getimagesizefromstring')) {
            return null;
        }

        $info = @getimagesizefromstring($binary);
        if (!is_array($info)) {
            return null;
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        $mime = (string) ($info['mime'] ?? '');

        $minDimension = (int) config('file_converter.document_images.min_dimension', 100);
        if ($width < $minDimension || $height < $minDimension) {
            return null;
        }

        $maxDimension = (int) config('file_converter.document_images.max_dimension', 1024);
        $fits = $maxDimension <= 0 || max($width, $height) <= $maxDimension;
        if ($fits && !$forceReencode) {
            return ['mime' => $mime, 'data' => $binary];
        }

        $target = $fits ? max($width, $height) : $maxDimension;
        $resized = $this->downscale($binary, $width, $height, $target);

        return $resized ?? ['mime' => $mime, 'data' => $binary];
    }

    /**
     * Scales with GD so the longest side equals $maxDimension. The result
     * is webp when GD can write it, png otherwise. Returns null when GD is not
     * available or fails, in which case the caller sends the original.
     *
     * @return array{mime: string, data: string}|null
     */
    protected function downscale(string $binary, int $width, int $height, int $maxDimension): ?array
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecopyresampled')) {
            return null;
        }

        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            return null;
        }

        $scale = $maxDimension / max($width, $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($target === false) {
            imagedestroy($source);
            return null;
        }

        // Keep transparency; flatten onto white when the output format lacks alpha.
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 255, 255, 255, 127);
        imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);

        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        if (function_exists('imagewebp')) {
            $ok = imagewebp($target, null, 85);
            $mime = 'image/webp';
        } else {
            $ok = imagepng($target, null, 6);
            $mime = 'image/png';
        }
        $data = ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        if (!$ok || !is_string($data) || $data === '') {
            return null;
        }

        return ['mime' => $mime, 'data' => $data];
    }
}
