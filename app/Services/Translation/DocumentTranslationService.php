<?php

namespace App\Services\Translation;

use App\Models\TranslateDocument;
use App\Services\Translation\Exceptions\TranslationFailedException;
use App\Services\Translation\Providers\DeeplLibraryProvider;
use DeepL\DeepLException;
use DeepL\DocumentHandle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
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

    public function __construct(
        private TranslationUsageLogger $usageLogger,
        private TranslationService $translationService
    ) {}

    private const STORAGE_PATH = 'translated_documents';

    private const CACHE_PREFIX = 'doc_translation_';

    private const CACHE_TTL_HOURS = 1;

    /** How long translated files are kept before automatic cleanup. */
    private const FILE_TTL_HOURS = 24;

    /**
     * Upload a document to DeepL and start translation.
     * Returns immediately with a job ID for polling.
     *
     * @param  UploadedFile  $file  The uploaded document
     * @param  string  $targetLang  Target language code
     * @param  string|null  $sourceLang  Source language code (null for auto-detect)
     * @param  int|null  $glossaryId  Local glossary ID
     * @param  string|null  $formality  Formality preference
     * @return array{job_id: string, original_name: string, output_extension: string, target_lang: string}
     *
     * @throws TranslationFailedException
     */
    public function uploadDocument(UploadedFile $file, string $targetLang, ?string $sourceLang = null, int|array|null $glossaryId = null, ?string $formality = null): array
    {
        $showDebug = $this->translationService->shouldShowDebug();

        if ($showDebug) {
            Log::debug('[Document Translation] uploadDocument() called', [
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'target_lang' => $targetLang,
                'source_lang' => $sourceLang,
                'glossary_id' => $glossaryId,
                'formality' => $formality,
            ]);
        }

        $translator = $this->getDeeplTranslator();
        $provider = $this->getDeeplProvider();

        // Fix for DeepL deprecation of 'en' as target language
        if (strtolower($targetLang) === 'en') {
            if ($showDebug) {
                Log::debug('[Document Translation] Correcting "en" → "en-US"');
            }
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

        if ($showDebug) {
            Log::debug('[Document Translation] Input file prepared', [
                'job_id' => $jobId,
                'input_path' => $inputPath,
                'input_exists' => file_exists($inputPath),
            ]);
        }

        try {
            $options = [];
            if ($formality && $formality !== 'default') {
                $options['formality'] = $formality;
            }

            if ($glossaryId && $sourceLang && method_exists($provider, 'createDeepLGlossary')) {
                // We need to use the provider's logic to create a temp glossary
                // Reflections or making it public might be needed, but for now let's see.
                // Actually, I can just replicate it here or call it if I make it public.
                // Let's assume we want to call it. I'll make it public in the provider.
                $tempGlossaryId = $provider->createDeepLGlossary($glossaryId, $sourceLang, $targetLang);
                if ($tempGlossaryId) {
                    $options['glossary'] = $tempGlossaryId;
                }
            }

            $handle = $translator->uploadDocument(
                $inputPath,
                $sourceLang,
                strtoupper($targetLang),
                $options
            );

            if ($showDebug) {
                Log::debug('[Document Translation] Document uploaded to DeepL', [
                    'job_id' => $jobId,
                    'deepl_document_id' => $handle->documentId,
                    'original_name' => $originalName,
                ]);
            }

            $originalBaseName = pathinfo($originalName, PATHINFO_FILENAME);
            $normalizedTargetLang = strtolower($targetLang);

            // Store job metadata in cache for fast polling
            Cache::put(self::CACHE_PREFIX.$jobId, [
                'deepl_document_id' => $handle->documentId,
                'deepl_document_key' => $handle->documentKey,
                'original_name' => $originalBaseName,
                'original_extension' => $originalExtension,
                'output_extension' => $outputExtension,
                'target_lang' => $normalizedTargetLang,
                'input_file_path' => $inputPath,
                'status' => 'queued',
                'temp_glossary_id' => $options['glossary'] ?? null,
            ], now()->addHours(self::CACHE_TTL_HOURS));

            // Persist record to DB immediately so files can always be tracked and cleaned up
            TranslateDocument::create([
                'job_id' => $jobId,
                'user_id' => Auth::id(),
                'original_name' => $originalBaseName,
                'output_extension' => $outputExtension,
                'target_lang' => $normalizedTargetLang,
                'source_lang' => $sourceLang ? strtolower($sourceLang) : null,
                'download_id' => null,
                'file_path' => '',
                'status' => 'pending',
                'expires_at' => now()->addHours(self::FILE_TTL_HOURS),
            ]);

            return [
                'job_id' => $jobId,
                'original_name' => $originalBaseName,
                'output_extension' => $outputExtension,
                'target_lang' => $normalizedTargetLang,
            ];

        } catch (DeepLException $e) {
            @unlink($inputPath);

            if ($this->translationService->shouldShowDebug()) {
                Log::error('[Document Translation] DeepL upload failed', [
                    'error' => $e->getMessage(),
                    'job_id' => $jobId,
                ]);
            }

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

            if ($this->translationService->shouldShowDebug()) {
                Log::debug('[Document Translation] Status polled', [
                    'job_id' => $jobId,
                    'status' => $status->status,
                    'seconds_remaining' => $status->secondsRemaining,
                    'billed_characters' => $status->billedCharacters,
                ]);
            }

            $result = [
                'status' => $status->status,
                'seconds_remaining' => $status->secondsRemaining,
                'download_id' => null,
                'error_message' => null,
            ];

            // If done, download the translated file from DeepL
            if ($status->done()) {
                // Guard against double-download race condition:
                // re-check cache in case another request already downloaded the file
                $freshJobData = Cache::get(self::CACHE_PREFIX.$jobId);
                if (! empty($freshJobData['download_id'])) {
                    $result['download_id'] = $freshJobData['download_id'];
                    $result['status'] = 'done';

                    return $result;
                }

                $downloadId = $this->downloadResult($jobId, $jobData, $handle, $translator);
                $result['download_id'] = $downloadId;

                // Log usage once
                if (empty($jobData['usage_logged']) && $status->billedCharacters !== null) {
                    $this->usageLogger->logDocumentTranslation(
                        providerName: 'deepl',
                        model: 'deepl-document',
                        billedChars: $status->billedCharacters
                    );
                    $jobData['usage_logged'] = true;
                }

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
            if ($this->translationService->shouldShowDebug()) {
                Log::error('[Document Translation] Status check failed', [
                    'job_id' => $jobId,
                    'error' => $e->getMessage(),
                ]);
            }

            throw new TranslationFailedException('Status check failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the absolute path to a translated document by its download ID.
     * file_path is stored relative to the local storage disk.
     *
     * @return string|null Absolute path to the file, or null if not found
     */
    public function getTranslatedFilePath(string $downloadId): ?string
    {
        $record = TranslateDocument::where('download_id', $downloadId)->first();

        if (! $record || empty($record->file_path)) {
            return null;
        }

        return Storage::disk(self::STORAGE_DISK)->path($record->file_path);
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
     * Delete a translated document file and mark the DB record as deleted.
     */
    public function deleteTranslatedFile(string $downloadId): void
    {
        $record = TranslateDocument::where('download_id', $downloadId)->first();

        if ($record && ! empty($record->file_path)) {
            Storage::disk(self::STORAGE_DISK)->delete($record->file_path);
        }

        if ($record) {
            $record->update(['status' => 'deleted']);
        }
    }

    /**
     * Download the translated document from DeepL and store it locally.
     * Also updates the DB record to status=done.
     *
     * @return string The download ID for the stored file
     *
     * @throws DeepLException
     */
    private function downloadResult(string $jobId, array $jobData, DocumentHandle $handle, \DeepL\Translator $translator): string
    {
        $downloadId = Str::uuid()->toString();
        $outputFilename = $downloadId.'.'.$jobData['output_extension'];

        // Relative path stored in DB — portable across environments
        $relativePath = self::STORAGE_PATH.'/'.$outputFilename;
        $absolutePath = Storage::disk(self::STORAGE_DISK)->path($relativePath);

        if ($this->translationService->shouldShowDebug()) {
            Log::debug('[Document Translation] Downloading translated file from DeepL', [
                'job_id' => $jobId,
                'download_id' => $downloadId,
                'relative_path' => $relativePath,
            ]);
        }

        $translator->downloadDocument($handle, $absolutePath);

        // Clean up input file
        $this->cleanupInputFile($jobData);

        $fileSize = file_exists($absolutePath) ? filesize($absolutePath) : null;

        if ($this->translationService->shouldShowDebug()) {
            Log::debug('[Document Translation] Download complete', [
                'job_id' => $jobId,
                'download_id' => $downloadId,
                'file_exists' => file_exists($absolutePath),
                'file_size' => $fileSize,
            ]);
        }

        // Update DB with relative path — resolved at runtime via Storage::disk
        TranslateDocument::where('job_id', $jobId)->update([
            'download_id' => $downloadId,
            'file_path' => $relativePath,
            'file_size' => $fileSize,
            'status' => 'done',
            'translated_at' => now(),
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

    /**
     * Get the DeepL Provider instance.
     *
     * @throws TranslationFailedException
     */
    private function getDeeplProvider(): DeeplLibraryProvider
    {
        $provider = \App\Services\Translation\TranslationFactory::create('deepl');

        if (! $provider instanceof DeeplLibraryProvider) {
            throw new TranslationFailedException('Document translation requires the DeepL provider.');
        }

        return $provider;
    }
}
