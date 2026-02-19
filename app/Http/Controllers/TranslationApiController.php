<?php

namespace App\Http\Controllers;

use App\Http\Requests\TranslateDocumentRequest;
use App\Jobs\ProcessDocumentTranslation;
use App\Models\TranslateDocument;
use App\Services\Translation\DocumentTranslationService;
use App\Services\Translation\Exceptions\InvalidLanguageException;
use App\Services\Translation\Exceptions\QuotaExceededException;
use App\Services\Translation\Exceptions\TranslationFailedException;
use App\Services\Translation\TextImprovementService;
use App\Services\Translation\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class TranslationApiController extends Controller
{
    public function __construct(
        private TranslationService $translationService,
        private TextImprovementService $textImprovementService
    ) {}

    /**
     * Translate text using DeepL API
     */
    public function translate(Request $request): JsonResponse
    {
        // Validate incoming request
        $validated = $request->validate([
            'text' => 'required|string|max:50000',
            'source_lang' => 'nullable|string|max:10',
            'target_lang' => 'required|string|max:10',
            'glossary_id' => 'nullable|integer|exists:translate_glossaries,id',
            'model' => 'nullable|string|max:255', // Add model validation
        ]);

        try {
            // Call translation service
            $result = $this->translationService->translate(
                text: $validated['text'],
                sourceLang: $validated['source_lang'] ?? null,
                targetLang: $validated['target_lang'],
                glossaryId: $validated['glossary_id'] ?? null,
                model: $validated['model'] ?? null // Pass model
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'text' => $result['text'],
                    'detected_source_language' => $result['detected_source_language'],
                ],
            ]);

        } catch (InvalidLanguageException $e) {
            Log::warning('DeepL translation failed: Invalid language', [
                'error' => $e->getMessage(),
                'source_lang' => $validated['source_lang'] ?? null,
                'target_lang' => $validated['target_lang'],
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Ungültige Sprache ausgewählt. Bitte wählen Sie eine unterstützte Sprache.',
                'message' => $e->getMessage(),
            ], 400);

        } catch (QuotaExceededException $e) {
            Log::error('DeepL translation failed: Quota exceeded', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Übersetzungslimit erreicht. Bitte versuchen Sie es später erneut.',
                'message' => $e->getMessage(),
            ], 429);

        } catch (TranslationFailedException $e) {
            Log::error('DeepL translation failed', [
                'error' => $e->getMessage(),
                'source_lang' => $validated['source_lang'] ?? null,
                'target_lang' => $validated['target_lang'],
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Übersetzung fehlgeschlagen. Bitte versuchen Sie es erneut.',
                'message' => $e->getMessage(),
            ], 500);

        } catch (\Exception $e) {
            Log::error('Unexpected error during translation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Ein unerwarteter Fehler ist aufgetreten.',
                'message' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get available AI models for text improvement
     */
    public function getModels(): JsonResponse
    {
        try {
            $models = $this->translationService->getAvailableModels();

            return response()->json([
                'success' => true,
                'data' => [
                    'models' => $models,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch available models', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Modelle konnten nicht geladen werden.',
            ], 500);
        }
    }

    /**
     * Improve text using AI
     */
    public function write(Request $request): JsonResponse
    {
        // Validate incoming request
        $validated = $request->validate([
            'text' => 'required|string|max:50000',
            'target_lang' => 'nullable|string|max:10',
            'model' => 'nullable|string|max:100',
            'style' => 'nullable|string|max:50',
        ]);

        try {
            // Check if DeepL Write is selected
            $modelId = $validated['model'] ?? null;

            // Check if DeepL Write is selected or if explicit "DeepL API Pro" was chosen for improvement
            // Note: DeepL API (library provider) handles 'write' method if implemented.
            // Currently DeeplLibraryProvider doesn't implement 'write' but the old 'DeeplTranslationProvider' did via 'write()' call on TranslationService.
            // Wait, TranslationService checks `method_exists($provider, 'write')`.

            if ($modelId === 'deepl-write' || $modelId === 'deepl') {
                // Use DeepL Write API (or standard DeepL improvement if applicable)
                $result = $this->translationService->write(
                    text: $validated['text'],
                    targetLang: $validated['target_lang'] ?? null
                );
            } else {
                // Use AI models (GWDG, Ollama, OpenAI, etc.)
                $result = $this->textImprovementService->improveText(
                    text: $validated['text'],
                    targetLang: $validated['target_lang'] ?? null,
                    modelId: $modelId,
                    style: $validated['style'] ?? null
                );
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'text' => $result['text'],
                ],
            ]);

        } catch (InvalidLanguageException $e) {
            Log::warning('Text improvement failed: Invalid language', [
                'error' => $e->getMessage(),
                'target_lang' => $validated['target_lang'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Ungültige Sprache ausgewählt. Bitte wählen Sie eine unterstützte Sprache.',
                'message' => $e->getMessage(),
            ], 400);

        } catch (QuotaExceededException $e) {
            Log::error('Text improvement failed: Quota exceeded', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Verbesserungslimit erreicht. Bitte versuchen Sie es später erneut.',
                'message' => $e->getMessage(),
            ], 429);

        } catch (TranslationFailedException $e) {
            Log::error('Text improvement failed', [
                'error' => $e->getMessage(),
                'target_lang' => $validated['target_lang'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Textverbesserung fehlgeschlagen. Bitte versuchen Sie es erneut.',
                'message' => $e->getMessage(),
            ], 500);

        } catch (\Exception $e) {
            Log::error('Unexpected error during text improvement', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Ein unerwarteter Fehler ist aufgetreten.',
                'message' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Upload a document to DeepL and start translation (non-blocking).
     * Returns a job ID for status polling.
     */
    public function translateDocument(TranslateDocumentRequest $request, DocumentTranslationService $documentService): JsonResponse
    {
        $uploadedFile = $request->file('file');

        Log::debug('[DocTranslation] Upload request received', [
            'file_name' => $uploadedFile?->getClientOriginalName(),
            'file_size' => $uploadedFile?->getSize(),
            'target_lang' => $request->validated('target_lang'),
            'source_lang' => $request->validated('source_lang'),
        ]);

        try {
            $result = $documentService->uploadDocument(
                file: $uploadedFile,
                targetLang: $request->validated('target_lang'),
                sourceLang: $request->validated('source_lang'),
            );

            Log::info('[DocTranslation] Upload successful', $result);

            // Dispatch background job to poll DeepL and download result
            ProcessDocumentTranslation::dispatch($result['job_id'])
                ->delay(now()->addSeconds(3));

            // Store pending job in session so it survives page reloads
            $pendingJobs = session('pending_doc_translations', []);
            $pendingJobs[] = [
                'job_id' => $result['job_id'],
                'original_name' => $result['original_name'],
                'output_extension' => $result['output_extension'],
                'target_lang' => $result['target_lang'],
                'started_at' => now()->toIso8601String(),
            ];
            session(['pending_doc_translations' => $pendingJobs]);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (TranslationFailedException $e) {
            Log::error('[DocTranslation] Upload failed', [
                'error' => $e->getMessage(),
                'previous' => $e->getPrevious()?->getMessage(),
            ]);

            $errorCode = null;
            if (str_contains($e->getMessage(), 'Source and target language are equal')) {
                $errorCode = 'same_language';
            }

            return response()->json([
                'success' => false,
                'error' => 'Dokumentübersetzung fehlgeschlagen. Bitte versuchen Sie es erneut.',
                'error_code' => $errorCode,
                'message' => $e->getMessage(),
            ], 500);

        } catch (\Exception $e) {
            Log::error('[DocTranslation] Unexpected exception during upload', [
                'exception_class' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Ein unerwarteter Fehler ist aufgetreten.',
                'message' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Poll the translation status of a document job.
     */
    public function documentStatus(string $jobId, DocumentTranslationService $documentService): JsonResponse
    {
        Log::debug('[DocTranslation] Status poll', ['job_id' => $jobId]);

        // Validate UUID format
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $jobId)) {
            return response()->json(['success' => false, 'error' => 'Invalid job ID.'], 400);
        }

        try {
            $result = $documentService->checkStatus($jobId);

            // When done, store completed document in session for persistence
            if ($result['status'] === 'done' && $result['download_id']) {
                $jobData = $documentService->getJobData($jobId);
                $translatedDocs = session('translated_documents', []);
                $translatedDocs[] = [
                    'download_id' => $result['download_id'],
                    'original_name' => $jobData['original_name'] ?? 'document',
                    'output_extension' => $jobData['output_extension'] ?? 'pdf',
                    'target_lang' => $jobData['target_lang'] ?? '',
                    'translated_at' => now()->toIso8601String(),
                ];
                session(['translated_documents' => $translatedDocs]);
            }

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (TranslationFailedException $e) {
            Log::error('[DocTranslation] Status check failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            $errorCode = null;
            if (str_contains($e->getMessage(), 'Source and target language are equal')) {
                $errorCode = 'same_language';
            }

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => $errorCode,
            ], 500);
        }
    }

    /**
     * Download a translated document and remove it from storage.
     */
    public function downloadDocument(Request $request, string $downloadId, DocumentTranslationService $documentService): \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
    {
        // Validate UUID format
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $downloadId)) {
            return response()->json(['success' => false, 'error' => 'Invalid download ID.'], 400);
        }

        $filePath = $documentService->getTranslatedFilePath($downloadId);

        if (! $filePath || ! file_exists($filePath)) {
            // Remove from session if file no longer exists
            $this->removeFromSessionList($downloadId);

            return response()->json(['success' => false, 'error' => 'File not found or already downloaded.'], 404);
        }

        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $originalName = $request->query('name', 'translated_document');
        $langSuffix = $request->query('lang', '');
        $filename = $originalName.($langSuffix ? '_'.$langSuffix : '').'.'.$extension;

        // Mark as downloaded in DB — file stays on disk until scheduler cleans it up
        TranslateDocument::where('download_id', $downloadId)
            ->update(['downloaded_at' => now()]);

        return response()->download($filePath, $filename);
    }

    /**
     * List all translated documents available for download.
     * Source of truth is the DB — session is no longer used.
     */
    public function listTranslatedDocuments(): JsonResponse
    {
        $docs = TranslateDocument::where('user_id', Auth::id())
            ->where('status', 'done')
            ->orderBy('translated_at', 'desc')
            ->get();

        $availableDocs = $docs
            ->filter(fn ($doc) => $doc->fileExists())
            ->map(fn ($doc) => [
                'download_id' => $doc->download_id,
                'original_name' => $doc->original_name,
                'output_extension' => $doc->output_extension,
                'target_lang' => $doc->target_lang,
                'file_size' => $doc->file_size,
                'translated_at' => $doc->translated_at?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => $availableDocs,
        ]);
    }

    /**
     * Delete a translated document from storage and DB.
     */
    public function deleteDocument(string $downloadId, DocumentTranslationService $documentService): JsonResponse
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $downloadId)) {
            return response()->json(['success' => false, 'error' => 'Invalid download ID.'], 400);
        }

        $documentService->deleteTranslatedFile($downloadId);

        return response()->json(['success' => true]);
    }

    /**
     * View (inline) a translated document in the browser.
     */
    public function viewDocument(Request $request, string $downloadId, DocumentTranslationService $documentService): \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $downloadId)) {
            return response()->json(['success' => false, 'error' => 'Invalid download ID.'], 400);
        }

        $filePath = $documentService->getTranslatedFilePath($downloadId);

        if (! $filePath || ! file_exists($filePath)) {
            $this->removeFromSessionList($downloadId);

            return response()->json(['success' => false, 'error' => 'File not found.'], 404);
        }

        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $originalName = $request->query('name', 'translated_document');
        $langSuffix = $request->query('lang', '');
        $filename = $originalName.($langSuffix ? '_'.$langSuffix : '').'.'.$extension;

        return response()->file($filePath, [
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * Remove a document from the session's translated documents list.
     */
    private function removeFromSessionList(string $downloadId): void
    {
        $translatedDocs = session('translated_documents', []);
        $translatedDocs = array_values(array_filter(
            $translatedDocs,
            fn ($doc) => $doc['download_id'] !== $downloadId
        ));
        session(['translated_documents' => $translatedDocs]);
    }
}
