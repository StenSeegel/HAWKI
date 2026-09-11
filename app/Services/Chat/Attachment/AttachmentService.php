<?php

namespace App\Services\Chat\Attachment;


use App\Models\AiConvMsg;
use App\Models\Message;
use App\Models\Attachment;

use App\Services\Chat\Attachment\AttachmentFactory;

use App\Services\Storage\FileStorageService;
use App\Services\Storage\Interfaces\StorageServiceInterface;
use App\Services\Storage\StorageServiceFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

use Exception;

class AttachmentService{


    public function __construct(
        private FileStorageService $storageService
    ) {}

    public function store($file, $category): ?array
    {
        try{
            // GET FILE TYPE
            $mime = $file->getMimeType();
            $type = $this->convertToAttachmentType($mime);
            // CREATE HANDLER
            $attachmentHandler = AttachmentFactory::create($type);
            // STORE FILE BASED ON TYPE
            $result = $attachmentHandler->store($file, $category);

            return $result;
        }
        catch(Exception $e){
            Log::error("Error storing file: $e");
            return null;
        }
    }



    /**
     * The address a stored file is shown at in the chat: the attachment's uuid
     * on a route that checks the session and the owner, and nothing else.
     *
     * Not the signed storage url. That one expires after 24 hours, and it is
     * written into the message the moment a picture is generated - so every
     * generated image, plot and container file went dark a day later, and the
     * chat log had to rewrite the urls on every load to hide it. This url is
     * good for as long as the attachment exists.
     */
    public function viewUrl(string $uuid, string $category): string
    {
        return match ($category) {
            'private' => route('attachment.view.private', ['uuid' => $uuid]),
            'group' => route('attachment.view.group', ['uuid' => $uuid]),
            default => (string) $this->storageService->getUrl($uuid, $category),
        };
    }

