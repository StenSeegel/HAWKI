<?php

namespace App\Services\PageRender;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use ZipArchive;

/**
 * Client for the page-render sidecar (_docker/page-render): one PNG per slide
 * of a deck, so a vision model sees the layout and not just the icons the
 * converter cut out of it.
 *
 * Everything here degrades to "no pages": a sidecar that is down, slow or
 * refuses the file leaves the upload exactly as it was before this existed.
 */
class PageRenderer
{
    /** Configured and switched on. Says nothing about whether the sidecar is up. */
    public static function isActive(): bool
    {
        return (bool) config('page_render.enabled', true)
            && trim((string) config('page_render.api_url', '')) !== '';
    }

    /** A file whose pages are worth rendering: a slide format, by extension. */
    public function shouldRender(string $filename): bool
    {
        if (! self::isActive()) {
            return false;
        }

        $extension = strtolower(pathinfo(trim($filename), PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, (array) config('page_render.formats', []), true);
    }

    /**
     * Renders the document's pages.
     *
     * @param  UploadedFile|string  $file  the upload, or its raw bytes
     * @param  string  $filename  the real name - the sidecar picks its import filter by extension
     * @return array<string,string> relative path => PNG bytes, e.g. "pages/page_001.png"; [] on any failure
     */
    public function render(UploadedFile|string $file, string $filename): array
    {
        if (! self::isActive()) {
            return [];
        }

        $tempPath = null;
        try {
            if ($file instanceof UploadedFile) {
                $resource = fopen($file->getRealPath(), 'r');
            } else {
                $tempPath = tempnam(sys_get_temp_dir(), 'render_');
                file_put_contents($tempPath, $file);
                $resource = fopen($tempPath, 'r');
            }

            // Options travel in the query string: with a multipart body Laravel
            // would otherwise send them as form fields, which the sidecar does
            // not read.
            $query = http_build_query([
                'max_pages' => max(1, (int) config('page_render.max_pages', 20)),
                'dpi' => max(36, (int) config('page_render.dpi', 110)),
            ]);

            $response = Http::withHeaders($this->headers())
                ->connectTimeout(5)
                ->timeout((int) config('page_render.timeout', 120))
                ->attach('file', $resource, basename($filename))
                ->post($this->url('render').'?'.$query);
            fclose($resource);

            if (! $response->successful()) {
                Log::warning('[PageRenderer] Sidecar refused '.$filename.': HTTP '.$response->status().' '.substr($response->body(), 0, 200));

                return [];
            }

            return $this->unzipPages($response->body());
        } catch (\Throwable $e) {
            Log::warning('[PageRenderer] Could not render '.$filename.': '.$e->getMessage());

            return [];
        } finally {
            if ($tempPath !== null) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * The sidecar's own format list, dotted extensions. Empty when unreachable.
     *
     * @return string[]
     */
    public function supportedFormats(): array
    {
        if (! self::isActive()) {
            return [];
        }

        try {
            $response = Http::withHeaders($this->headers())->connectTimeout(3)->timeout(5)->get($this->url(''));
            $formats = $response->successful() ? $response->json('supported_formats') : null;

            return is_array($formats) ? $formats : [];
        } catch (\Throwable $e) {
            Log::warning('[PageRenderer] Format list request failed: '.$e->getMessage());

            return [];
        }
    }

    /** @return array<string,string> "pages/page_001.png" => bytes, in page order */
    private function unzipPages(string $zipBytes): array
    {
        $tmpZip = tempnam(sys_get_temp_dir(), 'pages_');
        file_put_contents($tmpZip, $zipBytes);

        $zip = new ZipArchive();
        try {
            if ($zip->open($tmpZip) !== true) {
                throw new RuntimeException('the sidecar did not answer with a zip');
            }

            $pages = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (preg_match('#^pages/page_\d+\.png$#', $name) !== 1) {
                    continue;
                }
                $bytes = $zip->getFromIndex($i);
                if ($bytes !== false && $bytes !== '') {
                    $pages[$name] = $bytes;
                }
            }
            $zip->close();

            ksort($pages, SORT_NATURAL);

            return $pages;
        } finally {
            @unlink($tmpZip);
        }
    }

    private function headers(): array
    {
        $headers = ['Accept' => 'application/json'];
        $key = trim((string) config('page_render.api_key', ''));
        if ($key !== '') {
            $headers['Authorization'] = 'Bearer '.$key;
        }

        return $headers;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('page_render.api_url'), '/').'/'.ltrim($path, '/');
    }
}
