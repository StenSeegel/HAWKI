<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transcription;

use App\Http\Controllers\Controller;
use App\Jobs\Transcription\GenerateTranscriptionTitle;
use App\Models\Transcription\Transcription;
use App\Models\Transcription\TranscriptionJob;
use App\Services\Transcription\AsyncTranscriptionService;
use App\Services\Transcription\TranscriptionService;
use App\Services\Transcription\TranscriptionSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TranscriptionController extends Controller
{
    protected $transcriptionService;

    public function __construct(TranscriptionService $transcriptionService)
    {
        $this->transcriptionService = $transcriptionService;

        // Erhöhe PHP-Limits für Audio-Transkription (funktioniert mit allen Webservern)
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '900');
        @ini_set('max_input_time', '900');
        @ini_set('upload_max_filesize', '100M');
        @ini_set('post_max_size', '100M');
    }

    /**
     * Transkribiert eine Audiodatei
     */
    public function transcribe(Request $request)
    {
        try {
            $request->validate([
                'audio' => 'required|file|mimes:mp3,wav,m4a,ogg,flac,webm|max:25600', // Max 25MB (OpenAI Whisper API Limit)
                'language' => 'nullable|string|max:5',
            ]);

            $result = $this->transcriptionService->transcribeAudio(
                $request->file('audio'),
                $request->input('language')
            );

            return response()->json([
                'success' => true,
                'text' => $result['text'],
                'segments' => $result['segments'] ?? [],
                'language' => $result['language'] ?? null,
                'model_used' => $result['model'] ?? null,
                'provider' => $result['provider'] ?? null,
                'provider_name' => $result['provider_name'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Transcription error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return response()->json([
                'success' => false,
                'message' => 'Ein Fehler ist bei der Transkription aufgetreten: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Startet eine asynchrone Transkriptions-Upload-Session für große Dateien.
     */
    public function createUploadSession(Request $request, AsyncTranscriptionService $asyncService)
    {
        $request->validate([
            'filename' => 'required|string|max:255',
            'language' => 'nullable|string',
            'speaker_count' => 'nullable|string',
        ]);

        try {
            $session = $asyncService->generateUploadSession(
                Auth::id(),
                $request->input('filename'),
                $request->input('language', 'auto'),
                $request->input('speaker_count', 'auto')
            );

            return response()->json([
                'success' => true,
                'session' => $session,
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating upload session: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Error generating session'], 500);
        }
    }

    /**
     * Reiht den hochgeladenen Job in die Warteschlange ein.
     */
    public function dispatchJob($jobId, Request $request, AsyncTranscriptionService $asyncService)
    {
        $job = TranscriptionJob::where('id', $jobId)->where('user_id', Auth::id())->firstOrFail();

        $speakerMapping = $request->input('speaker_mapping');
        $speakerSnippets = $request->input('speaker_snippets');
        $speakerCount = $request->input('speaker_count');
        $llmCorrection = $request->input('llm_correction');

        $manifest = $job->manifest_data ?? [];
        if (! isset($manifest['settings'])) {
            $manifest['settings'] = [];
        }

        if ($speakerMapping) {
            if (is_string($speakerMapping)) {
                $speakerMapping = json_decode($speakerMapping, true);
            }
            $manifest['settings']['speaker_mapping'] = $speakerMapping;
        }
        if ($speakerSnippets) {
            if (is_string($speakerSnippets)) {
                $speakerSnippets = json_decode($speakerSnippets, true);
            }
            $manifest['settings']['speaker_snippets'] = $speakerSnippets;
        }
        if ($speakerCount !== null) {
            $manifest['settings']['speaker_count'] = $speakerCount;
        }

        $manifest['settings']['llm_correction'] = (bool) $llmCorrection;

        $job->update(['manifest_data' => $manifest]);

        try {
            $asyncService->dispatchPreprocessingJob($job);

            return response()->json([
                'success' => true,
                'message' => 'Job dispatched successfully',
                'status' => $job->status,
            ]);
        } catch (\Exception $e) {
            Log::error('Error dispatching job: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Error dispatching job'], 500);
        }
    }

    /**
     * Startet die Sprecheranalyse für den hochgeladenen Job.
     */
    public function analyzeJob($jobId, AsyncTranscriptionService $asyncService)
    {
        $job = TranscriptionJob::where('id', $jobId)->where('user_id', Auth::id())->firstOrFail();

        try {
            $asyncService->dispatchAnalyzeJob($job);

            return response()->json([
                'success' => true,
                'message' => 'Analyze job dispatched successfully',
                'status' => $job->status,
            ]);
        } catch (\Exception $e) {
            Log::error('Error dispatching analyze job: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Error dispatching analyze job'], 500);
        }
    }

    /**
     * Liest den Status eines asynchronen Jobs aus.
     */
    public function getAsyncStatus($jobId)
    {
        $job = TranscriptionJob::where('id', $jobId)->where('user_id', Auth::id())->firstOrFail();

        $response = [
            'success' => true,
            'status' => $job->status,
            'job_id' => $job->id,
            'manifest' => in_array($job->status, ['preprocessed', 'transcribing', 'analyzed_speakers']) ? $job->manifest_data : null,
            'error' => $job->error_message,
        ];

        if ($job->status === 'completed' && $job->result_data) {
            $response['result'] = $job->result_data;
        }

        return response()->json($response);
    }

    /**
     * Status einer Transkription abrufen
     */
    public function getStatus($jobId)
    {
        try {
            $status = $this->transcriptionService->getTranscriptionStatus($jobId);

            return response()->json([
                'success' => true,
                'data' => $status,
            ]);
        } catch (\Exception $e) {
            Log::error('Status check error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Statusabruf: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Gibt die aktuelle Konfiguration des Transkriptions-Service zurück
     */
    public function getConfiguration(\App\Services\AI\Config\AiConfigService $aiConfigService)
    {
        try {
            $config = $this->transcriptionService->getConfiguration();

            // Get all available providers from AiConfigService
            // Filter to only include providers that have an OpenAi adapter, as Transcription currently requires it
            // Or just return all and let frontend decide, but filtering here is better
            $allProviders = $aiConfigService->getProviders();

            $transcriptionProviders = [];
            foreach ($allProviders as $uniqueName => $providerData) {
                $adapter = $providerData['adapter'] ?? '';
                if (in_array($adapter, ['OpenAi', 'Responses']) && ($providerData['active'] ?? true)) {
                    $transcriptionProviders[] = array_merge(['unique_name' => $uniqueName], $providerData);
                }
            }

            // If no OpenAI providers are found, return the fallback if it exists
            if (empty($transcriptionProviders) && isset($allProviders['openai'])) {
                $transcriptionProviders[] = array_merge(['unique_name' => 'openai'], $allProviders['openai']);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'current' => $config,
                    'providers' => $transcriptionProviders,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Configuration retrieval error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Konfiguration konnte nicht geladen werden',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Speichert die Konfiguration für den Transkriptions-Service
     */
    public function saveConfiguration(Request $request, TranscriptionSettingsService $settingsService)
    {
        try {
            $validated = $request->validate([
                'provider' => 'required|string',
                'model' => 'required|string',
            ]);

            // Save to database via TranscriptionSettingsService
            $settingsService->set('provider', $validated['provider']);
            $settingsService->set('model', $validated['model']);

            return response()->json([
                'success' => true,
                'message' => 'Einstellungen erfolgreich gespeichert',
            ]);
        } catch (\Exception $e) {
            Log::error('Configuration save error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Einstellungen konnten nicht gespeichert werden',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Testet die Verbindung zum Ollama-Server
     */
    public function testConnection()
    {
        try {
            $result = $this->transcriptionService->testConnection();

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Connection test error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Verbindungstest: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Speichert eine Transkription in der Datenbank
     */
    public function save(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'segments' => 'required|array',
                'words' => 'nullable|array',
                'language' => 'nullable|string|max:10',
                'duration' => 'nullable|integer',
                'model_used' => 'nullable|string',
                'provider' => 'nullable|string',
                'original_filename' => 'nullable|string',
                'file_size' => 'nullable|integer',
                'metadata' => 'nullable|array',
                'title' => 'nullable|string|max:255',
            ]);

            if (empty($validatedData['segments'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Segmente fehlen. Die Transkription kann nicht ohne Segmente gespeichert werden.',
                ], 422);
            }

            $resolvedModelUsed = null;
            $resolvedProvider = null;
            try {
                $config = $this->transcriptionService->getConfiguration();
                $resolvedModelUsed = $config['model']['model_id'] ?? null;
                $resolvedProvider = $config['provider']['unique_name'] ?? null;
            } catch (\Throwable $e) {
                Log::warning('Transcription save: could not resolve provider/model from server configuration.', [
                    'error' => $e->getMessage(),
                ]);
            }

            if (empty($resolvedModelUsed) || empty($resolvedProvider)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Transkriptions-Konfiguration konnte nicht aufgelöst werden. Bitte prüfen Sie Provider- und Modell-Einstellungen.',
                ], 500);
            }

            $transcription = DB::transaction(function () use ($validatedData, $resolvedModelUsed, $resolvedProvider) {
                $transcription = Transcription::create([
                    'title' => $validatedData['title'] ?? (($validatedData['original_filename'] ?? 'Upload').' '.now()->format('d.m.Y H:i')),
                    'user_id' => Auth::id(),
                    'language' => $validatedData['language'] ?? null,
                    'user_locale' => app()->getLocale(),
                    'duration' => $validatedData['duration'] ?? null,
                    // Use backend-resolved runtime configuration as source of truth.
                    'model_used' => $resolvedModelUsed,
                    'provider' => $resolvedProvider,
                    'original_filename' => $validatedData['original_filename'] ?? null,
                    'file_size' => $validatedData['file_size'] ?? null,
                    'metadata' => $validatedData['metadata'] ?? null,
                ]);

                $transcription->textData()->create([
                    'segments' => $validatedData['segments'],
                    'words' => $validatedData['words'] ?? null,
                ]);

                // Associate corresponding TranscriptionJob(s) with this transcription
                if (isset($validatedData['metadata'])) {
                    $metadata = $validatedData['metadata'];
                    if (isset($metadata['source_files']) && is_array($metadata['source_files'])) {
                        foreach ($metadata['source_files'] as $sf) {
                            if (! empty($sf['job_id'])) {
                                TranscriptionJob::where('id', $sf['job_id'])
                                    ->where('user_id', Auth::id())
                                    ->update(['transcription_id' => $transcription->id]);
                            }
                        }
                    }
                    if (! empty($metadata['job_id'])) {
                        TranscriptionJob::where('id', $metadata['job_id'])
                            ->where('user_id', Auth::id())
                            ->update(['transcription_id' => $transcription->id]);
                    }
                }

                return $transcription;
            });

            // Trigger automatic title generation (async in queue) only if no custom title was provided
            if (empty($validatedData['title'])) {
                GenerateTranscriptionTitle::dispatch($transcription);
            }

            return response()->json([
                'success' => true,
                'transcription' => $transcription,
                'message' => 'Transkription erfolgreich gespeichert',
            ], 201);
        } catch (\Exception $e) {
            Log::error('Transcription save error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Speichern: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Liste aller Transkriptionen des Benutzers
     */
    public function list(Request $request)
    {
        try {
            $transcriptions = Transcription::forUser(Auth::id())
                ->recent(50)
                ->get(['id', 'slug', 'title', 'language', 'duration', 'original_filename', 'created_at', 'updated_at']);

            return response()->json([
                'success' => true,
                'transcriptions' => $transcriptions,
            ]);
        } catch (\Exception $e) {
            Log::error('Transcriptions list error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Laden der Liste: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Liste aller aktiven Transkriptions-Jobs
     */
    public function getActiveJobs(Request $request)
    {
        try {
            // Hole Jobs, die in den letzten 24 Stunden erstellt wurden und nicht abgeschlossen oder fehlgeschlagen sind
            $activeJobs = TranscriptionJob::where('user_id', Auth::id())
                ->whereIn('status', ['pending', 'preprocessing', 'transcribing'])
                ->where('created_at', '>=', now()->subHours(24))
                ->orderBy('created_at', 'desc')
                ->get(['id', 'status', 'created_at']);

            return response()->json([
                'success' => true,
                'jobs' => $activeJobs,
            ]);
        } catch (\Exception $e) {
            Log::error('Active jobs list error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Laden der aktiven Jobs: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Lädt eine einzelne Transkription
     */
    public function load($slug)
    {
        try {
            $transcription = Transcription::where('slug', $slug)
                ->where('user_id', Auth::id())
                ->with('textData')
                ->firstOrFail();

            $transcriptionArray = $transcription->toArray();
            $transcriptionArray['transcript_text'] = $transcription->textData?->resolvedTranscriptText() ?? '';
            $transcriptionArray['segments'] = $transcription->textData?->segments ?? [];
            $transcriptionArray['words'] = $transcription->textData?->words ?? [];
            unset($transcriptionArray['text_data']);

            return response()->json([
                'success' => true,
                'transcription' => $transcriptionArray,
            ]);
        } catch (\Exception $e) {
            Log::error('Transcription load error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Transkription nicht gefunden',
            ], 404);
        }
    }

    /**
     * Löscht eine Transkription
     */
    public function delete($slug)
    {
        try {
            $transcription = Transcription::where('slug', $slug)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $transcription->delete();

            return response()->json([
                'success' => true,
                'message' => 'Transkription erfolgreich gelöscht',
            ]);
        } catch (\Exception $e) {
            Log::error('Transcription delete error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Löschen',
            ], 500);
        }
    }

    /**
     * Aktualisiert den Titel einer Transkription
     */
    public function updateTitle(Request $request, $slug)
    {
        try {
            $validatedData = $request->validate([
                'title' => 'required|string|max:255',
            ]);

            $transcription = Transcription::where('slug', $slug)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $transcription->update(['title' => $validatedData['title']]);

            return response()->json([
                'success' => true,
                'message' => 'Titel erfolgreich aktualisiert',
            ]);
        } catch (\Exception $e) {
            Log::error('Title update error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Aktualisieren des Titels',
            ], 500);
        }
    }

    /**
     * Aktualisiert die Segmente einer Transkription (Sprecher-Korrekturen)
     */
    public function updateSegments(Request $request, $slug)
    {
        try {
            $validatedData = $request->validate([
                'segments' => 'required|array',
            ]);

            $transcription = Transcription::where('slug', $slug)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $transcription->textData()->update([
                'segments' => $validatedData['segments'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Segmente erfolgreich aktualisiert',
            ]);
        } catch (\Exception $e) {
            Log::error('Segments update error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Aktualisieren der Segmente',
            ], 500);
        }
    }

    /**
     * Erstellt eine KI-Zusammenfassung (Ergebnisprotokoll) des Transkripts
     */
    public function summarize(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'transcript_text' => 'nullable|string',
                'transcription_slug' => 'nullable|string',
                'force_regenerate' => 'nullable|boolean',
                'check_only' => 'nullable|boolean',
                'model' => 'nullable|string',
            ]);

            $transcription = null;
            if (! empty($validatedData['transcription_slug'])) {
                $transcription = \App\Models\Transcription::where('slug', $validatedData['transcription_slug'])->first();

                if ($transcription && empty($validatedData['force_regenerate'])) {
                    $metadata = $transcription->metadata ?? [];
                    if (! empty($metadata['summary'])) {
                        return response()->json([
                            'success' => true,
                            'summary' => $metadata['summary'],
                        ]);
                    }
                }
            }

            if (! empty($validatedData['check_only'])) {
                return response()->json([
                    'success' => true,
                    'summary' => null,
                ]);
            }

            if (empty($validatedData['transcript_text'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Transkript-Text fehlt.',
                ], 422);
            }

            $text = $validatedData['transcript_text'];

            // Wir nutzen den AiService für die Zusammenfassung
            $aiService = app(\App\Services\AI\AiService::class);

            $prompt = "Du bist ein Experte für Gesprächsprotokolle. Hier ist das Transkript eines Gesprächs. Erstelle ein professionelles Ergebnisprotokoll.\n\n".
                      "Struktur:\n".
                      "1. Titel/Thema (basierend auf dem Inhalt)\n".
                      "2. Zusammenfassung (kurz und prägnant)\n".
                      "3. Wichtigste Kernaussagen (als Stichpunkte)\n".
                      "4. Beschlüsse und nächste Schritte (falls identifizierbar)\n\n".
                      "Sprache: Deutsch. Form: Professionell, sachlich.\n\n".
                      "TRANSKRIPT:\n".$text;

            $aiConfigService = app(\App\Services\AI\Config\AiConfigService::class);
            $defaultModels = $aiConfigService->getDefaultModels();
            $model = $validatedData['model'] ?? $defaultModels['default_model'] ?? 'gpt-4o';

            $payload = [
                'model' => $model,
                'stream' => false,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => ['text' => 'Du bist ein hilfreicher Assistent, der Transkripte präzise und professionell zusammenfasst.'],
                    ],
                    [
                        'role' => 'user',
                        'content' => ['text' => $prompt],
                    ],
                ],
            ];

            try {
                $aiModelObj = $aiService->getModelOrFail($model);
                $providerConfig = $aiModelObj->getProvider()->getConfig();

                Log::info('Request:', [
                    'provider' => $providerConfig->getId(),
                    'model' => $aiModelObj->getId(),
                    'base_url' => $providerConfig->getApiUrl(),
                    'Payload' => $payload,
                ]);
            } catch (\Exception $e) {
                // Ignoriere fehlende Provider-Konfiguration für Logging
            }

            $response = $aiService->sendRequest($payload);

            if (isset($providerConfig)) {
                Log::info($providerConfig->getId().' API-Verbindung erfolgreich', [
                    'url' => $providerConfig->getApiUrl(),
                    'status' => 200,
                ]);
            }

            $summary = '';
            if (is_object($response) && isset($response->content)) {
                $content = $response->content;
                if (is_array($content)) {
                    $summary = $content['text'] ?? ($content[0]['text'] ?? '');
                } elseif (is_string($content)) {
                    $summary = $content;
                }
            }

            if ($transcription && ! empty($summary)) {
                $metadata = $transcription->metadata ?? [];
                $metadata['summary'] = $summary;
                $transcription->metadata = $metadata;
                $transcription->save();
            }

            return response()->json([
                'success' => true,
                'summary' => $summary,
            ]);
        } catch (\Exception $e) {
            Log::error('Summarization error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Fehler bei der Zusammenfassung: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Optimiert die Sprecherzuordnung im Transkript semantisch mithilfe von KI
     */
    public function optimizeSpeakers(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'segments' => 'required|array',
                'model' => 'nullable|string',
            ]);

            $segments = $validatedData['segments'];
            $model = $validatedData['model'] ?? null;

            $asyncService = app(\App\Services\Transcription\AsyncTranscriptionService::class);
            $optimizedSegments = $asyncService->optimizeTranscriptSpeakers($segments, $model);

            return response()->json([
                'success' => true,
                'segments' => $optimizedSegments,
            ]);
        } catch (\Exception $e) {
            Log::error('Speaker optimization error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Fehler bei der Sprecher-Optimierung: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generiert eine Presigned URL fuer das Audio-Streaming aus S3 fuer einen bestimmten Job oder ein Transkript.
     */
    public function getAudioPresignedUrl(Request $request, AsyncTranscriptionService $asyncService): \Illuminate\Http\JsonResponse
    {
        try {
            $jobId = $request->query('job_id');
            $slug = $request->query('slug');
            $index = $request->query('index') !== null ? (int) $request->query('index') : null;

            $url = $asyncService->getAudioPresignedUrl(Auth::id(), $jobId, $slug, $index);
            if (! $url) {
                return response()->json([
                    'success' => false,
                    'message' => 'Audio-Datei nicht gefunden oder kein Zugriff.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'url' => $url,
            ]);
        } catch (\Exception $e) {
            Log::error('Error generating presigned url: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ein Fehler ist beim Generieren der Stream-URL aufgetreten: '.$e->getMessage(),
            ], 500);
        }
    }
}
