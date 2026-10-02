<?php

namespace App\Services\FileConverter\Handlers;

use App\Services\FileConverter\Handlers\Interfaces\FileConverterInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Finder\SplFileInfo;
use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Exception;

class HawkiDocConverter implements FileConverterInterface
{
    private array $config;
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @throws ConnectionException
     * @throws Exception
     */
    public function convert(UploadedFile|SplFileInfo|string $file, ?string $filename = null): array
    {
        // Every temporary file and directory goes again when the conversion
        // ends, however it ends. They used to stay: per document an upl_ copy,
        // an empty unzipped_ placeholder and the whole pdf_extract_ tree -
        // about 4 GB in the app container's /tmp on ki-chat after two weeks.
        $tempFilePath = null;
        $extractDir = null;
        $resource = null;

        try {
            if ($file instanceof UploadedFile) {
                $resource = fopen($file->getRealPath(), 'r');
                $filename = $file->getClientOriginalName();
            } elseif ($file instanceof \SplFileInfo) {
                $resource = fopen($file->getPathname(), 'r');
                $filename = $file->getFilename();
            } elseif (is_string($file)) {
                // Assume string contains file contents (as returned from Storage::get())
                // Write to a temp file
                $tempFilePath = tempnam(sys_get_temp_dir(), 'upl_');
                file_put_contents($tempFilePath, $file);
                $resource = fopen($tempFilePath, 'r');
                // The converter validates by file name: calling every payload
                // file.pdf made re-extraction of a .docx fail with a 400.
                $filename = $filename !== null && trim($filename) !== '' ? $filename : 'file.pdf';
            } else {
                throw new \InvalidArgumentException("Invalid file input. Expected UploadedFile or SplFileInfo.");
            }

            // Conversion is synchronous and CPU bound (OCR): a figure-heavy PDF takes
            // ~15 s on a laptop and ~30 s on a 2-core host, which is exactly the
            // default Guzzle timeout. Give it room, the converter itself allows 60 min.
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->config['api_key'],
                'Accept'        => 'application/json',
            ])
            ->connectTimeout(10)
            ->timeout((int) ($this->config['timeout'] ?? 300))
            ->attach('file', $resource, $filename)
            ->post($this->config['api_url']);

            if (!$response->successful()) {
                \Log::error('PDF extraction failed: ' . $response->body());
                throw new Exception('PDF extraction failed: ' . $response->body());
            }

            // Unzip files from response
            $extractDir = sys_get_temp_dir() . '/pdf_extract_' . uniqid();
            if (!mkdir($extractDir, 0700, true) && !is_dir($extractDir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $extractDir));
            }

            $this->unzipContent($response->body(), $extractDir);

            // Read all extracted files and return them as [relative_path => file_content]
            $files = [];
            $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extractDir));
            foreach ($rii as $fileinfo) {
                if ($fileinfo->isFile()) {
                    $relativePath = substr($fileinfo->getPathname(), strlen($extractDir) + 1);
                    $files[$relativePath] = file_get_contents($fileinfo->getPathname());
                }
            }

            return $files;
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
            if ($tempFilePath !== null && $tempFilePath !== false) {
                @unlink($tempFilePath);
            }
            if ($extractDir !== null) {
                File::deleteDirectory($extractDir);
            }
        }
    }

    private function unzipContent($zipContent, $extractToDirectory): bool
    {
        // tempnam() creates the file it names, so the zip goes into that very
        // file - writing it to "<name>.zip" left the placeholder behind.
        $tmpZip = tempnam(sys_get_temp_dir(), 'unzipped_');

        try {
            file_put_contents($tmpZip, $zipContent);

            $zip = new ZipArchive();
            if ($zip->open($tmpZip) !== true) {
                throw new Exception("Failed to open ZIP file.");
            }
            $zip->extractTo($extractToDirectory);
            $zip->close();

            return true;
        } finally {
            @unlink($tmpZip);
        }
    }

    /**
     * The converter's own format list: GET on the API root, with the same key
     * that /extract uses, answers {"version": ..., "supported_formats": [...]}.
     *
     * @return string[]
     */
    public function supportedFormats(): array
    {
        $url = $this->rootUrl();
        if ($url === null) {
            return [];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->config['api_key'],
            'Accept'        => 'application/json',
        ])
        ->connectTimeout(3)
        ->timeout(5)
        ->get($url);

        if (!$response->successful()) {
            \Log::warning('[HawkiDocConverter] Format list request failed: HTTP ' . $response->status());
            return [];
        }

        $formats = $response->json('supported_formats');

        return is_array($formats) ? $formats : [];
    }

    /**
     * The API root of the converter: its /extract endpoint without the endpoint.
     */
    private function rootUrl(): ?string
    {
        $apiUrl = trim((string) ($this->config['api_url'] ?? ''));
        if ($apiUrl === '') {
            return null;
        }

        $root = preg_replace('#/extract/?$#', '', $apiUrl);

        return rtrim((string) $root, '/') . '/';
    }
}
