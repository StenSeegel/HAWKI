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

    protected ?int $minSpeakers = null;

    protected ?int $maxSpeakers = null;

    public function __construct(TranscriptionSettingsService $settingsService)
    {
        $this->baseUrl = rtrim((string) $settingsService->get('base_url', ''), '/');
        $this->apiKey = (string) $settingsService->get('api_key', '');
        $this->model = (string) $settingsService->get('model', '');
        $this->diarizationModel = (string) $settingsService->get('diarization_model', 'pyannote/speaker-diarization-community-1');

        $min = $settingsService->get('min_speakers');
        $this->minSpeakers = $min !== null ? (int) $min : null;
        $max = $settingsService->get('max_speakers');
        $this->maxSpeakers = $max !== null ? (int) $max : null;

        if (empty($this->baseUrl) || empty($this->model)) {
            throw new RuntimeException('Custom Speaches Provider ist unvollständig konfiguriert.');
        }
    }

    public function getName(): string
    {
        return 'Custom Speaches';
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

            Log::info('CustomSpeaches: Audio-Datei für Transkription vorbereitet', [
                'original_name' => $audioFile->getClientOriginalName(),
                'temp_path' => $tempPath,
            ]);

            $result = $this->processTranscription($tempPath, $language);

            if ($diarize && ! empty($result['segments']) && ! empty($this->diarizationModel)) {
                if (is_callable($onProgress)) {
                    $onProgress('diarizing');
                }
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

        $payload['file'] = new \CURLFile($audioPath, mime_content_type($audioPath), basename($audioPath));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl.'/audio/transcriptions');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer '.$this->apiKey,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            Log::error('Custom Speaches API curl error', ['error' => $curlError]);
            throw new Exception("Custom Speaches API cURL-Fehler: {$curlError}");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            Log::error('Custom Speaches API error', [
                'status' => $httpCode,
                'body' => $responseBody,
            ]);
            throw new Exception("Custom Speaches API-Fehler (Status: {$httpCode}): {$responseBody}");
        }

        $responseData = json_decode($responseBody, true);

        return $this->normalizeResponse($responseData);
    }

    public function diarizeAudio(string $audioPath, array $result, array $options = []): array
    {
        if (empty($this->diarizationModel)) {
            return $result;
        }

        return $this->processDiarization($audioPath, $result, $options);
    }

    protected function processDiarization(string $audioPath, array $result, array $options = []): array
    {
        $segments = $result['segments'] ?? [];

        try {
            Log::info('Starte Audio-basierte Diarization bei Custom Speaches', ['audio_path' => $audioPath, 'options' => $options]);

            $payload = [
                'model' => $this->diarizationModel,
            ];

            $numSpeakers = $options['num_speakers'] ?? null;
            if ($numSpeakers !== null && $numSpeakers > 0) {
                $payload['num_speakers'] = (int) $numSpeakers;
            } else {
                $minSpeakers = $options['min_speakers'] ?? $this->minSpeakers;
                if ($minSpeakers !== null && $minSpeakers > 0) {
                    $payload['min_speakers'] = (int) $minSpeakers;
                }
                $maxSpeakers = $options['max_speakers'] ?? $this->maxSpeakers;
                if ($maxSpeakers !== null && $maxSpeakers > 0) {
                    $payload['max_speakers'] = (int) $maxSpeakers;
                }
            }

            $payload['file'] = new \CURLFile($audioPath, mime_content_type($audioPath), basename($audioPath));

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->baseUrl.'/audio/diarization');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 600);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer '.$this->apiKey,
            ]);

            $responseBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                Log::error('Custom Speaches API Diarization curl error', ['error' => $curlError]);
                throw new Exception("Custom Speaches API Diarization cURL-Fehler: {$curlError}");
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                Log::warning('Diarization API error: '.$responseBody);

                return $result;
            }

            $diarizationData = json_decode($responseBody, true);
            $diarizationSegments = $diarizationData['segments'] ?? [];

            if (empty($diarizationSegments)) {
                return $result;
            }

            $speakerMap = [];
            $nextSpeakerIndex = 1;

            foreach ($segments as &$transSegment) {
                $bestSpeaker = null;

                // 1. Prioritize Word-Level Majority Vote if words are available
                if (! empty($transSegment['words'])) {
                    $speakerVotes = [];

                    foreach ($transSegment['words'] as $w) {
                        $wMid = ($w['start'] + $w['end']) / 2;
                        $wordSpeaker = null;

                        foreach ($diarizationSegments as $diarSegment) {
                            if ($wMid >= $diarSegment['start'] && $wMid <= $diarSegment['end']) {
                                $wordSpeaker = $diarSegment['speaker'];
                                break;
                            }
                        }

                        // Fallback for word: max overlap
                        if (! $wordSpeaker) {
                            $maxWordOverlap = 0;
                            foreach ($diarizationSegments as $diarSegment) {
                                $oStart = max($w['start'], $diarSegment['start']);
                                $oEnd = min($w['end'], $diarSegment['end']);
                                $o = max(0, $oEnd - $oStart);
                                if ($o > $maxWordOverlap) {
                                    $maxWordOverlap = $o;
                                    $wordSpeaker = $diarSegment['speaker'];
                                }
                            }
                        }

                        if ($wordSpeaker) {
                            $speakerVotes[$wordSpeaker] = ($speakerVotes[$wordSpeaker] ?? 0) + 1;
                        }
                    }

                    if (! empty($speakerVotes)) {
                        arsort($speakerVotes);
                        $bestSpeaker = array_key_first($speakerVotes);
                    }
                }

                // 2. Fallback to Segment-Level Max Overlap
                if (! $bestSpeaker) {
                    $transStart = $transSegment['start'];
                    $transEnd = $transSegment['end'];
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
                }

                if ($bestSpeaker) {
                    if (! isset($speakerMap[$bestSpeaker])) {
                        $speakerMap[$bestSpeaker] = 'Sprecher '.$nextSpeakerIndex++;
                    }
                    $transSegment['speaker'] = $speakerMap[$bestSpeaker];
                } else {
                    $transSegment['speaker'] = 'Unbekannt';
                }
            }

            // Zusammenhängende Segmente desselben Sprechers mergen,
            // um zu stark zersplitterte Textblöcke in der UI zu vermeiden.
            $mergedSegments = [];
            $currentSegment = null;

            foreach ($segments as $segment) {
                if ($currentSegment === null) {
                    $currentSegment = $segment;

                    continue;
                }

                $gap = $segment['start'] - $currentSegment['end'];

                // Wenn gleicher Sprecher und Lücke nicht extrem groß (z.B. < 3 Sekunden)
                if ($segment['speaker'] === $currentSegment['speaker'] && $gap < 3.0) {
                    $currentSegment['end'] = $segment['end'];
                    $currentSegment['text'] .= ' '.trim($segment['text']);
                    if (isset($segment['words'])) {
                        $currentSegment['words'] = array_merge($currentSegment['words'] ?? [], $segment['words']);
                    }
                } else {
                    $mergedSegments[] = $currentSegment;
                    $currentSegment = $segment;
                }
            }

            if ($currentSegment !== null) {
                $mergedSegments[] = $currentSegment;
            }

            $result['segments'] = $mergedSegments;

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
