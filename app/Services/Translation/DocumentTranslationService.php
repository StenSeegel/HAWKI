<?php

namespace App\Services\Translation;

use App\Services\Translation\Exceptions\TranslationFailedException;
use App\Services\Translation\Providers\DeeplLibraryProvider;
use DeepL\DeepLException;
use DeepL\DocumentHandle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Service for translating documents via DeepL's native document translation API.
 *
 * Uses the async 3-step DeepL flow:
 * 1. uploadDocument() – uploads file, returns job ID immediately
 * 2. checkStatus() – polls DeepL for translation progress
 * 3. downloadResult() – downloads finished file when status is "done"
 */
class DocumentTranslationService
{
    private const STORAGE_DISK = 'local';

    private const STORAGE_PATH = 'translated_documents';

    private const CACHE_PREFIX = 'doc_translation_';

    private const CACHE_TTL_HOURS = 1;

    /**
     * Upload a document to DeepL and start translation.
     * Returns immediately with a job ID for polling.
     *
     * @param  UploadedFile  $file  The uploaded document
     * @param  string  $targetLang  Target language code
     * @param  string|null  $sourceLang  Source language code (null for auto-detect)
     * @return array{job_id: string, original_name: string, output_extension: string, target_lang: string}
     *
     * @throws TranslationFailedException
     */
    public function uploadDocument(UploadedFile $file, string $targetLang, ?string $sourceLang = null): array
    {
        Log::debug('[DocTranslation][Service] uploadDocument() called', [
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'target_lang' => $targetLang,
            'source_lang' => $sourceLang,
        ]);

        $translator = $this->getDeeplTranslator();

        // Fix for DeepL deprecation of 'en' as target language
        if (strtolower($targetLang) === 'en') {
            Log::debug('[DocTranslation][Service] Correcting "en" → "en-US"');
            $targetLang = 'en-US';
        }

        $originalName = $file->getClientOriginalName();
        $originalExtension = strtolower($file->getClientOriginalExtension());

        // .doc files are returned as .docx by DeepL
        $outputExtension = ($originalExtension === 'doc') ? 'docx' : $originalExtension;

        // Create job ID
        $jobId = Str::uuid()->toString();

        // Ensure storage directory exists
        Storage::disk(self::STORAGE_DISK)->makeDirectory(self::STORAGE_PATH);

        // DeepL requires a file extension to determine the document type.
        // PHP temp uploads have no extension, so copy to a properly named file.
        $inputFilename = $jobId.'_input.'.$originalExtension;
        $inputPath = Storage::disk(self::STORAGE_DISK)->path(self::STORAGE_PATH.'/'.$inputFilename);
        copy($file->getRealPath(), $inputPath);

        Log::debug('[DocTranslation][Service] Input file prepared', [
            'job_id' => $jobId,
            'input_path' => $inputPath,
            'input_exists' => file_exists($inputPath),
        ]);

        try {
            $handle = $translator->uploadDocument(
                $inputPath,
                $sourceLang,
                strtoupper($targetLang),
            );

            Log::info('[DocTranslation][Service] Document uploaded to DeepL', [
                'job_id' => $jobId,
                'deepl_document_id' => $handle->documentId,
                'original_name' => $originalName,
            ]);

            // Store job metadata in cache
            Cache::put(self::CACHE_PREFIX.$jobId, [
                'deepl_document_id' => $handle->documentId,
                'deepl_document_key' => $handle->documentKey,
                'original_name' => pathinfo($originalName, PATHINFO_FILENAME),
                'original_extension' => $originalExtension,
                'output_extension' => $outputExtension,
                'target_lang' => strtolower($targetLang),
                'input_file_path' => $inputPath,
                'status' => 'queued',
            ], now()->addHours(self::CACHE_TTL_HOURS));

            return [
                'job_id' => $jobId,
                'original_name' => pathinfo($originalName, PATHINFO_FILENAME),
                'output_extension' => $outputExtension,
                'target_lang' => strtolower($targetLang),
            ];

        } catch (DeepLException $e) {
            @unlink($inputPath);

            Log::error('[DocTranslation][Service] DeepL upload failed', [
                'error' => $e->getMessage(),
                'job_id' => $jobId,
            ]);

            throw new TranslationFailedException('Document upload failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Check the translation status of a document job.
     * When status is "done", automatically downloads the result from DeepL.
     *
     * @return array{status: string, seconds_remaining: int|null, download_id: string|null, error_message: string|null}
     *
     * @throws TranslationFailedException
     */
    public function checkStatus(string $jobId): array
    {
        $jobData = Cache::get(self::CACHE_PREFIX.$jobId);

        if (! $jobData) {
            throw new TranslationFailedException('Translation job not found or expired.');
        }

        $translator = $this->getDeeplTranslator();

        $handle = new DocumentHandle(
            $jobData['deepl_document_id'],
            $jobData['deepl_document_key'],
        );

        try {
            $status = $translator->getDocumentStatus($handle);

            Log::debug('[DocTranslation][Service] Status polled', [
                'job_id' => $jobId,
                'status' => $status->status,
                'seconds_remaining' => $status->secondsRemaining,
                'billed_characters' => $status->billedCharacters,
            ]);

            $result = [
                'status' => $status->status,
                'seconds_remaining' => $status->secondsRemaining,
                'download_id' => null,
                'error_message' => null,
            ];

            // If done, download the translated file from DeepL
            if ($status->done()) {
                $downloadId = $this->downloadResult($jobId, $jobData, $handle, $translator);
                $result['download_id'] = $downloadId;

                // Update cache with download_id
                $jobData['status'] = 'done';
                $jobData['download_id'] = $downloadId;
                Cache::put(self::CACHE_PREFIX.$jobId, $jobData, now()->addHours(self::CACHE_TTL_HOURS));
            }

            // If error, capture the message
            if (! $status->ok()) {
                $result['error_message'] = $status->errorMessage;

                // Update cache status
                $jobData['status'] = 'error';
                Cache::put(self::CACHE_PREFIX.$jobId, $jobData, now()->addHours(self::CACHE_TTL_HOURS));

                // Clean up input file
                $this->cleanupInputFile($jobData);
            }

            return $result;

        } catch (DeepLException $e) {
            Log::error('[DocTranslation][Service] Status check failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            throw new TranslationFailedException('Status check failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the path to a translated document by its download ID.
     *
     * @return string|null Full path to the file, or null if not found
     */
    public function getTranslatedFilePath(string $downloadId): ?string
    {
        $files = Storage::disk(self::STORAGE_DISK)->files(self::STORAGE_PATH);

        foreach ($files as $file) {
            $basename = basename($file);
            // Match the download ID but exclude input files
            if (str_starts_with($basename, $downloadId) && ! str_contains($basename, '_input.')) {
                return Storage::disk(self::STORAGE_DISK)->path($file);
            }
        }

        return null;
    }

    /**
     * Get the cached job metadata for building download filenames.
     *
     * @return array|null The job metadata or null if not found
     */
    public function getJobData(string $jobId): ?array
    {
        return Cache::get(self::CACHE_PREFIX.$jobId);
    }

    /**
     * Delete a translated document after download.
     */
    public function deleteTranslatedFile(string $downloadId): void
    {
        $files = Storage::disk(self::STORAGE_DISK)->files(self::STORAGE_PATH);

        foreach ($files as $file) {
            if (str_starts_with(basename($file), $downloadId)) {
                Storage::disk(self::STORAGE_DISK)->delete($file);
                break;
            }
        }
    }

    /**
     * Download the translated document from DeepL and store it locally.
     *
     * @return string The download ID for the stored file
     *
     * @throws DeepLException
     */
    private function downloadResult(string $jobId, array $jobData, DocumentHandle $handle, \DeepL\Translator $translator): string
    {
        $downloadId = Str::uuid()->toString();
        $outputFilename = $downloadId.'.'.$jobData['output_extension'];
        $outputPath = Storage::disk(self::STORAGE_DISK)->path(self::STORAGE_PATH.'/'.$outputFilename);

        Log::info('[DocTranslation][Service] Downloading translated file from DeepL', [
            'job_id' => $jobId,
            'download_id' => $downloadId,
            'output_path' => $outputPath,
        ]);

        $translator->downloadDocument($handle, $outputPath);

        // Clean up input file
        $this->cleanupInputFile($jobData);

        Log::info('[DocTranslation][Service] Download complete', [
            'job_id' => $jobId,
            'download_id' => $downloadId,
            'file_exists' => file_exists($outputPath),
            'file_size' => file_exists($outputPath) ? filesize($outputPath) : null,
        ]);

        return $downloadId;
    }

    /**
     * Get the DeepL Translator instance.
     *
     * @throws TranslationFailedException
     */
    private function getDeeplTranslator(): \DeepL\Translator
    {
        $provider = TranslationFactory::create('deepl');

        if (! $provider instanceof DeeplLibraryProvider) {
            throw new TranslationFailedException('Document translation requires the DeepL provider.');
        }

        $translator = $provider->getTranslator();

        if (! $translator) {
            throw new TranslationFailedException('DeepL translator is not available.');
        }

        return $translator;
    }

    /**
     * Clean up the temporary input file copy.
     */
    private function cleanupInputFile(array $jobData): void
    {
        if (isset($jobData['input_file_path']) && file_exists($jobData['input_file_path'])) {
            @unlink($jobData['input_file_path']);
        }
    }
}
