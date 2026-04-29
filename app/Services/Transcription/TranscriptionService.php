<?php

namespace App\Services\Transcription;

use App\Services\AI\AiService;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TranscriptionService
{
    protected string $apiKey;

    protected string $baseUrl;

    protected string $model;

    protected ?array $providerData = null;

    protected ?string $providerUniqueName = null;

    /**
     * Konstruktor - lädt OpenAI Konfiguration
     */
    public function __construct(
        protected AiService $aiService,
        protected \App\Services\AI\Config\AiConfigService $aiConfigService
    ) {
        $this->loadConfiguration();
    }

    /**
     * Lädt die Konfiguration: Zuerst Datenbank, dann Config/Env als Fallback
     */
    protected function loadConfiguration(): void
    {
        try {
            $providers = $this->aiConfigService->getProviders();
            $settingsService = app(\App\Services\SettingsService::class);

            $configuredProvider = $settingsService->get('hawki_transcription_provider');
            $configuredModel = $settingsService->get('hawki_transcription_model');

            if ($configuredProvider && isset($providers[$configuredProvider])) {
                $pData = $providers[$configuredProvider];
                $adapter = $pData['adapter'] ?? '';
                if (($pData['active'] ?? true) && in_array($adapter, ['OpenAi', 'Responses'])) {
                    $this->providerData = $pData;
                    $this->providerUniqueName = $configuredProvider;
                }
            }

            // Fallback to first available OpenAi provider if none configured or configured one is invalid
            if (! $this->providerData) {
                foreach ($providers as $uniqueName => $providerData) {
                    $adapter = $providerData['adapter'] ?? '';
                    if (($providerData['active'] ?? true) && in_array($adapter, ['OpenAi', 'Responses'])) {
                        $this->providerData = $providerData;
                        $this->providerUniqueName = $uniqueName;
                        break;
                    }
                }
            }

            if ($this->providerData) {
                // Use base_url if available, otherwise strip endpoint from api_url
                $baseUrl = $this->providerData['base_url'] ?? $this->providerData['api_url'];
                $this->baseUrl = preg_replace('#/(?:chat/completions|responses).*$#', '', rtrim($baseUrl, '/'));
                $this->apiKey = $this->providerData['api_key'] ?? '';

                $models = collect($this->providerData['models'])->filter(fn ($m) => ($m['active'] ?? true));

                // If a specific model is configured, try to use it
                if ($configuredModel && $models->first(fn ($m) => ($m['id'] ?? $m['model_id'] ?? $m['name'] ?? '') === $configuredModel)) {
                    $this->model = $configuredModel;
                } else {
                    throw new \RuntimeException('Es ist kein gültiges Transkriptions-Modell in den globalen Einstellungen konfiguriert.');
                }

                Log::info('TranscriptionService: Konfiguration via AiConfigService geladen', [
                    'provider' => $this->providerData['provider_name'],
                    'model' => $this->model,
                    'base_url' => $this->baseUrl,
                ]);
            } else {
                throw new \RuntimeException('Kein gültiger Transkriptions-Provider gefunden. Bitte konfigurieren Sie einen Provider für Transkriptionen in den Einstellungen.');
            }
        } catch (Exception $e) {
            Log::error('TranscriptionService: Konfiguration konnte nicht geladen werden.', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Validierung
        if (empty($this->apiKey)) {
            throw new \RuntimeException('API Key ist nicht konfiguriert. Bitte überprüfen Sie die Provider-Einstellungen.');
        }

        // Environment Validation nur wenn nicht in Test-Umgebung
        if (app()->environment() !== 'testing') {
            $this->validateEnvironment();
        }
    }

    /**
     * Überprüft, ob die Umgebung korrekt konfiguriert ist
     */
    protected function validateEnvironment(): void
    {
        $providerName = $this->providerData ? $this->providerData['provider_name'] : 'OpenAI';

        try {
            // Teste API-Verbindung mit einem einfachen Models-Request
            $response = Http::timeout(5)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$this->apiKey,
                ])
                ->get($this->baseUrl.'/models');

            if (! $response->successful()) {
                Log::warning("{$providerName} API nicht erreichbar", [
                    'status' => $response->status(),
                    'message' => $response->body(),
                ]);
            } else {
                Log::info("{$providerName} API-Verbindung erfolgreich", [
                    'url' => $this->baseUrl,
                    'status' => $response->status(),
                ]);
            }
        } catch (Exception $e) {
            Log::warning("{$providerName} API Verbindungstest fehlgeschlagen: ".$e->getMessage());
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

            $providerName = $this->providerData ? $this->providerData['provider_name'] : 'OpenAI';
            Log::error('Transcription error: '.$e->getMessage(), [
                'file' => $audioFile->getClientOriginalName(),
                'provider' => $providerName,
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
        $providerName = $this->providerData ? $this->providerData['provider_name'] : 'OpenAI';

        try {
            Log::info("Sende Transkriptions-Anfrage an {$providerName}", [
                'model' => $this->model,
                'base_url' => $this->baseUrl,
                'audio_size' => filesize($audioPath),
                'language' => $language ?? 'auto',
            ]);

            $isResponsesApi = ($this->providerData['adapter'] ?? '') === 'Responses';
            $responseFormat = $isResponsesApi ? 'json' : 'verbose_json';

            $payload = array_filter([
                'model' => $this->model,
                'language' => $language,
                'response_format' => $responseFormat,
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
                    'error' => $response->body(),
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
                Log::error("{$providerName} API-Fehler", [
                    'status' => $response->status(),
                    'body' => $errorBody,
                ]);
                throw new Exception("{$providerName} API-Fehler (Status: {$response->status()}): {$errorBody}");
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
                'provider' => $this->providerData ? $this->providerData['provider_name'] : 'OpenAI',
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
                'id' => null,
                'name' => $this->providerData ? $this->providerData['provider_name'] : 'OpenAI (Fallback)',
                'unique_name' => $this->providerUniqueName ?? 'openai-fallback',
                'base_url' => $this->baseUrl,
                'is_active' => $this->providerData['active'] ?? true,
                'source' => $this->providerData ? 'AiConfigService' : 'config/env',
            ],
            'model' => [
                'id' => null,
                'model_id' => $this->model,
                'label' => $this->model,
                'is_active' => true,
                'source' => $this->providerData ? 'AiConfigService' : 'config/env',
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

    /**
     * Identifies speakers in segments using GPT-4o
     */
    public function diarizeSegments(array $segments): array
    {
        try {
            // Group segments into larger chunks for GPT
            $chunks = [];
            $currentChunk = [];
            $currentLength = 0;

            foreach ($segments as $segment) {
                $currentChunk[] = $segment;
                $currentLength += strlen($segment['text']);
                // Increase chunk size significantly to 8000 chars for more context
                if ($currentLength > 8000 || count($currentChunk) >= 80) {
                    $chunks[] = $currentChunk;
                    $currentChunk = [];
                    $currentLength = 0;
                }
            }
            if (! empty($currentChunk)) {
                $chunks[] = $currentChunk;
            }

            $diarizedSegments = [];
            $speakerRegistry = [];

            foreach ($chunks as $chunk) {
                $registryJson = json_encode($speakerRegistry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

                $prompt = "You are an expert in speaker diarization. Below is a list of transcription segments with timestamps.
Your task is to identify which person is speaking in each segment.

CONTEXT: The user is seeing too many speakers. You must be extremely conservative and try to reuse existing speaker IDs whenever possible.

Existing Speaker Registry (profiles from previous chunks):
{$registryJson}

Instructions:
1. Assign a speaker ID (e.g., 'Sprecher 1', 'Sprecher 2') to each segment.
2. If the voice or context matches a speaker in the Registry, you MUST use their exact ID.
3. Only create a new ID if you are absolutely certain it's a different person.
4. For each speaker, provide/update a 'voice_profile' to maintain consistency (e.g., 'Male, deep voice, calm').

Return ONLY a JSON object:
{
  \"segments\": [{\"id\": 0, \"speaker\": \"Sprecher 1\"}, ...],
  \"updated_profiles\": {\"Sprecher 1\": \"...\"}
}

Segments for this chunk:
";
                foreach ($chunk as $index => $segment) {
                    $prompt .= "ID: {$index} | [{$segment['start']} - {$segment['end']}] | Text: {$segment['text']}\n";
                }

                $response = $this->aiService->sendRequest([
                    'model' => 'gpt-4o-mini',
                    'stream' => false,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => ['text' => 'You are a helpful assistant specialized in JSON speaker diarization. Always return valid JSON.'],
                        ],
                        [
                            'role' => 'user',
                            'content' => ['text' => $prompt],
                        ],
                    ],
                    'response_format' => ['type' => 'json_object'],
                ]);

                $content = $this->extractAiText($response);

                if (empty($content)) {
                    Log::warning('Diarization: empty response from AI for chunk, skipping.');
                    $diarizedSegments = array_merge($diarizedSegments, $chunk);

                    continue;
                }

                // Strip markdown if present
                $content = preg_replace('/^```json\s*|\s*```$/i', '', trim($content));

                $result = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::warning('Diarization: JSON decode failed: '.json_last_error_msg().' Content: '.substr($content, 0, 200));
                    $diarizedSegments = array_merge($diarizedSegments, $chunk);

                    continue;
                }

                // Update registry with new/updated profiles
                if (isset($result['updated_profiles']) && is_array($result['updated_profiles'])) {
                    foreach ($result['updated_profiles'] as $id => $profile) {
                        $speakerRegistry[$id] = $profile;
                    }
                }

                // Apply mappings to current chunk
                $mapping = $result['segments'] ?? [];
                if (is_array($mapping)) {
                    foreach ($mapping as $item) {
                        if (isset($item['id'], $item['speaker']) && isset($chunk[$item['id']])) {
                            $chunk[$item['id']]['speaker'] = $item['speaker'];
                        }
                    }
                }

                $diarizedSegments = array_merge($diarizedSegments, $chunk);
            }

            return $diarizedSegments;
        } catch (Exception $e) {
            Log::warning('Diarization failed: '.$e->getMessage());

            return $segments;
        }
    }

    /**
     * Helper to extract text from AiResponse
     */
    protected function extractAiText($response): string
    {
        if (is_object($response) && isset($response->content)) {
            $content = $response->content;
            if (is_array($content)) {
                if (isset($content['text'])) {
                    return $content['text'];
                }
                if (isset($content[0]['text'])) {
                    return $content[0]['text'];
                }
            }
            if (is_string($content)) {
                return $content;
            }
        }

        return '';
    }
}
