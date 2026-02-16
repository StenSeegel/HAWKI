<?php

namespace App\Services\AI;

use App\Models\ApiProvider;
use App\Models\AiModel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Exception;
use RuntimeException;

class TranscriptionService
{
    protected ?string $baseUrl = null;
    protected ?string $modelId = null;
    protected ?ApiProvider $provider = null;
    protected ?AiModel $model = null;
    
    /**
     * Konstruktor - lädt Provider und Modell aus der Datenbank
     */
    public function __construct()
    {
        $this->loadConfiguration();
    }

    /**
     * Lädt die Konfiguration aus der Datenbank
     */
    protected function loadConfiguration(): void
    {
        try {
            // Suche den Provider "ollama-jlu" in der Datenbank
            $this->provider = ApiProvider::where('unique_name', 'ollama-jlu')
                ->where('is_active', true)
                ->first();
            
            if (!$this->provider) {
                throw new RuntimeException(
                    "Provider 'ollama-jlu' nicht gefunden oder nicht aktiv. " .
                    "Bitte überprüfen Sie die api_providers Tabelle."
                );
            }

            // Suche das Whisper-Modell für diesen Provider
            $this->model = AiModel::where('provider_id', $this->provider->id)
                ->where('model_id', 'karanchopda333/whisper:latest')
                ->where('is_active', true)
                ->first();
            
            if (!$this->model) {
                throw new RuntimeException(
                    "Whisper-Modell 'karanchopda333/whisper:latest' nicht gefunden oder nicht aktiv für Provider 'ollama-jlu'. " .
                    "Bitte überprüfen Sie die ai_models Tabelle."
                );
            }

            $this->baseUrl = rtrim($this->provider->base_url, '/');
            $this->modelId = $this->model->model_id;

            Log::info('TranscriptionService konfiguriert', [
                'provider' => $this->provider->provider_name,
                'model' => $this->modelId,
                'base_url' => $this->baseUrl
            ]);

            $this->validateEnvironment();
        } catch (Exception $e) {
            Log::error('Fehler beim Laden der Transkriptions-Konfiguration: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Überprüft, ob die Umgebung korrekt konfiguriert ist
     */
    protected function validateEnvironment(): void
    {
        if (!$this->baseUrl) {
            throw new RuntimeException("Keine base_url für Provider konfiguriert");
        }

        try {
            // Teste Ollama API-Verbindung
            $response = Http::timeout(5)->get($this->baseUrl . '/api/tags');
            
            if (!$response->successful()) {
                throw new RuntimeException(
                    "Ollama API nicht erreichbar unter: {$this->baseUrl} " .
                    "(Status: {$response->status()})"
                );
            }

            Log::info('Ollama API-Verbindung erfolgreich', [
                'url' => $this->baseUrl,
                'status' => $response->status()
            ]);
        } catch (Exception $e) {
            Log::error('Environment validation error: ' . $e->getMessage());
            throw new RuntimeException("Ollama API nicht verfügbar: " . $e->getMessage());
        }
    }


    /**
     * Transkribiert eine Audiodatei mit Ollama Whisper
     */
    public function transcribeAudio($audioFile, $language = null)
    {
        try {
            // Temporärer Pfad für die Audiodatei
            $tempPath = storage_path('app/temp/' . uniqid() . '.' . $audioFile->getClientOriginalExtension());
            
            // Stelle sicher, dass das temp-Verzeichnis existiert
            if (!file_exists(dirname($tempPath))) {
                mkdir(dirname($tempPath), 0755, true);
            }

            // Datei temporär speichern
            $audioFile->move(dirname($tempPath), basename($tempPath));

            Log::info('Audio-Datei für Transkription vorbereitet', [
                'original_name' => $audioFile->getClientOriginalName(),
                'temp_path' => $tempPath,
                'size' => filesize($tempPath)
            ]);

            // Transkription durchführen mit Ollama Whisper
            $result = $this->processAudioWithOllama($tempPath, $language);

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
            
            Log::error('Transcription error: ' . $e->getMessage(), [
                'file' => $audioFile->getClientOriginalName(),
                'provider' => $this->provider?->provider_name ?? 'unknown',
                'model' => $this->modelId ?? 'unknown'
            ]);
            throw $e;
        }
    }

    /**
     * Verarbeitet die Audiodatei mit Ollama Whisper
     * Ollama unterstützt verschiedene Ansätze für Whisper-Transkription
     */
    protected function processAudioWithOllama(string $audioPath, ?string $language): array
    {
        try {
            // Lese die Audio-Datei als Base64
            $audioContent = file_get_contents($audioPath);
            $audioBase64 = base64_encode($audioContent);

            Log::info('Sende Transkriptions-Anfrage an Ollama', [
                'model' => $this->modelId,
                'base_url' => $this->baseUrl,
                'audio_size' => strlen($audioContent),
                'language' => $language ?? 'auto'
            ]);

            // Ollama Whisper API-Anfrage
            // Verschiedene Ansätze möglich, abhängig von der Ollama-Version
            $response = $this->sendOllamaWhisperRequest($audioBase64, $language);

            if (!$response->successful()) {
                $errorBody = $response->body();
                Log::error('Ollama API-Fehler', [
                    'status' => $response->status(),
                    'body' => $errorBody
                ]);
                throw new Exception("Ollama API-Fehler (Status: {$response->status()}): {$errorBody}");
            }

            $result = $response->json();

            Log::info('Transkription erfolgreich', [
                'text_length' => strlen($result['text'] ?? $result['response'] ?? '')
            ]);

            // Normalisiere die Antwort
            return $this->normalizeResponse($result);
        } catch (Exception $e) {
            Log::error('Audio processing error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Sendet die Whisper-Anfrage an Ollama
     * Unterstützt verschiedene Ollama-Whisper-Integrationen
     */
    protected function sendOllamaWhisperRequest(string $audioBase64, ?string $language)
    {
        // Versuche zuerst den Standard Ollama-Ansatz für Whisper
        // Bei Ollama wird Whisper als reguläres Modell behandelt
        try {
            $response = Http::timeout(120)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($this->baseUrl . '/api/generate', [
                    'model' => $this->modelId,
                    'prompt' => 'Transcribe this audio file.',
                    'images' => [$audioBase64], // Ollama verwendet 'images' auch für Audio
                    'stream' => false,
                    'options' => array_filter([
                        'language' => $language,
                    ])
                ]);

            // Wenn erfolgreich, verwende diese Methode
            if ($response->successful()) {
                return $response;
            }

            // Alternative: Versuche den Chat-Endpoint
            Log::info('Generate-Endpoint fehlgeschlagen, versuche Chat-Endpoint');
            return Http::timeout(120)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($this->baseUrl . '/api/chat', [
                    'model' => $this->modelId,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => 'Transcribe this audio file.',
                            'images' => [$audioBase64]
                        ]
                    ],
                    'stream' => false,
                    'options' => array_filter([
                        'language' => $language,
                    ])
                ]);
        } catch (Exception $e) {
            Log::error('Fehler beim Senden der Ollama-Anfrage: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Normalisiert die verschiedenen möglichen Antwortformate von Ollama
     */
    protected function normalizeResponse(array $result): array
    {
        // Ollama kann verschiedene Antwortformate zurückgeben
        $text = $result['text'] ?? 
                $result['response'] ?? 
                ($result['message']['content'] ?? '');

        return [
            'text' => $text,
            'segments' => $result['segments'] ?? [],
            'language' => $result['language'] ?? null,
            'duration' => $result['duration'] ?? null,
            'model' => $this->modelId,
            'provider' => $this->provider->provider_name
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
                'provider' => $this->provider?->provider_name ?? 'unknown',
                'model' => $this->modelId ?? 'unknown'
            ];
        } catch (Exception $e) {
            Log::error('Status check error: ' . $e->getMessage());
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
                'name' => $this->provider?->provider_name,
                'unique_name' => $this->provider?->unique_name,
                'base_url' => $this->baseUrl,
                'is_active' => $this->provider?->is_active
            ],
            'model' => [
                'id' => $this->model?->id,
                'model_id' => $this->modelId,
                'label' => $this->model?->label,
                'is_active' => $this->model?->is_active
            ]
        ];
    }

    /**
     * Testet die Verbindung zum Ollama-Server
     */
    public function testConnection(): array
    {
        try {
            $response = Http::timeout(5)->get($this->baseUrl . '/api/tags');
            
            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'message' => 'Verbindung erfolgreich',
                    'url' => $this->baseUrl,
                    'models' => $data['models'] ?? []
                ];
            }

            return [
                'success' => false,
                'message' => 'Verbindung fehlgeschlagen',
                'status' => $response->status(),
                'url' => $this->baseUrl
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Fehler: ' . $e->getMessage(),
                'url' => $this->baseUrl
            ];
        }
    }
}
