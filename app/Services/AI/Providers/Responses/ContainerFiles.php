<?php

declare(strict_types=1);

namespace App\Services\AI\Providers\Responses;

use App\Services\AI\Value\AiModel;
use App\Services\Chat\Attachment\AttachmentService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Files the native code interpreter wrote into its container.
 *
 * The response never carries their bytes. It carries `container_file_citation`
 * annotations - container id, file id, filename - on the text where the model
 * mentions the file, and the model writes that mention as a `sandbox:/mnt/data/…`
 * link nobody outside the container can open. The container lives for about
 * twenty idle minutes, so the file is fetched while the answer arrives and stored
 * in HAWKI's own filesystem, where it stays available with the message.
 *
 * One instance serves one response: a file the model mentions twice is fetched
 * once.
 */
class ContainerFiles
{
    /**
     * The largest file taken over. Bigger ones are left in the container and
     * logged - a data dump has no place in a chat.
     */
    public const MAX_BYTES = 25 * 1024 * 1024;

    /**
     * @var array<string, array<string, mixed>|null> file id => what was stored, or null when the fetch failed
     */
    private array $fetched = [];

    public function __construct(
        private readonly AttachmentService $attachments,
    ) {}

    /**
     * Whether an annotation names a container file at all.
     */
    public static function isCitation(array $annotation): bool
    {
        return ($annotation['type'] ?? '') === 'container_file_citation'
            && (string) ($annotation['container_id'] ?? '') !== ''
            && (string) ($annotation['file_id'] ?? '') !== '';
    }

    /**
     * Fetches the file an annotation names and stores it as an attachment.
     *
     * @return array{file_id: string, filename: string, uuid: string, url: string, mime: string, name: string, output_index: int|null, repeated: bool}|null
     */
    public function fetch(AiModel $model, array $annotation, ?int $outputIndex = null): ?array
    {
        if (! self::isCitation($annotation)) {
            return null;
        }

        $containerId = (string) $annotation['container_id'];
        $fileId = (string) $annotation['file_id'];
        $filename = basename(trim((string) ($annotation['filename'] ?? '')));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $filename = $fileId;
        }

        if (array_key_exists($fileId, $this->fetched)) {
            $stored = $this->fetched[$fileId];

            return $stored === null ? null : $stored + ['repeated' => true];
        }

        $stored = $this->download($model, $containerId, $fileId, $filename);

        if ($stored !== null) {
            $stored['file_id'] = $fileId;
            $stored['filename'] = $filename;
            $stored['output_index'] = $outputIndex;
        }

        $this->fetched[$fileId] = $stored;

        return $stored === null ? null : $stored + ['repeated' => false];
    }

    private function download(AiModel $model, string $containerId, string $fileId, string $filename): ?array
    {
        $config = $model->getProvider()->getConfig();
        $url = self::containersBaseUrl($config->getApiUrl())
            .'/containers/'.rawurlencode($containerId)
            .'/files/'.rawurlencode($fileId).'/content';

        try {
            $response = Http::withToken((string) $config->getApiKey())
                ->timeout(60)
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('[RESPONSES] Could not fetch a container file', [
                'file_id' => $fileId,
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('[RESPONSES] The container file endpoint refused the file', [
                'file_id' => $fileId,
                'filename' => $filename,
                'status' => $response->status(),
            ]);

            return null;
        }

        $bytes = $response->body();

        if ($bytes === '') {
            return null;
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            Log::warning('[RESPONSES] A container file is too large to keep', [
                'file_id' => $fileId,
                'filename' => $filename,
                'bytes' => strlen($bytes),
            ]);

            return null;
        }

        $mimeHint = $response->header('Content-Type');

        return $this->attachments->storeGeneratedFile(
            $bytes,
            $filename,
            'private',
            is_string($mimeHint) && $mimeHint !== '' ? $mimeHint : null
        );
    }

    /**
     * The API root the containers endpoint hangs off: the provider's responses
     * url without its last segment, so a gateway in front of OpenAI is kept.
     */
    public static function containersBaseUrl(string $apiUrl): string
    {
        $trimmed = rtrim(trim($apiUrl), '/');

        return preg_replace('~/responses$~', '', $trimmed) ?? $trimmed;
    }
}
