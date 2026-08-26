<?php

declare(strict_types=1);

namespace App\Services\Transcription\Providers;

use App\Services\AI\Config\AiConfigService;
use App\Services\Transcription\Contracts\TranscriptionProviderInterface;
use App\Services\Transcription\TranscriptionSettingsService;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenAiTranscriptionProvider implements TranscriptionProviderInterface
{
    protected string $baseUrl;

    protected string $apiKey;

    protected string $model;

    protected ?array $providerData = null;

    protected ?string $providerUniqueName = null;

    public function __construct(TranscriptionSettingsService $settingsService)
    {
        $this->loadConfiguration($settingsService);
    }

    protected function loadConfiguration(TranscriptionSettingsService $settingsService): void
    {
        $aiConfigService = app(AiConfigService::class);
        $configuredProvider = $settingsService->get('provider');
        $configuredModel = $settingsService->get('model');

        try {
            $providers = $aiConfigService->getProviders();

            if ($configuredProvider && isset($providers[$configuredProvider])) {
                $pData = $providers[$configuredProvider];
                $adapter = $pData['adapter'] ?? '';
                if (($pData['active'] ?? true) && in_array($adapter, ['OpenAi', 'Responses'])) {
                    $this->providerData = $pData;
                    $this->providerUniqueName = $configuredProvider;
                }
            }

            if (! $this->providerData) {
                throw new \RuntimeException('Der ausgewählte OpenAI Provider ist ungültig oder inaktiv.');
            }

            if ($this->providerData) {
                $baseUrl = $this->providerData['base_url'] ?? $this->providerData['api_url'];
                $this->baseUrl = preg_replace('#/(?:chat/completions|responses).*$#', '', rtrim($baseUrl, '/'));
                $this->apiKey = $this->providerData['api_key'] ?? '';

                $models = collect($this->providerData['models'])->filter(fn ($m) => ($m['active'] ?? true));

                if ($configuredModel && $models->first(fn ($m) => ($m['id'] ?? $m['model_id'] ?? $m['name'] ?? '') === $configuredModel)) {
                    $this->model = $configuredModel;
                } else {
                    throw new RuntimeException('Es ist kein gültiges Transkriptions-Modell in den globalen Einstellungen konfiguriert.');
                }

                Log::info('OpenAiTranscriptionProvider: configuration loaded', [
                    'provider' => $this->providerData['provider_name'],
                    'model' => $this->model,
                    'base_url' => $this->baseUrl,
                ]);
            } else {
                throw new RuntimeException('Kein gültiger Transkriptions-Provider gefunden.');
            }
        } catch (Exception $e) {
            Log::error('OpenAiTranscriptionProvider: failed to load configuration.', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        if (empty($this->apiKey)) {
            throw new RuntimeException('API Key ist nicht konfiguriert.');
        }
    }

    public function getName(): string
    {
        return $this->providerData ? ($this->providerData['provider_name'] ?? 'OpenAI') : 'OpenAI';
    }

    public function transcribeAudio($audioFile, ?string $language = null, ?callable $onProgress = null, bool $diarize = true): array
    {
        try {
            $tempDir = storage_path('app/temp');
            $tempPath = $tempDir.'/'.uniqid().'.'.$audioFile->getClientOriginalExtension();

            if (! file_exists($tempDir)) {
                if (! mkdir($tempDir, 0755, true) && ! is_dir($tempDir)) {
                    throw new RuntimeException(sprintf('Directory "%s" was not created', $tempDir));
                }
            }

            $audioFile->move($tempDir, basename($tempPath));

            Log::info('OpenAiTranscriptionProvider: audio file prepared for transcription', [
                'original_name' => $audioFile->getClientOriginalName(),
                'temp_path' => $tempPath,
            ]);

            $result = $this->processTranscription($tempPath, $language);

            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            return $result;
        } catch (Exception $e) {
            if (isset($tempPath) && file_exists($tempPath)) {
                unlink($tempPath);
            }

            Log::error('OpenAiTranscriptionProvider error: '.$e->getMessage(), [
                'file' => $audioFile->getClientOriginalName(),
                'model' => $this->model,
            ]);
            throw $e;
        }
    }

    public function transcribeAudioParallel(array $audioFiles, ?string $language = null): array
    {
        $results = [];
        foreach ($audioFiles as $key => $file) {
            if (is_string($file)) {
                $fileName = basename($file);
                $uploadedFile = new \Illuminate\Http\UploadedFile(
                    $file,
                    $fileName,
                    'audio/wav',
                    null,
                    true
                );
            } else {
                $uploadedFile = $file;
            }

            $results[$key] = $this->transcribeAudio($uploadedFile, $language, null, false);
        }

        return $results;
    }

    protected function processTranscription(string $audioPath, ?string $language): array
    {
        Log::info("Sending transcription request to {$this->getName()}", [
            'model' => $this->model,
            'base_url' => $this->baseUrl,
            'language' => $language ?? 'auto',
        ]);

        $isResponsesApi = ($this->providerData['adapter'] ?? '') === 'Responses';
        $responseFormat = $isResponsesApi ? 'json' : 'verbose_json';

        $payload = array_filter([
            'model' => $this->model,
            'language' => $language,
            'response_format' => $responseFormat,
        ]);

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

        if (! $response->successful()) {
            Log::error('OpenAI API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new Exception("API-Fehler (Status: {$response->status()}): {$response->body()}");
        }

        $responseData = $response->json();

        return $this->normalizeResponse($responseData);
    }

    protected function normalizeResponse(array $result): array
    {
        return [
            'text' => $result['text'] ?? '',
            'segments' => $result['segments'] ?? [],
            'words' => $result['words'] ?? [],
            'language' => $result['language'] ?? null,
            'duration' => $result['duration'] ?? null,
            'model' => $this->model,
            // Stable provider identifier (unique_name) from current runtime configuration.
            'provider' => $this->providerUniqueName,
            'provider_name' => $this->providerData['provider_name'] ?? null,
            'usage' => $result['usage'] ?? null,
        ];
    }

    public function diarizeAudio(string $audioPath, array $result, array $options = [], ?float $audioDurationSeconds = null): array
    {
        // OpenAI models natively supported here do not support diarization out-of-the-box.
        return $result;
    }

    public function analyzeSpeakers(string $audioPath, array $options = [], ?float $audioDurationSeconds = null): array
    {
        // OpenAI models natively supported here do not support speaker analysis out-of-the-box.
        return [];
    }

    public function getTranscriptionStatus($jobId): array
    {
        return [
            'status' => 'completed',
            'progress' => 100,
            'job_id' => $jobId,
            'provider' => $this->getName(),
            'model' => $this->model,
        ];
    }

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