    /**
     * The response for viewUrl(): the file inline, its persistent copy or - before
     * the message that carries it is saved - the temp one.
     */
    public function inlineResponse(Attachment $attachment): \Symfony\Component\HttpFoundation\Response
    {
        $bytes = $this->retrieve($attachment);

        if ($bytes === null || $bytes === '') {
            abort(404, 'File not found');
        }

        return response($bytes, 200, $this->inlineHeaders($attachment) + [
            // The bytes behind a uuid never change, so the browser may keep them.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Headers for a file shown in the browser. An SVG is served from HAWKI's
     * origin, so it is kept from running anything: sanitized when stored, and
     * locked down again here.
     *
     * @return array<string,string>
     */
    public function inlineHeaders(Attachment $attachment): array
    {
        $name = str_replace(['"', "\r", "\n"], '', (string) $attachment->name);

        $headers = [
            'Content-Type' => (string) $attachment->mime,
            'Content-Disposition' => 'inline; filename="'.$name.'"; filename*=UTF-8\'\''.rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($attachment->mime === 'image/svg+xml') {
            $headers['Content-Security-Policy'] = SvgSanitizer::CONTENT_SECURITY_POLICY;
        }

        return $headers;
    }

    public function retrieve(Attachment $attachment, $outputType = null)
    {
        $uuid = $attachment->uuid;
        $category = $attachment->category;

        if($outputType){
            $attachmentHandler = AttachmentFactory::create($attachment->type);
            return $attachmentHandler->retrieveContext($uuid, $category, $outputType);
        }
        else{
            try{
                $file = $this->storageService->retrieve($uuid, $category);

                /*
                 * A file only reaches its persistent folder when the message that
                 * carries it is saved. A generated image is attached to the next
                 * message the moment it appears - to edit it, or as context for a
                 * vision model - which can be before that save has happened, and
                 * the persistent read then finds nothing. So the temp copy is the
                 * fallback rather than a failure.
                 */
                if ($file === null) {
                    $file = $this->storageService->retrieve($uuid, $category, true);
                }

                return $file;
            }
            catch(Exception $e){
                Log::error("Error retrieving file", ["UUID"=> $uuid, "category"=> $category]);
                return null;
            }
        }
    }


    public function getFileUrl(Attachment $attachment, $outputType = null)
    {
        $uuid = $attachment->uuid;
        $category = $attachment->category;

        if($outputType){
            $urls = $this->storageService->getOutputFilesUrls($uuid, $category, $outputType);
            return $urls[0];
        }
        else{
            try{
                return $this->storageService->getUrl($uuid, $category);
            }
            catch(Exception $e){
                Log::error("Error retrieving file", ["UUID"=> $uuid, "category"=> $category]);
                return null;
            }
        }
    }



    public function delete(Attachment $attachment): bool
    {
        try{
            $deleted = $this->storageService->delete($attachment->uuid, $attachment->category);
            if(!$deleted){
                return false;
            }

            $attachment->delete();
            return true;
        }
        catch(Exception $e){
            Log::error(message: "Failed to remove attachment: $e" );
            return false;
        }
    }


    public function convertToAttachmentType($mime){

        if(str_contains($mime, 'pdf') ||
           str_contains($mime, 'word')){
            return 'document';
        }
        if(str_contains($mime, 'image')){
            return 'image';
        }
    }


    public function assignToMessage(AiConvMsg|Message $message, array $data): ?string
    {
        try{
            $category = $message instanceof AiConvMsg ? 'private' : 'group';
            $movedToPersistent = $this->storageService->moveFileToPersistentFolder($data['uuid'], $category);

            if (!$movedToPersistent) {
                Log::warning('[ATTACHMENT SERVICE] Failed to move attachment to persistent storage before linking', [
                    'uuid' => $data['uuid'] ?? null,
                    'category' => $category,
                    'message_id' => $message->id ?? null,
                    'message_type' => get_class($message),
                ]);
            }

            // Check if an attachment with this UUID already exists (could be an orphaned entry from storeFromBase64)
            $existingAttachment = Attachment::where('uuid', $data['uuid'])->first();

            if ($existingAttachment) {
                $belongsToAnotherMessage = $existingAttachment->attachable_id !== null
                    && !($existingAttachment->attachable_type === get_class($message)
                        && (int)$existingAttachment->attachable_id === (int)$message->id);

                if ($belongsToAnotherMessage) {
                    // Re-parenting would take the attachment away from the message
                    // that owns it - a generated image sent along as context has to
                    // become a copy of its own.
                    return $this->copyAttachmentToMessage($existingAttachment, $message, $category) !== null
                        ? 'true'
                        : false;
                }

                // Orphan from storeFromBase64(), so it is simply adopted.
                $existingAttachment->attachable_id = $message->id;
                $existingAttachment->attachable_type = get_class($message);
                $existingAttachment->save();
            } else {
                // If it doesn't exist, create a new one using the relationship
                $type = $this->convertToAttachmentType($data['mime']);
                $message->attachments()->create([
                    'uuid' => $data['uuid'],
                    'name' => $data['name'],
                    'category' => $category,
                    'mime' => $data['mime'],
                    'type' => $type,
                    'user_id' => Auth::id()
                ]);
            }
            return 'true';
        }
        catch(Exception $e){
            Log::error('[ATTACHMENT SERVICE] Failed to assign attachment to message', [
                'uuid' => $data['uuid'] ?? null,
                'message_id' => $message->id ?? null,
                'message_type' => get_class($message),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Duplicates an attachment that already belongs to another message, file and
     * row, so both messages keep their own copy.
     */
    private function copyAttachmentToMessage(Attachment $source, AiConvMsg|Message $message, string $category): ?Attachment
    {
        $contents = $this->retrieve($source);
        if ($contents === null || $contents === '') {
            Log::error('[ATTACHMENT SERVICE] Could not read the attachment that should be copied', [
                'uuid' => $source->uuid,
                'category' => $source->category,
            ]);

            return null;
        }

        $uuid = (string)\Illuminate\Support\Str::uuid();
        $stored = $this->storageService->store(
            file: $contents,
            filename: $source->name,
            uuid: $uuid,
            category: $category,
            temp: false
        );

        if (!$stored) {
            Log::error('[ATTACHMENT SERVICE] Could not store the copied attachment', [
                'source_uuid' => $source->uuid,
                'uuid' => $uuid,
            ]);

            return null;
        }

        return $message->attachments()->create([
            'uuid' => $uuid,
            'name' => $source->name,
            'category' => $category,
            'mime' => $source->mime,
            'type' => $source->type,
            'user_id' => Auth::id(),
        ]);
    }

    /**
     * Stores a file a tool produced - a CSV the code interpreter wrote, say - as a
     * temp attachment of the current user, the way generated images are stored.
     * It becomes permanent when the message carrying it is saved, which links it
     * through assignToMessage().
     *
     * @param  string|null  $mimeHint  what the sender declared, trusted only when it is specific
     * @return array{uuid: string, url: string, mime: string, name: string}|null
     */
    public function storeGeneratedFile(string $bytes, string $filename, string $category, ?string $mimeHint = null): ?array
    {
        try {
            $filename = basename(trim($filename));
            if ($filename === '' || $filename === '.' || $filename === '..') {
                $filename = 'file';
            }

            $mime = $this->mimeOfGeneratedFile($bytes, $filename, $mimeHint);

            // An SVG is served from HAWKI's origin, so it must not carry a script.
            if ($mime === 'image/svg+xml') {
                $clean = SvgSanitizer::sanitize($bytes);
                if ($clean === null) {
                    Log::warning('[ATTACHMENT SERVICE] A generated SVG is not well-formed and was not stored', ['filename' => $filename]);

                    return null;
                }
                $bytes = $clean;
            }

            $uuid = \Illuminate\Support\Str::uuid()->toString();

            $stored = $this->storageService->store(
                file: $bytes,
                filename: $filename,
                uuid: $uuid,
                category: $category,
                temp: true
            );

            if (! $stored) {
                Log::error('[ATTACHMENT SERVICE] Failed to store generated file', ['filename' => $filename]);

                return null;
            }

            \App\Models\Attachment::create([
                'uuid' => $uuid,
                'name' => $filename,
                'category' => $category,
                'mime' => $mime,
                'type' => $this->convertToAttachmentType($mime) ?? 'other',
                'user_id' => Auth::id(),
            ]);

            return [
                'uuid' => $uuid,
                'url' => $this->viewUrl($uuid, $category),
                'mime' => $mime,
                'name' => $filename,
            ];
        } catch (Exception $e) {
            Log::error('[ATTACHMENT SERVICE] Error storing generated file: '.$e->getMessage(), ['filename' => $filename]);

            return null;
        }
    }

    /**
     * The MIME type of a generated file. The extension wins for the text formats
     * that sniffing cannot tell apart (a CSV is text/plain to finfo), then a
     * specific hint from the sender, then sniffing the bytes.
     */
    public function mimeOfGeneratedFile(string $bytes, string $filename, ?string $mimeHint = null): string
    {
        $byExtension = match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'csv' => 'text/csv',
            'tsv' => 'text/tab-separated-values',
            'json' => 'application/json',
            'md' => 'text/markdown',
            'txt' => 'text/plain',
            'html', 'htm' => 'text/html',
            'xml' => 'application/xml',
            'svg' => 'image/svg+xml',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'py' => 'text/x-python',
            default => null,
        };

        if ($byExtension !== null) {
            return $byExtension;
        }

        $hint = is_string($mimeHint) ? strtolower(trim(explode(';', $mimeHint)[0])) : '';
        if ($hint !== '' && ! in_array($hint, ['application/octet-stream', 'binary/octet-stream', 'text/plain'], true)) {
            return $hint;
        }

        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return is_string($sniffed) && $sniffed !== '' ? $sniffed : 'application/octet-stream';
    }

    /**
     * Store a base64-encoded image (used for AI-generated images)
     *
     * @param string $base64Data Base64-encoded image data (without data:image/png;base64, prefix)
     * @param string $category Storage category ('private' or 'group')
     * @param string $filename Optional filename (default: 'generated_image.png')
     * @param string $imageSize UI size selection (small|medium|big)
     * @return array|null Array with 'uuid', 'url', 'mime', 'name' or null on failure
     */
    public function storeFromBase64(string $base64Data, string $category, string $filename = 'generated_image.png', string $imageSize = 'medium', ?string $imageRatio = null): ?array
    {
        try {
            // Remove data URI prefix if present
            if (str_contains($base64Data, 'base64,')) {
                $base64Data = explode('base64,', $base64Data)[1];
            }

            // Decode base64 data
            $imageData = base64_decode($base64Data);
            if ($imageData === false) {
                Log::error("Failed to decode base64 image data");
                return null;
            }

            // Generate UUID for the file
            $uuid = \Illuminate\Support\Str::uuid()->toString();

            // Detect MIME type from decoded data
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($imageData);
            if (!is_string($mime) || $mime === '') {
                $mime = 'image/png';
            }

            // Resize to UI-selected final dimensions (Small/Medium/Big).
            $imageData = $this->resizeGeneratedImage($imageData, $mime, $imageSize, $imageRatio);
            $detectedMime = $finfo->buffer($imageData);
            if (is_string($detectedMime) && $detectedMime !== '') {
                $mime = $detectedMime;
            }

            // Determine file extension from MIME type
            $extension = match($mime) {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'image/svg+xml' => 'svg',
                default => 'png'
            };

            // Use provided filename or generate one
            if (pathinfo($filename, PATHINFO_EXTENSION) === '') {
                $filename = pathinfo($filename, PATHINFO_FILENAME) . '.' . $extension;
            }

            // Store generated images in temp storage first.
            // They are moved to persistent storage only after assignToMessage() links them.
            $stored = $this->storageService->store(
                file: $imageData,
                filename: $filename,
                uuid: $uuid,
                category: $category,
                temp: true
            );

            if (!$stored) {
                Log::error("Failed to store base64 image");
                return null;
            }

            Log::info('[ATTACHMENT SERVICE] Stored base64 image', [
                'uuid' => $uuid,
                'filename' => $filename,
                'category' => $category,
                'temp' => true
            ]);

            // Create Attachment database entry (required for download route)
            $type = $this->convertToAttachmentType($mime);
            \App\Models\Attachment::create([
                'uuid' => $uuid,
                'name' => $filename,
                'category' => $category,
                'mime' => $mime,
                'type' => $type,
                'user_id' => \Illuminate\Support\Facades\Auth::id()
            ]);

            Log::info('[ATTACHMENT SERVICE] Created Attachment database entry', [
                'uuid' => $uuid,
                'filename' => $filename
            ]);

            // The address the picture is shown at - stable, unlike the signed storage url.
            $url = $this->viewUrl($uuid, $category);

            Log::info('[ATTACHMENT SERVICE] Generated URL for base64 image', [
                'uuid' => $uuid,
                'url' => $url
            ]);

            return [
                'uuid' => $uuid,
                'url' => $url,
                'mime' => $mime,
                'name' => $filename
            ];
        } catch (Exception $e) {
            Log::error("Error storing base64 image: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Resize generated image to configured target dimensions.
     * Falls back to original image if no resize backend is available.
     */
    private function resizeGeneratedImage(string $imageData, string $mime, string $imageSize, ?string $imageRatio = null): string
    {
        $sourceWidth = 0;
        $sourceHeight = 0;
        if (function_exists('getimagesizefromstring')) {
            $dimensions = @getimagesizefromstring($imageData);
            if (is_array($dimensions)) {
                $sourceWidth = (int)($dimensions[0] ?? 0);
                $sourceHeight = (int)($dimensions[1] ?? 0);
            }
        }

        $targetDimensions = $this->resolveImageGenerationDimension($imageSize, $imageRatio);
        $targetWidth = (int)($targetDimensions['width'] ?? 0);
        $targetHeight = (int)($targetDimensions['height'] ?? 0);

        // An image kept at its own resolution still takes the ratio the gallery
        // asked for: the long edge stays, the short one follows the ratio.
        if ($targetWidth <= 0 && $sourceWidth > 0 && $sourceHeight > 0) {
            $ratioDimensions = $this->reshapeToRatio(max($sourceWidth, $sourceHeight), $imageRatio);
            $targetWidth = (int)($ratioDimensions['width'] ?? 0);
            $targetHeight = (int)($ratioDimensions['height'] ?? 0);
        }

        if ($targetWidth <= 0 || $targetHeight <= 0) {
            return $imageData;
        }

        if ($sourceWidth === $targetWidth && $sourceHeight === $targetHeight) {
            return $imageData;
        }

        $resizedWithGd = $this->resizeImageWithGd($imageData, $mime, $targetWidth, $targetHeight);
        if ($resizedWithGd !== null) {
            return $resizedWithGd;
        }

        $resizedWithFfmpeg = $this->resizeImageWithFfmpeg($imageData, $mime, $targetWidth, $targetHeight);
        if ($resizedWithFfmpeg !== null) {
            return $resizedWithFfmpeg;
        }

        Log::warning('[ATTACHMENT SERVICE] Could not resize generated image, keeping original dimensions', [
            'image_size' => $imageSize,
            'image_ratio' => $imageRatio,
            'target' => $targetWidth . 'x' . $targetHeight,
            'mime' => $mime,
        ]);

        return $imageData;
    }

    /**
     * Resize using GD if extension is available.
     */
    private function resizeImageWithGd(string $imageData, string $mime, int $targetWidth, int $targetHeight): ?string
    {
        if (
            !function_exists('imagecreatefromstring') ||
            !function_exists('imagecreatetruecolor') ||
            !function_exists('imagecopyresampled')
        ) {
            return null;
        }

        $sourceImage = @imagecreatefromstring($imageData);
        if ($sourceImage === false) {
            return null;
        }

        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            imagedestroy($sourceImage);
            return null;
        }

        $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($targetImage === false) {
            imagedestroy($sourceImage);
            return null;
        }

        // Preserve transparency for image formats that support alpha.
        if (in_array($mime, ['image/png', 'image/webp', 'image/gif'], true)) {
            imagealphablending($targetImage, false);
            imagesavealpha($targetImage, true);
            $transparent = imagecolorallocatealpha($targetImage, 0, 0, 0, 127);
            imagefilledrectangle($targetImage, 0, 0, $targetWidth, $targetHeight, $transparent);
        }

        imagecopyresampled(
            $targetImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight
        );

        ob_start();
        $writeSuccess = match ($mime) {
            'image/jpeg', 'image/jpg' => imagejpeg($targetImage, null, 90),
            'image/gif' => imagegif($targetImage),
            'image/webp' => function_exists('imagewebp')
                ? imagewebp($targetImage, null, 90)
                : imagepng($targetImage, null, 6),
            default => imagepng($targetImage, null, 6),
        };
        $resizedImage = ob_get_clean();

        imagedestroy($sourceImage);
        imagedestroy($targetImage);

        if ($writeSuccess && is_string($resizedImage) && $resizedImage !== '') {
            return $resizedImage;
        }

        return null;
    }

    /**
     * Resize using ffmpeg as fallback (useful when GD is not installed).
     */
    private function resizeImageWithFfmpeg(string $imageData, string $mime, int $targetWidth, int $targetHeight): ?string
    {
        if (!function_exists('exec')) {
            return null;
        }

        $ffmpegBinary = $this->resolveFfmpegBinary();
        if ($ffmpegBinary === null) {
            return null;
        }

        $inputExt = match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'png',
        };

        $inputTmp = tempnam(sys_get_temp_dir(), 'hawki_img_in_');
        $outputTmp = tempnam(sys_get_temp_dir(), 'hawki_img_out_');
        if ($inputTmp === false || $outputTmp === false) {
            if ($inputTmp !== false && file_exists($inputTmp)) {
                @unlink($inputTmp);
            }
            if ($outputTmp !== false && file_exists($outputTmp)) {
                @unlink($outputTmp);
            }
            return null;
        }

        $inputPath = $inputTmp . '.' . $inputExt;
        $outputPath = $outputTmp . '.png';

        @rename($inputTmp, $inputPath);
        @unlink($outputTmp);

        try {
            if (file_put_contents($inputPath, $imageData) === false) {
                return null;
            }

            $filter = sprintf(
                'scale=%d:%d',
                $targetWidth,
                $targetHeight
            );

            $command = sprintf(
                '%s -hide_banner -loglevel error -y -i %s -vf %s -frames:v 1 %s 2>&1',
                escapeshellarg($ffmpegBinary),
                escapeshellarg($inputPath),
                escapeshellarg($filter),
                escapeshellarg($outputPath)
            );

            $cmdOutput = [];
            $exitCode = 1;
            exec($command, $cmdOutput, $exitCode);

            if ($exitCode !== 0 || !file_exists($outputPath)) {
                Log::warning('[ATTACHMENT SERVICE] ffmpeg resize failed', [
                    'exit_code' => $exitCode,
                    'output' => implode("\n", $cmdOutput),
                ]);
                return null;
            }

            $resized = file_get_contents($outputPath);
            if ($resized === false || $resized === '') {
                return null;
            }

            return $resized;
        } finally {
            if (file_exists($inputPath)) {
                @unlink($inputPath);
            }
            if (file_exists($outputPath)) {
                @unlink($outputPath);
            }
        }
    }

    private function resolveFfmpegBinary(): ?string
    {
        $output = [];
        $exitCode = 1;
        @exec('command -v ffmpeg 2>/dev/null', $output, $exitCode);

        if ($exitCode === 0 && !empty($output[0])) {
            return trim((string)$output[0]);
        }

        if (is_executable('/usr/bin/ffmpeg')) {
            return '/usr/bin/ffmpeg';
        }

        return null;
    }

    /**
     * The S/M/L preset sets the length of the longest edge, the aspect ratio the
     * shape. Without a ratio the presets keep the dimensions they always had.
     */
    private function resolveImageGenerationDimension(string $imageSize, ?string $imageRatio = null): array
    {
        $normalized = strtolower($imageSize);

        // Keep the image exactly as it came in. Used for sandbox plots, whose
        // aspect ratio is theirs and not one of the generation presets - the
        // 'default' arm below would square them - and for pictures from the
        // provider's image tool, whose resolution is the API's answer to the
        // prompt. A ratio is applied on top by the caller, from the source size.
        if ($normalized === 'original') {
            return ['width' => 0, 'height' => 0];
        }

        if ($imageRatio === null || preg_match('/^\d{1,2}:\d{1,2}$/', $imageRatio) !== 1) {
            return match ($normalized) {
                'small' => ['width' => 512, 'height' => 512],
                'medium' => ['width' => 1024, 'height' => 1024],
                'big' => ['width' => 1536, 'height' => 1024],
                default => ['width' => 1024, 'height' => 1024],
            };
        }

        $longestEdge = match ($normalized) {
            'small' => 512,
            'big' => 1536,
            default => 1024,
        };

        return $this->reshapeToRatio($longestEdge, $imageRatio)
            ?? $this->resolveImageGenerationDimension($imageSize);
    }

    /**
     * The dimensions of a 'w:h' ratio at the given long edge, or null when the
     * ratio is missing or malformed.
     */
    private function reshapeToRatio(int $longestEdge, ?string $imageRatio): ?array
    {
        if ($imageRatio === null || preg_match('/^\d{1,2}:\d{1,2}$/', $imageRatio) !== 1) {
            return null;
        }

        [$ratioWidth, $ratioHeight] = array_map('intval', explode(':', $imageRatio));
        if ($ratioWidth <= 0 || $ratioHeight <= 0) {
            return null;
        }

        return $ratioWidth >= $ratioHeight
            ? [
                'width' => $longestEdge,
                'height' => (int)round($longestEdge * $ratioHeight / $ratioWidth),
            ]
            : [
                'width' => (int)round($longestEdge * $ratioWidth / $ratioHeight),
                'height' => $longestEdge,
            ];
    }

}
