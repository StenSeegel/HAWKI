<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transcription;

use App\Http\Controllers\Controller;
use App\Jobs\Transcription\GenerateTranscriptionTitle;
use App\Models\Transcription\CustomTranscriptFormat;
use App\Models\Transcription\SummaryTemplate;
use App\Models\Transcription\Transcription;
use App\Models\Transcription\TranscriptionJob;
use App\Services\Transcription\AsyncTranscriptionService;
use App\Services\Transcription\SummaryTemplateRegistry;
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
        $userId = Auth::id();
        session()->save();

        $request->validate([
            'filename' => 'required|string|max:255',
            'language' => 'nullable|string',
            'speaker_count' => 'nullable|string',
        ]);

        try {
            $session = $asyncService->generateUploadSession(
                $userId,
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
        $userId = Auth::id();
        session()->save();

        $job = TranscriptionJob::where('id', $jobId)->where('user_id', $userId)->firstOrFail();

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
        $userId = Auth::id();
        session()->save();

        $job = TranscriptionJob::where('id', $jobId)->where('user_id', $userId)->firstOrFail();

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
        $userId = Auth::id();
        session()->save();

        $job = TranscriptionJob::where('id', $jobId)->where('user_id', $userId)->firstOrFail();

        $response = [
            'success' => true,
            'status' => $job->status,
            'job_id' => $job->id,
            'manifest' => in_array($job->status, ['preprocessed', 'transcribing', 'analyzed_speakers', 'optimizing']) ? $job->manifest_data : null,
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
        $userId = Auth::id();
        session()->save();

        try {
            // Hole Jobs, die in den letzten 24 Stunden erstellt wurden und nicht abgeschlossen oder fehlgeschlagen sind
            $activeJobs = TranscriptionJob::where('user_id', $userId)
                ->whereIn('status', ['pending', 'preprocessing', 'transcribing', 'optimizing'])
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
     * Erstellt eine KI-Zusammenfassung (Ergebnisprotokoll) des Transkripts.
     * Unterstützt sowohl die klassische Generierung als auch die template-basierte
     * abschnittsweise Generierung für Previews und Exporte.
     */
    public function summarize(Request $request, SummaryTemplateRegistry $templateRegistry): \Illuminate\Http\JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'transcription_slug' => 'nullable|string',
                'transcript_text' => 'nullable|string',
                'template_id' => 'nullable|string',
                'preview' => 'nullable|boolean',
                'force_regenerate' => 'nullable|boolean',
                'check_only' => 'nullable|boolean',
                'model' => 'nullable|string',
                'section_index' => 'nullable|integer',
                // Keep backward compatibility parameters
                'sections' => 'nullable|array',
                'stale_headings' => 'nullable|array',
                'structure' => 'nullable|array',
            ]);

            // 1. Resolve transcription if slug is provided
            $transcription = null;
            if (! empty($validatedData['transcription_slug'])) {
                $transcription = Transcription::where('slug', $validatedData['transcription_slug'])
                    ->where('user_id', Auth::id())
                    ->with('textData')
                    ->first();
            }

            // 2. check_only optimization: if summary is already cached and not forced to regenerate, return it
            if ($transcription && empty($validatedData['force_regenerate']) && empty($validatedData['section_index']) && empty($validatedData['stale_headings'])) {
                $metadata = $transcription->metadata ?? [];
                if (! empty($metadata['summary'])) {
                    // Check if check_only requested
                    if (! empty($validatedData['check_only'])) {
                        return response()->json([
                            'success' => true,
                            'summary' => null,
                        ]);
                    }

                    $requestedTemplateId = $validatedData['template_id'] ?? 'legacy';
                    $cachedTemplateId = $transcription->summary_template_id ?? 'legacy';

                    if ($requestedTemplateId === $cachedTemplateId) {
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

            // 3. Resolve segments and transcript text
            $segments = [];
            if ($transcription) {
                $segments = $transcription->textData?->segments ?? [];
            }

            $preview = ! empty($validatedData['preview']);

            if ($preview) {
                $transcriptText = $this->getReducedTranscriptSample($segments);
            } else {
                $transcriptText = $validatedData['transcript_text'] ?? '';
                if (empty($transcriptText) && $transcription) {
                    $transcriptText = $this->getTranscriptText($segments, false);
                }
            }

            $isTemplateRun = $request->has('sections') || ! empty($validatedData['template_id']);
            if (empty($transcriptText) && ! $isTemplateRun) {
                return response()->json([
                    'success' => false,
                    'error' => 'Transkript-Text fehlt.',
                ], 422);
            }

            // 4. Resolve Template and Sections
            $sections = [];
            $templateId = $validatedData['template_id'] ?? null;

            if ($request->has('sections')) {
                // Backward compatibility / custom inline sections
                $sections = $validatedData['sections'];
            } else {
                // Template resolution via registry
                $template = $templateRegistry->resolve($templateId);
                $sections = $template->sections ?? [];
                $templateId = $template->id; // Resolved template ID
            }

            if (empty($sections)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Keine Abschnitte für Zusammenfassung definiert.',
                ], 400);
            }

            // 5. Placeholders resolution helper
            $title = $transcription->title ?? $validatedData['title'] ?? 'Interview';
            $date = $transcription->created_at ? $transcription->created_at->format('d.m.Y') : date('d.m.Y');

            $speakers = [];
            foreach ($segments as $s) {
                if (! empty($s['speaker'])) {
                    $speakers[] = $s['speaker'];
                }
            }
            $speakers = array_unique($speakers);
            $participants = empty($speakers) ? 'Keine' : implode(', ', $speakers);

            $durationVal = $transcription->duration ?? 0;
            $durationMin = $durationVal > 0 ? (int) ($durationVal / 60) : 0;
            $duration = "{$durationMin} Min";

            $replacePlaceholders = function (string $str) use ($title, $date, $participants, $duration): string {
                $placeholders = [
                    '{{titel}}' => $title,
                    '{{title}}' => $title,
                    '{{datum}}' => $date,
                    '{{date}}' => $date,
                    '{{teilnehmer}}' => $participants,
                    '{{participants}}' => $participants,
                    '{{dauer}}' => $duration,
                    '{{duration}}' => $duration,
                ];

                return str_replace(array_keys($placeholders), array_values($placeholders), $str);
            };

            // 6. Generate sections (per section call to LLM)
            $metadata = $transcription->metadata ?? [];
            $summarySections = $metadata['summary_sections'] ?? [];
            $results = [];

            $aiService = app(\App\Services\AI\AiService::class);
            $aiConfigService = app(\App\Services\AI\Config\AiConfigService::class);
            $defaultModels = $aiConfigService->getDefaultModels();
            $model = $validatedData['model'] ?? $defaultModels['default_model'] ?? 'gpt-4o';

            $forceRegenerate = ! empty($validatedData['force_regenerate']);
            $targetSectionIndex = isset($validatedData['section_index']) ? (int) $validatedData['section_index'] : null;
            $staleHeadings = $validatedData['stale_headings'] ?? null;

            foreach ($sections as $index => $section) {
                $heading = $section['heading'] ?? '';
                $instruction = $section['instruction'] ?? '';

                // If instruction is empty, it's a pure data row. No LLM generation.
                if (empty($instruction)) {
                    continue;
                }

                // Check if we should generate this section
                $shouldGenerate = false;
                if ($staleHeadings !== null) {
                    if (in_array($heading, $staleHeadings)) {
                        $shouldGenerate = true;
                    }
                } elseif ($targetSectionIndex !== null) {
                    if ($targetSectionIndex === $index) {
                        $shouldGenerate = true;
                    }
                } else {
                    if ($forceRegenerate) {
                        $shouldGenerate = true;
                    } elseif (! isset($summarySections[$index]) && ! isset($results[$heading])) {
                        $shouldGenerate = true;
                    }
                }

                if ($shouldGenerate) {
                    // Assemble prompt for this section
                    $prompt = $instruction."\n\nTRANSKRIPT:\n".$transcriptText;

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

                    $response = $aiService->sendRequest($payload);

                    $sectionOutput = '';
                    if (is_object($response) && isset($response->content)) {
                        $content = $response->content;
                        if (is_array($content)) {
                            $sectionOutput = $content['text'] ?? ($content[0]['text'] ?? '');
                        } elseif (is_string($content)) {
                            $sectionOutput = $content;
                        }
                    }

                    $results[$heading] = trim($sectionOutput);
                    $summarySections[$index] = trim($sectionOutput);
                } else {
                    $results[$heading] = $summarySections[$index] ?? '';
                }
            }

            // 7. Compile/assemble the final summary
            $assembledMarkdown = '';
            $structure = $validatedData['structure'] ?? null;

            if ($preview) {
                // In preview mode, return the individual generated results
                return response()->json([
                    'success' => true,
                    'results' => $results,
                ]);
            }

            // Assemble markdown structure
            if ($structure) {
                // If custom structure passed
                foreach ($structure as $block) {
                    if ($block['type'] === 'heading') {
                        $lvl = $block['level'] ?? 2;
                        $hashes = str_repeat('#', (int) $lvl);
                        $text = $replacePlaceholders($block['text'] ?? '');
                        $assembledMarkdown .= $hashes.' '.$text."\n\n";
                    } elseif ($block['type'] === 'text') {
                        $text = $replacePlaceholders($block['text'] ?? '');
                        $assembledMarkdown .= $text."\n\n";
                    } elseif ($block['type'] === 'divider') {
                        $assembledMarkdown .= "---\n\n";
                    } elseif ($block['type'] === 'section') {
                        $heading = $replacePlaceholders($block['heading'] ?? '');
                        $content = $results[$block['heading']] ?? '';
                        if (! empty($heading)) {
                            if (! str_starts_with(trim($heading), '#')) {
                                $assembledMarkdown .= '## '.$heading."\n";
                            } else {
                                $assembledMarkdown .= $heading."\n";
                            }
                        }
                        if (! empty($content)) {
                            $assembledMarkdown .= $content."\n\n";
                        }
                    }
                }
            } else {
                // Standard compilation based on resolved sections
                foreach ($sections as $index => $section) {
                    $heading = $replacePlaceholders($section['heading'] ?? '');
                    $content = $summarySections[$index] ?? '';

                    if (! empty($heading)) {
                        if (! str_starts_with(trim($heading), '#')) {
                            $assembledMarkdown .= '## '.$heading."\n";
                        } else {
                            $assembledMarkdown .= $heading."\n";
                        }
                    }

                    if (! empty($content)) {
                        $assembledMarkdown .= $content."\n\n";
                    }
                }
            }

            // Save summary back to transcription
            if ($transcription) {
                $metadata['summary'] = trim($assembledMarkdown);
                $metadata['summary_sections'] = $summarySections;
                $transcription->metadata = $metadata;
                if ($templateId) {
                    $transcription->summary_template_id = $templateId;
                }
                $transcription->save();
            }

            return response()->json([
                'success' => true,
                'summary' => trim($assembledMarkdown),
            ]);
        } catch (\Exception $e) {
            Log::error('Summarization error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return response()->json([
                'success' => false,
                'error' => 'Fehler bei der Zusammenfassung: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Erstellt den vollständigen Transkript-Text aus den Segmenten.
     */
    private function getTranscriptText(array $segments, bool $preview = false): string
    {
        if ($preview) {
            return $this->getReducedTranscriptSample($segments);
        }

        $textPayload = '';
        $currentSpeaker = null;
        $currentText = '';

        foreach ($segments as $index => $segment) {
            $segSpeaker = $segment['speaker'] ?? 'Unbekannt';
            $safeText = $segment['text'] ?? '';
            if ($index === 0 || $segSpeaker !== $currentSpeaker || (isset($segments[$index - 1]) && ($segment['start'] - $segments[$index - 1]['end']) > 10)) {
                if ($currentSpeaker) {
                    $textPayload .= "{$currentSpeaker}: ".trim($currentText)."\n";
                }
                $currentSpeaker = $segSpeaker;
                $currentText = $safeText.' ';
            } else {
                $currentText .= $safeText.' ';
            }
        }
        if ($currentSpeaker) {
            $textPayload .= "{$currentSpeaker}: ".trim($currentText)."\n";
        }

        return $textPayload;
    }

    /**
     * Extrahiert repräsentative Segmente aus dem Anfang, der Mitte und dem Ende eines Transkripts
     * und begrenzt die Länge auf ein Token-Budget.
     */
    private function getReducedTranscriptSample(array $segments, int $maxTokens = 2000): string
    {
        $maxChars = $maxTokens * 4;
        $budget = (int) ($maxChars / 3);

        if (empty($segments)) {
            return '';
        }

        $formatSegment = function (array $seg): string {
            $speaker = $seg['speaker'] ?? 'Unbekannt';
            $text = $seg['text'] ?? '';

            return "{$speaker}: {$text}\n";
        };

        // Anfang
        $beg = '';
        $begIdx = 0;
        while ($begIdx < count($segments) && strlen($beg) < $budget) {
            $beg .= $formatSegment($segments[$begIdx]);
            $begIdx++;
        }

        // Ende
        $end = '';
        $endIdx = count($segments) - 1;
        while ($endIdx >= $begIdx && strlen($end) < $budget) {
            $end = $formatSegment($segments[$endIdx]).$end;
            $endIdx--;
        }

        // Mitte
        $mid = '';
        if ($endIdx > $begIdx) {
            $midStart = (int) (($begIdx + $endIdx) / 2);
            $mid .= $formatSegment($segments[$midStart]);

            $left = $midStart - 1;
            $right = $midStart + 1;

            while (strlen($mid) < $budget && ($left >= $begIdx || $right <= $endIdx)) {
                if ($left >= $begIdx) {
                    $mid = $formatSegment($segments[$left]).$mid;
                    $left--;
                }
                if (strlen($mid) < $budget && $right <= $endIdx) {
                    $mid .= $formatSegment($segments[$right]);
                    $right++;
                }
            }
        }

        $parts = array_filter([$beg, $mid, $end]);

        return implode("\n... [Ausschnitt] ...\n\n", $parts);
    }

    /**
     * Bereinigt Markdown-Codeblocks und parst das zurückgegebene JSON.
     */
    private function cleanAndParseJson(string $text): array
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $lines = explode("\n", $text);
            if (str_starts_with(trim($lines[0]), '```')) {
                array_shift($lines);
            }
            if (str_ends_with(trim(end($lines)), '```')) {
                array_pop($lines);
            }
            $text = implode("\n", $lines);
        }
        $text = trim($text);

        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        $startPos = strpos($text, '{');
        $endPos = strrpos($text, '}');
        if ($startPos !== false && $endPos !== false) {
            $jsonCandidate = substr($text, $startPos, $endPos - $startPos + 1);
            $decoded = json_decode($jsonCandidate, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        throw new \Exception('Konnte die JSON-Antwort der KI nicht parsen: '.substr($text, 0, 500));
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

    /**
     * List all transcription templates for the current user,
     * including default system templates (user_id = null).
     */
    public function listTemplates(SummaryTemplateRegistry $registry): \Illuminate\Http\JsonResponse
    {
        try {
            $templates = $registry->listAll();

            return response()->json([
                'success' => true,
                'templates' => $templates,
            ]);
        } catch (\Exception $e) {
            Log::error('List templates error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Laden der Vorlagen: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create or update a transcription template.
     */
    public function saveTemplate(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'id' => 'nullable|string|max:255',
                'name' => 'required|string|max:255',
                'structure' => 'required|array',
            ]);

            if (! empty($validatedData['id'])) {
                // Update existing
                $template = SummaryTemplate::where('id', $validatedData['id'])
                    ->where('user_id', Auth::id())
                    ->firstOrFail();
                $template->update([
                    'name' => $validatedData['name'],
                    'sections' => $validatedData['structure'],
                ]);
            } else {
                // Create new
                $slug = \Illuminate\Support\Str::slug($validatedData['name']);
                if (SummaryTemplate::where('id', $slug)->exists()) {
                    $slug .= '-'.substr(md5(uniqid()), 0, 6);
                }

                $template = SummaryTemplate::create([
                    'id' => $slug,
                    'user_id' => Auth::id(),
                    'name' => $validatedData['name'],
                    'sections' => $validatedData['structure'],
                    'is_builtin' => false,
                    'version' => 1,
                ]);
            }

            return response()->json([
                'success' => true,
                'template' => $template,
            ]);
        } catch (\Exception $e) {
            Log::error('Save template error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Speichern der Vorlage: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a custom transcription template.
     */
    public function deleteTemplate(string $id): \Illuminate\Http\JsonResponse
    {
        try {
            $template = SummaryTemplate::where('id', $id)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $template->delete();

            return response()->json([
                'success' => true,
                'message' => 'Vorlage erfolgreich gelöscht.',
            ]);
        } catch (\Exception $e) {
            Log::error('Delete template error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Löschen der Vorlage: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * List all custom transcription format presets for the current user.
     */
    public function listCustomFormats(): \Illuminate\Http\JsonResponse
    {
        try {
            $formats = CustomTranscriptFormat::where('user_id', Auth::id())
                ->orderBy('name', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'formats' => $formats,
            ]);
        } catch (\Exception $e) {
            Log::error('List custom formats error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Laden der Formate: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create or update a custom transcript export format preset.
     */
    public function saveCustomFormat(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'id' => 'nullable|integer',
                'name' => 'required|string|max:255',
                'speakers' => 'required|boolean',
                'timestamps' => 'required|boolean',
                'avatars' => 'required|boolean',
                'bubbles' => 'required|boolean',
                'anonymize' => 'required|boolean',
                'order' => 'required|string|in:chronological,speaker',
            ]);

            if (! empty($validatedData['id'])) {
                // Update existing
                $format = CustomTranscriptFormat::where('id', $validatedData['id'])
                    ->where('user_id', Auth::id())
                    ->firstOrFail();
                $format->update($validatedData);
            } else {
                // Create new
                $format = CustomTranscriptFormat::create(array_merge($validatedData, [
                    'user_id' => Auth::id(),
                ]));
            }

            return response()->json([
                'success' => true,
                'format' => $format,
            ]);
        } catch (\Exception $e) {
            Log::error('Save custom format error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Speichern des Formats: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a custom transcript export format preset.
     */
    public function deleteCustomFormat(string $id): \Illuminate\Http\JsonResponse
    {
        try {
            $format = CustomTranscriptFormat::where('id', (int) $id)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $format->delete();

            return response()->json([
                'success' => true,
                'message' => 'Format erfolgreich gelöscht.',
            ]);
        } catch (\Exception $e) {
            Log::error('Delete custom format error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Löschen des Formats: '.$e->getMessage(),
            ], 500);
        }
    }
}
