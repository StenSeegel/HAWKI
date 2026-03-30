<?php

namespace App\Http\Controllers;

use App\Http\Requests\TranslateDocumentRequest;
use App\Jobs\ProcessDocumentTranslation;
use App\Models\TranslateDocument;
use App\Services\AI\AiService;
use App\Services\AI\Config\AiConfigService;
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
        private TextImprovementService $textImprovementService,
        private AiService $aiService,
        private AiConfigService $aiConfigService,
    ) {}

    /**
     * Translate text using DeepL API
     */
    public function translate(Request $request): JsonResponse
    {
        if (config('app.debug')) {
            Log::debug('[Translation API] Pre-validation request data', $request->all());
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'text' => 'required', // string or array
            'source_lang' => 'nullable|string|max:10',
            'target_lang' => 'nullable|string|max:10',
            'glossary_id' => 'nullable',
            'model' => 'nullable|string|max:255',
            'formality' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            if (config('app.debug')) {
                Log::warning('[Translation API] Validation failed', [
                    'errors' => $validator->errors()->toArray(),
                    'input' => $request->all()
                ]);
            }
            return response()->json([
                'success' => false,
                'error' => 'Validierung fehlgeschlagen.',
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        $glossaryId = $validated['glossary_id'] ?? null;
        if (is_array($glossaryId)) {
            $glossaryId = array_map('intval', $glossaryId);
        } elseif (is_numeric($glossaryId)) {
            $glossaryId = (int) $glossaryId;
        }

        try {
            // Call translation service
            $result = $this->translationService->translate(
                text: $validated['text'],
                sourceLang: $validated['source_lang'] ?? null,
                targetLang: ($validated['target_lang'] ?? null) ?: 'en-gb',
                glossaryId: $glossaryId,
                model: $validated['model'] ?? null, // Pass model
                formality: $validated['formality'] ?? null
            );

            $showDebug = \Illuminate\Support\Facades\Cache::remember('translate_settings_show_debug_infos', now()->addHours(1), function () {
                return \App\Models\TranslateSetting::where('key', 'show_debug_infos')->first()?->typed_value ?? false;
            });

            $response = [
                'success' => true,
                'data' => [
                    'text' => $result['text'],
                    'detected_source_language' => $result['detected_source_language'],
                ],
            ];

            if ($showDebug) {
                $response['debug'] = [
                    'request' => $validated,
                    'result' => $result,
                    'provider' => get_class($this->translationService), // Or more specific if needed
                ];
            }

            return response()->json($response);

        } catch (InvalidLanguageException $e) {
            if ($this->translationService->shouldShowDebug()) {
                Log::warning('[Text Translation] Failed: Invalid language', [
                    'error' => $e->getMessage(),
                    'source_lang' => $validated['source_lang'] ?? null,
                    'target_lang' => $validated['target_lang'],
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Ungültige Sprache ausgewählt. Bitte wählen Sie eine unterstützte Sprache.',
                'message' => $e->getMessage(),
            ], 400);

        } catch (QuotaExceededException $e) {
            if ($this->translationService->shouldShowDebug()) {
                Log::error('[Text Translation] Failed: Quota exceeded', [
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Übersetzungslimit erreicht. Bitte versuchen Sie es später erneut.',
                'message' => $e->getMessage(),
            ], 429);

        } catch (TranslationFailedException $e) {
            if ($this->translationService->shouldShowDebug()) {
                Log::error('[Text Translation] Failed', [
                    'error' => $e->getMessage(),
                    'source_lang' => $validated['source_lang'] ?? null,
                    'target_lang' => $validated['target_lang'],
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Übersetzung fehlgeschlagen. Bitte versuchen Sie es erneut.',
                'message' => $e->getMessage(),
            ], 500);

        } catch (\Exception $e) {
            if ($this->translationService->shouldShowDebug()) {
                Log::error('[Text Translation] Unexpected error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Ein unerwarteter Fehler ist aufgetreten.',
                'message' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Detect the language of the given text using the title_generator LLM model.
     * Takes a short sample of the input to minimise token usage.
     */
    public function detectLanguage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => 'required|string|max:5000',
        ]);

        // Use only the first 50 characters for a lightweight detection
        $sample = mb_substr($validated['text'], 0, 50);

        $showDebug = $this->translationService->shouldShowDebug();

        // Resolve the language detection model from translation settings
        $modelId = $this->translationService->resolveDefaultModelForType('detection', false);

        if (empty($modelId)) {
            if ($showDebug) {
                Log::warning('[Language Detection] No model configured for detection in translation extension');
            }

            return response()->json([
                'success' => false,
                'error' => 'Kein Modell für die Spracherkennung konfiguriert.',
            ], 503);
        }

        if ($showDebug) {
            $logContext = [
                'model_id' => $modelId,
                'sample_length' => mb_strlen($sample),
                'input' => $sample,
            ];

            Log::debug('[Language Detection] Requested', $logContext);

            if ($this->translationService->shouldShowPayload()) {
                Log::debug('[Language Detection] Request Payload', [
                    'model' => $modelId,
                    'sample' => $sample,
                ]);
            }
        }

        if (empty($modelId)) {
            if ($showDebug) {
                Log::warning('[Language Detection] No model configured');
            }

            return response()->json([
                'success' => false,
                'error' => 'No language detection model configured.',
            ], 503);
        }

        try {
            $payload = [
                'model' => $modelId,
                'stream' => false,
                'max_tokens' => 5,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => ['text' => 'You are a language detection tool. Detect the PRIMARY grammatical language of the text — focus on sentence structure, grammar, and common words. Ignore proper nouns, city names, institution names, abbreviations, and brand names, as these may appear in any language. Beware of translated text, that might appear as false positive for the origin language (e.g. Kafka translated to english should be detected as english, not german). Respond ONLY with the ISO 639-1 two-letter language code (e.g. "de", "en", "fr"). No explanation, no punctuation, just the two-letter code.'],
                    ],
                    [
                        'role' => 'user',
                        'content' => ['text' => $sample],
                    ],
                ],
            ];

            $maxAttempts = 3; // 1 initial + 2 retries
            $detectedCode = null;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $response = $this->aiService->sendRequest($payload);

                $rawResponse = $response->content['text'] ?? '';
                $detectedCode = strtolower(trim($rawResponse));

                if ($showDebug && $this->translationService->shouldShowPayload()) {
                    Log::debug('[Language Detection] Model response', [
                        'attempt' => $attempt,
                        'raw' => $rawResponse,
                        'detected_code' => $detectedCode,
                    ]);
                }

                if (preg_match('/^[a-z]{2}$/', $detectedCode)) {
                    break; // Valid code received
                }

                if ($showDebug) {
                    Log::warning('[Language Detection] Malformed output — retrying', [
                        'attempt' => $attempt,
                        'raw' => $rawResponse,
                    ]);
                }

                $detectedCode = null;
            }

            if ($detectedCode === null) {
                if ($showDebug) {
                    Log::warning('[Language Detection] All attempts returned malformed output — falling back to no source language');
                }

                return response()->json(['success' => true, 'data' => ['language' => null]]);
            }

            if ($showDebug) {
                Log::debug('[Language Detection] Completed', ['language' => $detectedCode]);
            }

            return response()->json([
                'success' => true,
                'data' => ['language' => $detectedCode],
            ]);

        } catch (\Exception $e) {
            if ($showDebug) {
                Log::error('[Language Detection] Failed', ['error' => $e->getMessage()]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Language detection failed.',
            ], 500);
        }
    }

    /**
     * Get available AI models for text improvement
     */
    public function getModels(): JsonResponse
    {
        try {
            $result = $this->translationService->getAvailableModels();

            return response()->json([
                'success' => true,
                'data' => [
                    'models' => $result['models'],
                    'default_model' => $result['default_model'],
                ],
            ]);

        } catch (\Exception $e) {
            if ($this->translationService->shouldShowDebug()) {
                Log::error('[AI Models] Failed to fetch available models', [
                    'error' => $e->getMessage(),
                ]);
            }

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
            'text' => 'required', // string or array
            'source_lang' => 'nullable|string|max:10',
            'target_lang' => 'nullable|string|max:10',
            'model' => 'nullable|string|max:100',
            'style' => 'nullable|string|max:50',
            'tone' => 'nullable|string|max:50',
            'formality' => 'nullable|string|max:50',
            'exclusions' => 'nullable|array',
            'type' => 'nullable|string|in:default,improvement,alternatives,synonyms,correction',
            'context' => 'nullable|string',
        ]);

        try {
            // Check if DeepL Write is selected
            $modelId = $validated['model'] ?? null;
            $type = $validated['type'] ?? 'default';

            // Auto-detect type as 'alternatives' if exclusions are present and type is default
            if (! empty($validated['exclusions']) && $type === 'default') {
                $type = 'alternatives';
            }

            // Check if DeepL Write is selected or if explicit "DeepL API Pro" was chosen for improvement
            // Mandatory: Synonyms MUST be handled by AI models, not by standard DeepL Write/API
            if (($modelId === 'deepl-write' || $modelId === 'deepl') && ! in_array($type, ['synonyms', 'correction'])) {
                $result = $this->translationService->write(
                    text: $validated['text'],
                    targetLang: $validated['target_lang'] ?? null,
                    style: $validated['style'] ?? null,
                    tone: $validated['tone'] ?? null
                );
            } else {
                // Use AI models (GWDG, Ollama, OpenAI, etc.)
                $result = $this->textImprovementService->improveText(
                    text: $validated['text'],
                    sourceLang: $validated['source_lang'] ?? null,
                    targetLang: $validated['target_lang'] ?? null,
                    modelId: $modelId,
                    style: $validated['style'] ?? null,
                    tone: $validated['tone'] ?? null,
                    formality: $validated['formality'] ?? null,
                    exclusions: $validated['exclusions'] ?? null,
                    type: $type,
                    context: $validated['context'] ?? null
                );
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'text' => $result['text'],
                ],
            ]);

        } catch (InvalidLanguageException $e) {
            if ($this->translationService->shouldShowDebug()) {
                Log::warning('[Text Rephrase] Failed: Invalid language', [
                    'error' => $e->getMessage(),
                    'target_lang' => $validated['target_lang'] ?? null,
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Ungültige Sprache ausgewählt. Bitte wählen Sie eine unterstützte Sprache.',
                'message' => $e->getMessage(),
            ], 400);

        } catch (QuotaExceededException $e) {
            if ($this->translationService->shouldShowDebug()) {
                Log::error('[Text Rephrase] Failed: Quota exceeded', [
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Verbesserungslimit erreicht. Bitte versuchen Sie es später erneut.',
                'message' => $e->getMessage(),
            ], 429);

        } catch (TranslationFailedException $e) {
            if ($this->translationService->shouldShowDebug()) {
                Log::error('[Text Rephrase] Failed', [
                    'error' => $e->getMessage(),
                    'target_lang' => $validated['target_lang'] ?? null,
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Textverbesserung fehlgeschlagen. Bitte versuchen Sie es erneut.',
                'message' => $e->getMessage(),
            ], 500);

        } catch (\Exception $e) {
            if ($this->translationService->shouldShowDebug()) {
                Log::error('[Text Rephrase] Unexpected error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

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

        $showDebug = $this->translationService->shouldShowDebug();

        if ($showDebug) {
            Log::debug('[Document Translation] Requested', [
                'file_name' => $uploadedFile?->getClientOriginalName(),
                'file_size' => $uploadedFile?->getSize(),
                'target_lang' => $request->validated('target_lang'),
                'source_lang' => $request->validated('source_lang'),
            ]);
        }

        try {
            $glossaryId = $request->validated('glossary_id');
            if (is_array($glossaryId)) {
                $glossaryId = array_map('intval', $glossaryId);
            } elseif (is_numeric($glossaryId)) {
                $glossaryId = (int) $glossaryId;
            }

            $result = $documentService->uploadDocument(
                file: $uploadedFile,
                targetLang: $request->validated('target_lang'),
                sourceLang: $request->validated('source_lang'),
                glossaryId: $glossaryId,
                formality: $request->validated('formality'),
            );

            if ($showDebug) {
                Log::info('[Document Translation] Upload successful', $result);
            }

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
            if ($showDebug) {
                Log::error('[Document Translation] Upload failed', [
                    'error' => $e->getMessage(),
                    'previous' => $e->getPrevious()?->getMessage(),
                ]);
            }

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
            if ($showDebug) {
                Log::error('[Document Translation] Unexpected exception during upload', [
                    'exception_class' => get_class($e),
                    'error' => $e->getMessage(),
                ]);
            }

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
        if ($this->translationService->shouldShowDebug()) {
            Log::debug('[Document Translation] Status poll', ['job_id' => $jobId]);
        }

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
            if ($this->translationService->shouldShowDebug()) {
                Log::error('[Document Translation] Status check failed', [
                    'job_id' => $jobId,
                    'error' => $e->getMessage(),
                ]);
            }

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
            ->filter(fn (TranslateDocument $doc) => $doc->fileExists())
            ->map(fn (TranslateDocument $doc) => [
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
