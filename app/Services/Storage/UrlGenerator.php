<?php

namespace App\Services\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\URL;

class UrlGenerator
{
    private string $path;
    private string $uuid;
    private string $category;
    private string $visibility;


    public function __construct(
        protected array $config,
        protected Filesystem $disk
    )
    {
    }

    public function generate(string $path, string $uuid, string $category): string
    {
        $this->uuid = $uuid;
        $this->path = $path;
        $this->category = $category;
        $this->visibility = $this->config['visibility'];


        // A private file is always served through HAWKI's signed route, which
        // checks that it belongs to the user before streaming it from the
        // disk. A presigned S3 link would point the browser straight at the
        // object store - one it may not reach from outside, and one that
        // hands the file to anyone holding the link without that check.
        if ($this->visibility !== 'public') {
            return $this->generateProxyUrl();
        }

        return match ($this->config['driver']) {
            's3', 'webdav' => $this->generateTemporaryUrl(),
            'local' => $this->generateLocalUrl(),
            // No direct URL, always proxy through Laravel
            'sftp' => $this->generateProxyUrl(),
            default => $this->generateDefaultUrl(),
        };
    }

    private function generateLocalUrl(): string{
        // Local "public" disk can return direct URLs
        if ($this->disk->url($this->path)) {
            return $this->disk->url($this->path);
        }

        return $this->generateProxyUrl();
    }

    private function generateTemporaryUrl(): string{
        // Prefer native temporaryUrl if supported
        if (method_exists($this->disk, 'temporaryUrl')) {
            return $this->disk->temporaryUrl($this->path, now()->addHours(24));
        }
        return $this->generateDefaultUrl();
    }

    private function generateDefaultUrl(): string{
        // Fallback: try native url() if available
        if (method_exists($this->disk, 'url')) {
            return $this->disk->url($this->path);
        }

        return $this->generateProxyUrl();
    }

    /**
     * The signed download route, which streams the file through HAWKI.
     */
    private function generateProxyUrl(): string{
        return URL::temporarySignedRoute(
            "files.download.{$this->category}",
            now()->addHours(24),
            [
                'uuid'     => $this->uuid,
                'category' => $this->category,
                'path'     => base64_encode($this->path),
                'disk'     => $this->disk,
            ]
        );
    }
}
