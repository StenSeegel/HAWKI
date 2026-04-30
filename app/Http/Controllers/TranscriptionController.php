<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateTranscriptionTitle;
use App\Models\Transcription;
use App\Services\Transcription\TranscriptionService;
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

            // POST-PROCESSING: Diarization
            if (! empty($result['segments'])) {
                $result['segments'] = $this->transcriptionService->diarizeSegments($result['segments']);
            }

            return response()->json([
                'success' => true,
                'text' => $result['text'],
                'segments' => $result['segments'] ?? [],
                'language' => $result['language'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Transcription error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Fehler bei der Transkription: '.$e->getMessage(),
            ], 500);
        }
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
    public function saveConfiguration(\Illuminate\Http\Request $request, \App\Services\SettingsService $settingsService)
    {
        try {
            $validated = $request->validate([
                'provider' => 'required|string',
                'model' => 'required|string',
            ]);

            // Save to database via SettingsService
            $settingsService->set('hawki_transcription_provider', $validated['provider']);
            $settingsService->set('hawki_transcription_model', $validated['model']);

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
                'transcript_text' => 'required|string',
                'segments' => 'nullable|array',
                'words' => 'nullable|array',
                'language' => 'nullable|string|max:10',
                'duration' => 'nullable|integer',
                'model_used' => 'nullable|string',
                'provider' => 'nullable|string',
                'original_filename' => 'nullable|string',
                'file_size' => 'nullable|integer',
                'metadata' => 'nullable|array',
            ]);

            $transcription = DB::transaction(function () use ($validatedData) {
                $transcription = Transcription::create([
                    'user_id' => Auth::id(),
                    'language' => $validatedData['language'] ?? null,
                    'user_locale' => app()->getLocale(), // Capture user's locale at request time
                    'duration' => $validatedData['duration'] ?? null,
                    'model_used' => $validatedData['model_used'] ?? null,
                    'provider' => $validatedData['provider'] ?? null,
                    'original_filename' => $validatedData['original_filename'] ?? null,
                    'file_size' => $validatedData['file_size'] ?? null,
                    'metadata' => $validatedData['metadata'] ?? null,
                ]);

                $transcription->textData()->create([
                    'transcript_text' => $validatedData['transcript_text'],
                    'segments' => $validatedData['segments'] ?? null,
                    'words' => $validatedData['words'] ?? null,
                ]);

                return $transcription;
            });

            // Trigger automatic title generation (async in queue)
            GenerateTranscriptionTitle::dispatch($transcription);

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
            $transcriptionArray['transcript_text'] = $transcription->textData?->transcript_text ?? '';
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
            if (!empty($validatedData['transcription_slug'])) {
                $transcription = \App\Models\Transcription::where('slug', $validatedData['transcription_slug'])->first();
                
                if ($transcription && empty($validatedData['force_regenerate'])) {
                    $metadata = $transcription->metadata ?? [];
                    if (!empty($metadata['summary'])) {
                        return response()->json([
                            'success' => true,
                            'summary' => $metadata['summary'],
                        ]);
                    }
                }
            }

            if (!empty($validatedData['check_only'])) {
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
                    'Payload' => $payload
                ]);
            } catch (\Exception $e) {
                // Ignoriere fehlende Provider-Konfiguration für Logging
            }

            $response = $aiService->sendRequest($payload);

            if (isset($providerConfig)) {
                Log::info($providerConfig->getId() . ' API-Verbindung erfolgreich', [
                    'url' => $providerConfig->getApiUrl(),
                    'status' => 200
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

            if ($transcription && !empty($summary)) {
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
}
