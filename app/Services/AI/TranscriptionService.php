<?php

namespace App\Services\AI;

use App\Models\AiModel;
use App\Models\ApiProvider;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TranscriptionService
{
    protected string $apiKey;

    protected string $baseUrl;

    protected string $model;

    protected ?ApiProvider $provider = null;

    protected ?AiModel $aiModel = null;
    
    /**
     * Konstruktor - lädt OpenAI Konfiguration
     */
    public function __construct()
    {
        $this->loadConfiguration();
    }

    /**
     * Lädt die Konfiguration: Zuerst Datenbank, dann Config/Env als Fallback
     */
    protected function loadConfiguration(): void
    {
        try {
            // Versuche Konfiguration aus der Datenbank zu laden
            $this->provider = ApiProvider::where('unique_name', 'openai')
                ->where('is_active', true)
                ->first();

            if ($this->provider) {
                // Provider in DB gefunden
                $this->baseUrl = rtrim($this->provider->base_url, '/');
                $this->apiKey = $this->provider->api_key ?? '';

                // Suche Whisper/Transcription Modell für diesen Provider
                // Wir priorisieren Modelle, die 'whisper' im Namen haben
                $this->aiModel = AiModel::where('provider_id', $this->provider->id)
                    ->where('is_active', true)
                    ->where('model_id', 'like', '%whisper%')
                    ->first();

                if (!$this->aiModel) {
                    $this->aiModel = AiModel::where('provider_id', $this->provider->id)
                        ->where('is_active', true)
                        ->where('model_id', 'like', '%transcribe%')
                        ->first();
                }

                if ($this->aiModel) {
                    $this->model = $this->aiModel->model_id;
                } else {
                    // Kein Modell in DB, nutze Fallback
                    $this->model = config('hawki.transcription.whisper_model', 'gpt-4o-transcribe');
                    Log::info('TranscriptionService: Kein Whisper-Modell in DB gefunden, nutze Fallback', [
                        'model' => $this->model,
                    ]);
                }

                Log::info('TranscriptionService: Konfiguration aus Datenbank geladen', [
                    'provider' => $this->provider->provider_name,
                    'model' => $this->model,
                    'base_url' => $this->baseUrl,
                ]);
            } else {
                // Kein Provider in DB, nutze Fallbacks aus Config/Env
                Log::info('TranscriptionService: Kein OpenAI-Provider in DB gefunden, nutze Config/Env Fallback');
                $this->loadFallbackConfiguration();
            }
        } catch (Exception $e) {
            Log::warning('TranscriptionService: Datenbank-Zugriff fehlgeschlagen (evtl. APP_KEY Mismatch bei verschlüsselten Feldern).', [
                'error' => $e->getMessage(),
                'hint' => 'Wenn "The MAC is invalid" erscheint, passt der APP_KEY nicht zu den verschlüsselten Daten in der DB.'
            ]);
            $this->loadFallbackConfiguration();
        }

        // Validierung
        if (empty($this->apiKey)) {
            Log::error('TranscriptionService: OPENAI_API_KEY fehlt in der Konfiguration.');
            throw new RuntimeException('OpenAI API Key ist nicht konfiguriert. Bitte erstellen Sie einen OpenAI-Provider in der Datenbank oder setzen Sie OPENAI_API_KEY in config/services.php.');
        }

        // Environment Validation nur wenn nicht in Test-Umgebung
        if (app()->environment() !== 'testing') {
            $this->validateEnvironment();
        }
    }

    /**
     * Lädt Fallback-Konfiguration aus Config/Env
     */
    protected function loadFallbackConfiguration(): void
    {
        $this->apiKey = config('services.openai.api_key', env('OPENAI_API_KEY', ''));
        $this->baseUrl = config('hawki.transcription.openai_base_url', 'https://api.openai.com/v1');
        $this->model = config('hawki.transcription.whisper_model', 'gpt-4o-transcribe');

        Log::info('TranscriptionService: Fallback-Konfiguration geladen', [
            'provider' => 'OpenAI (Config/Env Fallback)',
            'model' => $this->model,
            'base_url' => $this->baseUrl,
            'has_api_key' => ! empty($this->apiKey),
        ]);
    }

    /**
     * Überprüft, ob die Umgebung korrekt konfiguriert ist
     */
    protected function validateEnvironment(): void
    {
        try {
            // Teste OpenAI API-Verbindung mit einem einfachen Models-Request
            $response = Http::timeout(5)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$this->apiKey,
                ])
                ->get($this->baseUrl.'/models');

            if (! $response->successful()) {
                Log::warning('OpenAI API nicht erreichbar', [
                    'status' => $response->status(),
                    'message' => $response->body(),
                ]);
            } else {
                Log::info('OpenAI API-Verbindung erfolgreich', [
                    'url' => $this->baseUrl,
                    'status' => $response->status(),
                ]);
            }
        } catch (Exception $e) {
            Log::warning('OpenAI API Verbindungstest fehlgeschlagen: '.$e->getMessage());
        }
    }


    /**
     * Transkribiert eine Audiodatei mit OpenAI Whisper API
     */
    public function transcribeAudio($audioFile, $language = null)
    {
        try {
            // Temporärer Pfad für die Audiodatei
            $tempDir = storage_path('app/temp');
            $tempPath = $tempDir.'/'.uniqid().'.'.$audioFile->getClientOriginalExtension();

            // Stelle sicher, dass das temp-Verzeichnis existiert
            if (! file_exists($tempDir)) {
                if (! mkdir($tempDir, 0755, true) && ! is_dir($tempDir)) {
                    throw new RuntimeException(sprintf('Directory "%s" was not created', $tempDir));
                }
            }

            // Datei temporär speichern
            $audioFile->move($tempDir, basename($tempPath));

            Log::info('Audio-Datei für Transkription vorbereitet', [
                'original_name' => $audioFile->getClientOriginalName(),
                'temp_path' => $tempPath,
                'size' => filesize($tempPath),
            ]);

            // Transkription durchführen mit OpenAI Whisper API
            $result = $this->processAudioWithOpenAI($tempPath, $language);

            // Temporäre Datei löschen
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            return $result;
        } catch (Exception $e) {
            // Cleanup bei Fehler
            if (isset($tempPath) && file_exists($tempPath)) {
                unlink($tempPath);
            }

            Log::error('Transcription error: '.$e->getMessage(), [
                'file' => $audioFile->getClientOriginalName(),
                'provider' => 'OpenAI',
                'model' => $this->model,
            ]);
            throw $e;
        }
    }

    /**
     * Verarbeitet die Audiodatei mit OpenAI Whisper API
     *
     * @throws Exception
     */
    protected function processAudioWithOpenAI(string $audioPath, ?string $language): array
    {
        try {
            Log::info('Sende Transkriptions-Anfrage an OpenAI', [
                'model' => $this->model,
                'base_url' => $this->baseUrl,
                'audio_size' => filesize($audioPath),
                'language' => $language ?? 'auto',
            ]);

            $payload = array_filter([
                'model' => $this->model,
                'language' => $language,
                'response_format' => 'verbose_json',
            ]);

            $response = Http::timeout(600) // 10 Minuten Timeout für lange Audiodateien
                ->withHeaders([
                    'Authorization' => 'Bearer '.$this->apiKey,
                ])
                ->attach(
                    'file',
                    file_get_contents($audioPath),
                    basename($audioPath)
                )
                ->post($this->baseUrl.'/audio/transcriptions', $payload);

            // FALLBACK: Wenn verbose_json nicht unterstützt wird, versuche es mit normalem json
            if (! $response->successful() && $response->status() === 400 && str_contains($response->body(), 'verbose_json')) {
                Log::warning('TranscriptionService: verbose_json nicht unterstützt, Fallback auf json', [
                    'model' => $this->model,
                    'error' => $response->body()
                ]);
                
                $payload['response_format'] = 'json';
                $response = Http::timeout(600)
                    ->withHeaders([
                        'Authorization' => 'Bearer '.$this->apiKey,
                    ])
                    ->attach(
                        'file',
                        file_get_contents($audioPath),
                        basename($audioPath)
                    )
                    ->post($this->baseUrl.'/audio/transcriptions', $payload);
            }

            if (! $response->successful()) {
                $errorBody = $response->body();
                Log::error('OpenAI API-Fehler', [
                    'status' => $response->status(),
                    'body' => $errorBody,
                ]);
                throw new Exception("OpenAI API-Fehler (Status: {$response->status()}): {$errorBody}");
            }

            $result = $response->json();

            Log::info('Transkription erfolgreich', [
                'text_length' => strlen($result['text'] ?? ''),
                'duration' => $result['duration'] ?? null,
                'language' => $result['language'] ?? null,
            ]);

            // Normalisiere die Antwort
            return $this->normalizeResponse($result);
        } catch (Exception $e) {
            Log::error('Audio processing error: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Normalisiert die OpenAI Whisper API Antwort
     */
    protected function normalizeResponse(array $result): array
    {
        return [
            'text' => $result['text'] ?? '',
            'segments' => $result['segments'] ?? [],
            'words' => $result['words'] ?? [],
            'language' => $result['language'] ?? null,
            'duration' => $result['duration'] ?? null,
            'model' => $this->model,
            'provider' => 'OpenAI',
            'usage' => $result['usage'] ?? null,
        ];
    }


    /**
     * Prüft den Status einer Transkription
     * (Für zukünftige async-Implementierung)
     */
    public function getTranscriptionStatus($jobId)
    {
        try {
            // Aktuell synchrone Verarbeitung - Status ist entweder completed oder failed
            return [
                'status' => 'completed',
                'progress' => 100,
                'job_id' => $jobId,
                'provider' => $this->provider ? $this->provider->provider_name : 'OpenAI',
                'model' => $this->model,
            ];
        } catch (Exception $e) {
            Log::error('Status check error: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Gibt Informationen über die aktuelle Konfiguration zurück
     */
    public function getConfiguration(): array
    {
        return [
            'provider' => [
                'id' => $this->provider?->id,
                'name' => $this->provider ? $this->provider->provider_name : 'OpenAI (Fallback)',
                'unique_name' => $this->provider?->unique_name ?? 'openai-fallback',
                'base_url' => $this->baseUrl,
                'is_active' => $this->provider?->is_active ?? true,
                'source' => $this->provider ? 'database' : 'config/env',
            ],
            'model' => [
                'id' => $this->aiModel?->id,
                'model_id' => $this->model,
                'label' => $this->aiModel?->label ?? 'OpenAI Whisper',
                'is_active' => $this->aiModel?->is_active ?? true,
                'source' => $this->aiModel ? 'database' : 'config/env',
            ],
        ];
    }

    /**
     * Testet die Verbindung zur OpenAI API
     */
    public function testConnection(): array
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$this->apiKey,
                ])
                ->get($this->baseUrl.'/models');

            if ($response->successful()) {
                $data = $response->json();

                // Filtere Whisper/Transkriptions-Modelle
                $transcriptionModels = collect($data['data'] ?? [])
                    ->filter(function ($model) {
                        return str_contains($model['id'], 'whisper') ||
                               str_contains($model['id'], 'transcribe');
                    })
                    ->pluck('id')
                    ->toArray();

                return [
                    'success' => true,
                    'message' => 'Verbindung erfolgreich',
                    'url' => $this->baseUrl,
                    'current_model' => $this->model,
                    'available_transcription_models' => $transcriptionModels,
                ];
            }

            return [
                'success' => false,
                'message' => 'Verbindung fehlgeschlagen',
                'status' => $response->status(),
                'url' => $this->baseUrl,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Fehler: '.$e->getMessage(),
                'url' => $this->baseUrl,
            ];
        }
    }
}