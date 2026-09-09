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

        // Document order: image_0, image_1, ... image_10 (natural, not lexical).
        usort($paths, static fn(string $a, string $b) => strnatcmp(basename($a), basename($b)));

        // Files are read one by one and only until the cap is reached, so a
        // document with 30 figures costs about 10 reads per request, not 30.
        // That matters when the storage disk is S3.
        $images = [];
        $seen = [];
        foreach ($paths as $path) {
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
                'mime' => $prepared['mime'],
                'data' => $prepared['data'],
            ];
            if (count($images) >= $maxCount) {
                break;
            }
        }

        return $images;
    }

    /**
     * A one-line note telling the model which figures follow and how they map
     * to the "[Image: ../assets/<file>]" markers in the document text.
     *
     * @param array<int, array{name: string, mime: string, data: string}> $images
     */
    public function describe(Attachment $attachment, array $images): string
    {
        $names = implode(', ', array_column($images, 'name'));

        return sprintf(
            '[FIGURES FROM %s: the following %d image%s are the figures referenced as [Image: ../assets/<file>] in the attached file, in this order: %s]',
            $attachment->name,
            count($images),
            count($images) === 1 ? '' : 's',
            $names
        );
    }

    /**
     * Validates, filters and downscales one image. Returns null when the image
     * cannot be decoded or is too small to carry information.
     *
     * @return array{mime: string, data: string}|null
     */
    protected function prepare(string $binary): ?array
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
        if ($maxDimension <= 0 || max($width, $height) <= $maxDimension) {
            return ['mime' => $mime, 'data' => $binary];
        }

        $resized = $this->downscale($binary, $width, $height, $maxDimension);

        return $resized ?? ['mime' => $mime, 'data' => $binary];
    }

    /**
     * Downscales with GD so the longest side equals $maxDimension. The result
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
