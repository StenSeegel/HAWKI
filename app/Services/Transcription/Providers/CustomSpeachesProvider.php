<?php

declare(strict_types=1);

namespace App\Services\Transcription\Providers;

use App\Services\Transcription\Contracts\TranscriptionProviderInterface;
use App\Services\Transcription\TranscriptionSettingsService;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CustomSpeachesProvider implements TranscriptionProviderInterface
{
    protected string $baseUrl;

    protected string $apiKey;

    protected string $model;

    protected string $diarizationModel;

    public function __construct(TranscriptionSettingsService $settingsService)
    {
        $this->baseUrl = rtrim((string) $settingsService->get('base_url', ''), '/');
        $this->apiKey = (string) $settingsService->get('api_key', '');
        $this->model = (string) $settingsService->get('model', '');
        $this->diarizationModel = (string) $settingsService->get('diarization_model', 'pyannote/speaker-diarization-community-1');

        if (empty($this->baseUrl) || empty($this->model)) {
            throw new RuntimeException('Custom Speaches Provider ist unvollständig konfiguriert.');
        }
    }

    public function getName(): string
    {
        return 'Custom Speaches';
    }

    public function transcribeAudio($audioFile, ?string $language = null): array
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

            Log::info('CustomSpeaches: Audio-Datei für Transkription vorbereitet', [
                'original_name' => $audioFile->getClientOriginalName(),
                'temp_path' => $tempPath,
            ]);

            $result = $this->processTranscription($tempPath, $language);

            if (! empty($result['segments']) && ! empty($this->diarizationModel)) {
                $result = $this->processDiarization($tempPath, $result);
            }

            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            return $result;
        } catch (Exception $e) {
            if (isset($tempPath) && file_exists($tempPath)) {
                unlink($tempPath);
            }

            Log::error('CustomSpeaches Transcription error: '.$e->getMessage(), [
                'file' => $audioFile->getClientOriginalName(),
                'model' => $this->model,
            ]);
            throw $e;
        }
    }

    protected function processTranscription(string $audioPath, ?string $language): array
    {
        Log::info('Sende Transkriptions-Anfrage an Custom Speaches', [
            'model' => $this->model,
            'base_url' => $this->baseUrl,
            'language' => $language ?? 'auto',
        ]);

        $payload = array_filter([
            'model' => $this->model,
            'language' => $language,
            'response_format' => 'verbose_json',
            'timestamp_granularities[]' => 'word',
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
            Log::error('Custom Speaches API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new Exception("Custom Speaches API-Fehler (Status: {$response->status()}): {$response->body()}");
        }

        $responseData = $response->json();

        return $this->normalizeResponse($responseData);
    }

    protected function processDiarization(string $audioPath, array $result): array
    {
        $segments = $result['segments'] ?? [];

        try {
            Log::info('Starte Audio-basierte Diarization bei Custom Speaches', ['audio_path' => $audioPath]);

            $response = Http::timeout(600)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$this->apiKey,
                ])
                ->attach(
                    'file',
                    file_get_contents($audioPath),
                    basename($audioPath)
                )
                ->post($this->baseUrl.'/audio/diarization', [
                    'model' => $this->diarizationModel,
                ]);

            if (! $response->successful()) {
                Log::warning('Diarization API error: '.$response->body());

                return $result;
            }

            $diarizationData = $response->json();
            $diarizationSegments = $diarizationData['segments'] ?? [];

            if (empty($diarizationSegments)) {
                return $result;
            }

            if (! empty($result['words'])) {
                $newSegments = [];
                $currentSegment = null;
                $currentSpeaker = null;

                foreach ($result['words'] as $word) {
                    $wStart = $word['start'];
                    $wEnd = $word['end'];
                    $wText = $word['word'];

                    $bestSpeaker = null;
                    $maxOverlap = 0;

                    foreach ($diarizationSegments as $diarSegment) {
                        $overlapStart = max($wStart, $diarSegment['start']);
                        $overlapEnd = min($wEnd, $diarSegment['end']);
                        $overlap = max(0, $overlapEnd - $overlapStart);

                        if ($overlap > $maxOverlap) {
                            $maxOverlap = $overlap;
                            $bestSpeaker = $diarSegment['speaker'];
                        }
                    }

                    if ($bestSpeaker) {
                        $speakerId = (int) str_replace('SPEAKER_', '', $bestSpeaker);
                        $mappedSpeaker = 'Sprecher '.($speakerId + 1);
                    } else {
                        $mappedSpeaker = 'Unbekannt';
                    }

                    if ($currentSegment === null || $currentSpeaker !== $mappedSpeaker || ($wStart - $currentSegment['end']) > 2.0) {
                        if ($currentSegment !== null) {
                            $newSegments[] = $currentSegment;
                        }
                        $currentSpeaker = $mappedSpeaker;
                        $currentSegment = [
                            'start' => $wStart,
                            'end' => $wEnd,
                            'text' => ltrim($wText),
                            'speaker' => $mappedSpeaker,
                            'words' => [$word],
                        ];
                    } else {
                        $currentSegment['end'] = $wEnd;
                        $currentSegment['text'] .= $wText;
                        $currentSegment['words'][] = $word;
                    }
                }

                if ($currentSegment !== null) {
                    $newSegments[] = $currentSegment;
                }

                $result['segments'] = $newSegments;

                return $result;
            }

            foreach ($segments as &$transSegment) {
                $transStart = $transSegment['start'];
                $transEnd = $transSegment['end'];

                $bestSpeaker = null;
                $maxOverlap = 0;

                foreach ($diarizationSegments as $diarSegment) {
                    $diarStart = $diarSegment['start'];
                    $diarEnd = $diarSegment['end'];

                    $overlapStart = max($transStart, $diarStart);
                    $overlapEnd = min($transEnd, $diarEnd);
                    $overlap = max(0, $overlapEnd - $overlapStart);

                    if ($overlap > $maxOverlap) {
                        $maxOverlap = $overlap;
                        $bestSpeaker = $diarSegment['speaker'];
                    }
                }

                if ($bestSpeaker) {
                    $speakerId = (int) str_replace('SPEAKER_', '', $bestSpeaker);
                    $transSegment['speaker'] = 'Sprecher '.($speakerId + 1);
                } else {
                    $transSegment['speaker'] = 'Unbekannt';
                }
            }

            $result['segments'] = $segments;

            return $result;
        } catch (Exception $e) {
            Log::warning('Diarization failed: '.$e->getMessage());

            return $result;
        }
    }

    protected function normalizeResponse(array $result): array
    {
        return [
            'text' => $result['text'] ?? '',
            'segments' => $result['segments'] ?? [],
            'words' => $result['words'] ?? [],
            'language' => $result['language'] ?? 'unknown',
            'duration' => $result['duration'] ?? 0,
        ];
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
                'name' => 'Custom Speaches',
                'unique_name' => 'custom_speaches',
                'base_url' => $this->baseUrl,
                'is_active' => true,
                'source' => 'app_settings',
            ],
            'model' => [
                'id' => null,
                'model_id' => $this->model,
                'label' => $this->model,
                'is_active' => true,
                'source' => 'app_settings',
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
