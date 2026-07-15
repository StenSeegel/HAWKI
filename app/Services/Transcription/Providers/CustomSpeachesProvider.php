<?php

declare(strict_types=1);

namespace App\Services\Transcription\Providers;

use App\Services\Transcription\Contracts\TranscriptionProviderInterface;
use App\Services\Transcription\SpeachesConcurrencyLimiter;
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

    protected string $diarizationBaseUrl;

    protected string $diarizationApiKey;

    protected string $diarizationModel;

    public function __construct(TranscriptionSettingsService $settingsService)
    {
        $this->baseUrl = rtrim((string) $settingsService->get('base_url', ''), '/');
        $this->apiKey = (string) $settingsService->get('api_key', '');
        $this->model = (string) $settingsService->get('model', '');

        // Diarization may live on a different machine than batch STT (see
        // transcription-stack-requirements.md); empty settings fall back to
        // the batch server so single-machine setups need no extra config.
        $this->diarizationBaseUrl = rtrim((string) $settingsService->get('diarization_base_url', ''), '/') ?: $this->baseUrl;
        $this->diarizationApiKey = ((string) $settingsService->get('diarization_api_key', '')) ?: $this->apiKey;
        $this->diarizationModel = (string) $settingsService->get('diarization_model', 'pyannote/speaker-diarization-community-1');

        if (empty($this->baseUrl) || empty($this->model)) {
            throw new RuntimeException('Custom Speaches Provider ist unvollständig konfiguriert.');
        }
    }

    public function getName(): string
    {
        return 'Custom Speaches';
    }

    /**
     * Maximum number of requests that may be in flight at the Speaches server
     * at once, across all jobs and chunks (= number of model instances).
     */
    protected function maxConcurrency(): int
    {
        return max(1, (int) config('transcription.max_concurrency', 3));
    }

    /**
     * Shared global concurrency budget. All request types (transcription,
     * diarization, VAD, analysis) use the same slot namespace so the total
     * server load never exceeds the configured capacity.
     */
    protected function limiter(): SpeachesConcurrencyLimiter
    {
        return new SpeachesConcurrencyLimiter($this->maxConcurrency());
    }

    /**
     * Execute a multipart POST to the Speaches server while holding a single
     * concurrency permit, retrying on transient (transport / 5xx) failures.
     *
     * A request that successfully connected and then ran out of time while the
     * server was still processing it is NOT treated as transient: firing the
     * identical request again would just burn the same amount of time for the
     * same outcome, so that case returns immediately instead of retrying.
     *
     * @param  array  $payload  cURL POST fields (may contain a \CURLFile)
     * @return array{body: string, status: int, error: string}
     */
    protected function postToServer(string $endpoint, array $payload, int $timeout = 600, int $maxAttempts = 3, int $connectTimeout = 15, ?string $apiKey = null): array
    {
        $apiKey ??= $this->apiKey;
        // Used only for log labeling, so a slow/contended run is diagnosable
        // (e.g. "diarization waited 45s for a slot" vs. an anonymous entry).
        $label = basename((string) parse_url($endpoint, PHP_URL_PATH));

        $limiter = $this->limiter();
        $requestStartedAt = microtime(true);
        $permits = $limiter->acquire(1, 600, $label);

        try {
            $body = '';
            $status = 0;
            $error = '';

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $endpoint);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min($connectTimeout, $timeout));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer '.$apiKey,
                ]);

                $rawBody = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = (string) curl_error($ch);
                $errno = curl_errno($ch);
                $connectTime = (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME);
                curl_close($ch);
                $body = is_string($rawBody) ? $rawBody : '';

                // Success, or a non-retryable client error (4xx): return as-is.
                if ($error === '' && $status >= 200 && $status < 500 && $status !== 0) {
                    $elapsed = microtime(true) - $requestStartedAt;
                    if ($elapsed > 30) {
                        Log::info(sprintf("Speaches request to '%s' succeeded after %.1fs (timeout budget was %ds).", $label, $elapsed, $timeout));
                    }

                    return ['body' => $body, 'status' => $status, 'error' => ''];
                }

                $isProcessingTimeout = $errno === CURLE_OPERATION_TIMEDOUT && $connectTime > 0;
                if ($isProcessingTimeout) {
                    Log::warning("Speaches request to {$endpoint} exceeded its {$timeout}s budget while the server was still processing it; not retrying.", [
                        'attempt' => $attempt,
                    ]);

                    return ['body' => $body, 'status' => $status, 'error' => $error !== '' ? $error : "Zeitüberschreitung nach {$timeout}s"];
                }

                // Transient failure (connect-phase timeout, transport error, or 5xx): back off and retry.
                if ($attempt < $maxAttempts) {
                    Log::warning("Speaches transient failure on {$endpoint} (attempt {$attempt}/{$maxAttempts})", [
                        'status' => $status,
                        'curl_error' => $error,
                    ]);
                    sleep(2 * $attempt);
                }
            }

            return ['body' => $body, 'status' => $status, 'error' => $error];
        } finally {
            $limiter->release($permits);
        }
    }

    /**
     * Computes a diarization request timeout scaled to the audio's duration
     * (see config('transcription.diarization_timeout_*')). Diarization runs on
     * the whole, unchunked file, so unlike transcription chunks it cannot rely
     * on a flat timeout — a 30-minute recording needs far longer than a 2-minute
     * one. Falls back to the configured floor when duration is unknown.
     */
    /**
     * Diarization options can contain megabytes of base64 audio
     * (known_speaker_references snippets) and thousands of VAD segments —
     * logging them raw bloats laravel.log and the database log channel with
     * binary noise. Replace bulky entries with their dimensions.
     */
    protected function summarizeOptionsForLog(array $options): array
    {
        if (isset($options['known_speaker_references']) && is_array($options['known_speaker_references'])) {
            $refs = $options['known_speaker_references'];
            $options['known_speaker_references'] = sprintf(
                '[%d references, %.1f MB total]',
                count($refs),
                array_sum(array_map('strlen', $refs)) / (1024 * 1024)
            );
        }

        if (isset($options['vad_segments']) && is_array($options['vad_segments'])) {
            $options['vad_segments'] = sprintf('[%d segments]', count($options['vad_segments']));
        }

        return $options;
    }

    protected function diarizationTimeout(?float $durationSeconds): int
    {
        $floor = max(1, (int) config('transcription.diarization_timeout_floor', 600));

        if ($durationSeconds === null || $durationSeconds <= 0) {
            return $floor;
        }

        $ceiling = max($floor, (int) config('transcription.diarization_timeout_ceiling', 3600));
        $multiplier = (float) config('transcription.diarization_timeout_multiplier', 2.0);
        $buffer = (int) config('transcription.diarization_timeout_buffer_seconds', 120);

        $estimate = (int) ceil($durationSeconds * $multiplier) + $buffer;

        return max($floor, min($ceiling, $estimate));
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

            Log::info('CustomSpeaches: audio file prepared for transcription', [
                'original_name' => $audioFile->getClientOriginalName(),
                'temp_path' => $tempPath,
            ]);

            $result = $this->processTranscription($tempPath, $language);

            if ($diarize && ! empty($result['segments']) && ! empty($this->diarizationModel)) {
                if (is_callable($onProgress)) {
                    $onProgress('diarizing');
                }
                $options = [];
                try {
                    $options['vad_segments'] = $this->getSpeechTimestamps($tempPath);
                } catch (Exception $e) {
                    Log::warning('VAD segments fetch failed in transcribeAudio: '.$e->getMessage());
                }
                $result = $this->processDiarization($tempPath, $result, $options);
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

    public function transcribeAudioParallel(array $audioFiles, ?string $language = null): array
    {
        if (empty($audioFiles)) {
            return [];
        }

        $urls = array_values(array_filter(array_map('trim', explode(',', $this->baseUrl))));
        if (empty($urls)) {
            throw new RuntimeException('Custom Speaches Provider ist unvollständig konfiguriert.');
        }

        // Global request budget: the Speaches server can only run a fixed number
        // of instances in parallel. We never put more than this many requests in
        // flight at once, across ALL jobs and chunks (see SpeachesConcurrencyLimiter).
        $limit = max(1, (int) config('transcription.max_concurrency', 3));

        Log::info('CustomSpeaches: starting parallel transcription', [
            'files_count' => count($audioFiles),
            'workers_count' => count($urls),
            'max_concurrency' => $limit,
            'language' => $language ?? 'auto',
        ]);

        $payloadBase = array_filter([
            'model' => $this->model,
            'language' => $language,
            'response_format' => 'verbose_json',
            'timestamp_granularities[]' => 'word',
        ]);

        $limiter = $this->limiter();
        $workerCount = count($urls);
        $retryTimes = max(1, (int) config('transcription.retry_times', 3));
        $retryDelayMs = max(0, (int) config('transcription.retry_delay_ms', 3000));

        $results = [];
        $pending = array_keys($audioFiles);
        $globalIndex = 0; // round-robin worker assignment, stable across waves

        // Process the chunks in waves whose size is bounded by the permits we can
        // acquire from the global budget. A single big job can therefore never
        // flood the server, and concurrent jobs transparently share the budget.
        while (! empty($pending)) {
            $remaining = count($pending);
            $permits = $limiter->acquire(min($limit, $remaining), 600, 'transcriptions (parallel)');
            $waveSize = ! empty($permits) ? min(count($permits), $remaining) : min($limit, $remaining);

            $batchKeys = array_splice($pending, 0, $waveSize);

            try {
                $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($batchKeys, $audioFiles, $urls, $workerCount, $payloadBase, $retryTimes, $retryDelayMs, &$globalIndex) {
                    $requests = [];

                    foreach ($batchKeys as $key) {
                        $file = $audioFiles[$key];
                        $baseUrl = $urls[$globalIndex % $workerCount];
                        $globalIndex++;
                        $url = rtrim($baseUrl, '/').'/audio/transcriptions';

                        $filePath = $file instanceof \Illuminate\Http\UploadedFile ? $file->getRealPath() : $file;

                        if (! file_exists($filePath)) {
                            throw new RuntimeException("Audiodatei existiert nicht: {$filePath}");
                        }

                        // Name each pooled request by its original key so responses
                        // are addressable regardless of the wave's internal ordering.
                        // Retry transient transport/5xx failures so a single hiccup
                        // doesn't abort the whole job.
                        $requests[] = $pool->as((string) $key)
                            ->timeout(600)
                            ->retry($retryTimes, $retryDelayMs, function ($exception) {
                                if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
                                    return true;
                                }
                                if ($exception instanceof \Illuminate\Http\Client\RequestException) {
                                    return $exception->response->status() >= 500;
                                }

                                return false;
                            }, throw: false)
                            ->withHeaders([
                                'Authorization' => 'Bearer '.$this->apiKey,
                            ])
                            ->attach('file', file_get_contents($filePath), basename($filePath))
                            ->post($url, $payloadBase);
                    }

                    return $requests;
                });
            } finally {
                $limiter->release($permits);
            }

            foreach ($batchKeys as $key) {
                $response = $responses[(string) $key];

                if ($response instanceof \Exception) {
                    Log::error('Custom Speaches Parallel API error', [
                        'key' => $key,
                        'error' => $response->getMessage(),
                    ]);
                    throw new Exception("Custom Speaches Parallel API-Fehler: {$response->getMessage()}");
                }

                if (! $response->successful()) {
                    Log::error('Custom Speaches Parallel API error', [
                        'key' => $key,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    throw new Exception("Custom Speaches API-Fehler (Status: {$response->status()}): {$response->body()}");
                }

                $results[$key] = $this->normalizeResponse($response->json());
            }
        }

        return $results;
    }

    protected function processTranscription(string $audioPath, ?string $language): array
    {
        Log::info('Sending transcription request to Custom Speaches', [
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

        $response = $this->postToServer($this->baseUrl.'/audio/transcriptions', $payload);
        $responseBody = $response['body'];
        $httpCode = $response['status'];
        $curlError = $response['error'];

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

    public function diarizeAudio(string $audioPath, array $result, array $options = [], ?float $audioDurationSeconds = null): array
    {
        if (empty($this->diarizationModel)) {
            return $result;
        }

        try {
            $options['vad_segments'] = $this->getSpeechTimestamps($audioPath, $audioDurationSeconds);
        } catch (Exception $e) {
            Log::warning('VAD segments fetch failed in diarizeAudio: '.$e->getMessage());
        }

        return $this->processDiarization($audioPath, $result, $options, $audioDurationSeconds);
    }

    /**
     * @throws Exception if the diarization request itself fails (transport error
     *                    or non-2xx response). A clean response with zero detected
     *                    speakers is not an error and returns an empty array.
     */
    public function analyzeSpeakers(string $audioPath, array $options = [], ?float $audioDurationSeconds = null): array
    {
        if (empty($this->diarizationModel)) {
            return [];
        }

        Log::info('Starting pre-diarization (analyze) request to Custom Speaches', ['audio_path' => $audioPath, 'options' => $this->summarizeOptionsForLog($options)]);

        $payload = [
            'model' => $this->diarizationModel,
        ];

        $payload['file'] = new \CURLFile($audioPath, mime_content_type($audioPath), basename($audioPath));

        $timeout = $this->diarizationTimeout($audioDurationSeconds);
        $response = $this->postToServer($this->diarizationBaseUrl.'/audio/diarization', $payload, $timeout, 3, 15, $this->diarizationApiKey);
        $responseBody = $response['body'];
        $httpCode = $response['status'];
        $curlError = $response['error'];

        if ($curlError) {
            Log::error('Custom Speaches API Diarization curl error', ['error' => $curlError]);
            throw new Exception("Sprecheranalyse fehlgeschlagen (Diarization-Server nicht erreichbar oder zu langsam): {$curlError}");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            Log::error('Diarization API error during analysis: '.$responseBody);
            throw new Exception("Sprecheranalyse fehlgeschlagen (Diarization-Server antwortete mit Status {$httpCode}).");
        }

        $diarizationData = json_decode($responseBody, true);

        return $diarizationData['segments'] ?? [];
    }

    /**
     * @throws Exception if the diarization request itself fails (transport error
     *                    or non-2xx response). Callers that want to gracefully
     *                    degrade to an undiarized transcript should catch this.
     */
    protected function processDiarization(string $audioPath, array $result, array $options = [], ?float $audioDurationSeconds = null): array
    {
        $segments = $result['segments'] ?? [];

        Log::info('Starting audio-based diarization request to Custom Speaches', ['audio_path' => $audioPath, 'options' => $this->summarizeOptionsForLog($options)]);

        $payload = [
            'model' => $this->diarizationModel,
        ];

        // Either an exact count ("single speaker") or an optional lower
        // bound ("multiple speakers" => at least 2); otherwise pyannote
        // detects freely.
        $numSpeakers = $options['num_speakers'] ?? null;
        if ($numSpeakers !== null && $numSpeakers > 0) {
            $payload['num_speakers'] = (int) $numSpeakers;
        } elseif (! empty($options['min_speakers'])) {
            $payload['min_speakers'] = (int) $options['min_speakers'];
        }

        $payload['file'] = new \CURLFile($audioPath, mime_content_type($audioPath), basename($audioPath));

        if (! empty($options['known_speaker_names']) && ! empty($options['known_speaker_references'])) {
            foreach ($options['known_speaker_names'] as $index => $name) {
                $payload['known_speaker_names['.$index.']'] = $name;
            }
            foreach ($options['known_speaker_references'] as $index => $ref) {
                $payload['known_speaker_references['.$index.']'] = $ref;
            }
        }

        $timeout = $this->diarizationTimeout($audioDurationSeconds);
        $response = $this->postToServer($this->diarizationBaseUrl.'/audio/diarization', $payload, $timeout, 3, 15, $this->diarizationApiKey);
        $responseBody = $response['body'];
        $httpCode = $response['status'];
        $curlError = $response['error'];

        if ($curlError) {
            Log::error('Custom Speaches API Diarization curl error', ['error' => $curlError]);
            throw new Exception("Diarization fehlgeschlagen (Diarization-Server nicht erreichbar oder zu langsam): {$curlError}");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            Log::error('Diarization API error: '.$responseBody);
            throw new Exception("Diarization fehlgeschlagen (Diarization-Server antwortete mit Status {$httpCode}).");
        }

        $diarizationData = json_decode($responseBody, true);
        $diarizationSegments = $diarizationData['segments'] ?? [];

        return $this->mapDiarizationSegments($result, $diarizationSegments, $options);
    }

    public function mapDiarizationSegments(array $result, array $diarizationSegments, array $options = []): array
    {
        $segments = $result['segments'] ?? [];
        if (empty($diarizationSegments)) {
            return $result;
        }

        $words = $result['words'] ?? [];
        if (! empty($words)) {
            // Group words into contiguous phrases using punctuation-aware and speaker-transition rules
            $phrases = [];
            $currentPhrase = [];

            foreach ($words as $word) {
                if (empty($currentPhrase)) {
                    $currentPhrase[] = $word;
                } else {
                    $prevWord = $currentPhrase[count($currentPhrase) - 1];
                    $gap = $word['start'] - $prevWord['end'];

                    $prevSp = $this->getWordSpeaker($prevWord, $diarizationSegments);
                    $currSp = $this->getWordSpeaker($word, $diarizationSegments);
                    $speakerChanged = ($prevSp !== null && $currSp !== null && $prevSp !== $currSp);

                    $prevWordText = trim($prevWord['word'], " \t\n\r\0\x0B\"'»«›‹„“");
                    $lastChar = ! empty($prevWordText) ? substr($prevWordText, -1) : '';
                    $endsWithPunctuation = in_array($lastChar, ['.', '?', '!', ':', ';'], true);

                    $shouldSplit = ($gap >= 0.25)
                        || ($speakerChanged && ($gap >= 0.10 || $endsWithPunctuation))
                        || ($endsWithPunctuation && $gap >= 0.10);

                    if (! $shouldSplit) {
                        $currentPhrase[] = $word;
                    } else {
                        $phrases[] = $currentPhrase;
                        $currentPhrase = [$word];
                    }
                }
            }
            if (! empty($currentPhrase)) {
                $phrases[] = $currentPhrase;
            }

            // Map each phrase to its dominant speaker with weighted later-word preference
            $wordIndex = 0;
            $vadSegments = $options['vad_segments'] ?? [];

            foreach ($phrases as $phrase) {
                $phraseStart = $phrase[0]['start'];
                $phraseEnd = $phrase[count($phrase) - 1]['end'];

                $speakerOverlaps = [];
                $numWords = count($phrase);
                foreach ($phrase as $wIdx => $pWord) {
                    $weight = 1.0 + ($numWords > 1 ? ($wIdx / ($numWords - 1)) : 0.0);
                    $wStart = $pWord['start'];
                    $wEnd = $pWord['end'];
                    $wDur = max(0.01, $wEnd - $wStart);

                    foreach ($diarizationSegments as $diarSeg) {
                        $overlapStart = max($wStart, $diarSeg['start']);
                        $overlapEnd = min($wEnd, $diarSeg['end']);
                        $overlap = max(0.0, $overlapEnd - $overlapStart);
                        if ($overlap > 0.0) {
                            $sp = $diarSeg['speaker'];
                            $overlapRatio = $overlap / $wDur;
                            $speakerOverlaps[$sp] = ($speakerOverlaps[$sp] ?? 0.0) + ($overlap * $overlapRatio * $weight);
                        }
                    }
                }

                $bestSpeaker = null;
                if (! empty($speakerOverlaps)) {
                    arsort($speakerOverlaps);
                    $bestSpeaker = array_key_first($speakerOverlaps);
                }

                // VAD gap-filling fallback
                if ((! $bestSpeaker || $bestSpeaker === 'Unbekannt') && ! empty($vadSegments)) {
                    $matchingVadSegment = null;
                    $pMid = ($phraseStart + $phraseEnd) / 2.0;
                    foreach ($vadSegments as $vadSeg) {
                        if ($pMid >= $vadSeg['start'] && $pMid <= $vadSeg['end']) {
                            $matchingVadSegment = $vadSeg;
                            break;
                        }
                    }

                    if ($matchingVadSegment) {
                        $minDist = null;
                        foreach ($diarizationSegments as $diarSeg) {
                            $overlapStart = max($matchingVadSegment['start'], $diarSeg['start']);
                            $overlapEnd = min($matchingVadSegment['end'], $diarSeg['end']);
                            if ($overlapEnd > $overlapStart) {
                                $dist = 0.0;
                                if ($pMid < $diarSeg['start']) {
                                    $dist = $diarSeg['start'] - $pMid;
                                } elseif ($pMid > $diarSeg['end']) {
                                    $dist = $pMid - $diarSeg['end'];
                                }
                                if ($minDist === null || $dist < $minDist) {
                                    $minDist = $dist;
                                    $bestSpeaker = $diarSeg['speaker'];
                                }
                            }
                        }
                    }
                }

                $resolvedSpeaker = $bestSpeaker ?: 'Unbekannt';

                $numPhraseWords = count($phrase);
                for ($k = 0; $k < $numPhraseWords; $k++) {
                    $words[$wordIndex + $k]['speaker'] = $resolvedSpeaker;
                }
                $wordIndex += $numPhraseWords;
            }

            $result['words'] = $words;

            // Also update words inside segments if they exist
            if (! empty($result['segments'])) {
                foreach ($result['segments'] as &$seg) {
                    if (! empty($seg['words'])) {
                        foreach ($seg['words'] as &$sw) {
                            $sMid = ($sw['start'] + $sw['end']) / 2.0;
                            // Find corresponding word in $words
                            foreach ($words as $gw) {
                                if (abs($sMid - ($gw['start'] + $gw['end']) / 2.0) < 0.05) {
                                    $sw['speaker'] = $gw['speaker'];
                                    break;
                                }
                            }
                        }
                        unset($sw);
                    }
                }
                unset($seg);
            }
        }

        $speakerMap = $options['speaker_mapping'] ?? [];
        $nextSpeakerIndex = count($speakerMap) + 1;
        foreach ($speakerMap as $mappedName) {
            if (preg_match('/Sprecher\s+(\d+)/i', (string) $mappedName, $matches)) {
                $num = (int) $matches[1];
                if ($num >= $nextSpeakerIndex) {
                    $nextSpeakerIndex = $num + 1;
                }
            }
        }

        // 1. Distribute global words to their corresponding original segments
        $segmentWordsMap = [];
        foreach ($result['words'] ?? [] as $word) {
            $mid = ($word['start'] + $word['end']) / 2;
            $foundSegmentIndex = null;
            foreach ($segments as $idx => $seg) {
                if ($mid >= $seg['start'] && $mid <= $seg['end']) {
                    $foundSegmentIndex = $idx;
                    break;
                }
            }
            if ($foundSegmentIndex === null) {
                $minDist = null;
                foreach ($segments as $idx => $seg) {
                    $dist = min(abs($mid - $seg['start']), abs($mid - $seg['end']));
                    if ($minDist === null || $dist < $minDist) {
                        $minDist = $dist;
                        $foundSegmentIndex = $idx;
                    }
                }
            }
            if ($foundSegmentIndex !== null) {
                $segmentWordsMap[$foundSegmentIndex][] = $word;
            }
        }

        $newSegments = [];

        foreach ($segments as $idx => $transSegment) {
            // Get words for this segment
            $words = $transSegment['words'] ?? $segmentWordsMap[$idx] ?? [];

            if (! empty($words)) {
                $vadSegments = $options['vad_segments'] ?? [];

                // Assign a speaker to each word
                foreach ($words as &$word) {
                    $wMid = ($word['start'] + $word['end']) / 2;
                    $wordSpeaker = $word['speaker'] ?? null;

                    if (! $wordSpeaker || $wordSpeaker === 'Unbekannt') {
                        foreach ($diarizationSegments as $diarSegment) {
                            if ($wMid >= $diarSegment['start'] && $wMid <= $diarSegment['end']) {
                                $wordSpeaker = $diarSegment['speaker'];
                                break;
                            }
                        }

                        if (! $wordSpeaker) {
                            $maxWordOverlap = 0;
                            foreach ($diarizationSegments as $diarSegment) {
                                $oStart = max($word['start'], $diarSegment['start']);
                                $oEnd = min($word['end'], $diarSegment['end']);
                                $o = max(0, $oEnd - $oStart);
                                if ($o > $maxWordOverlap) {
                                    $maxWordOverlap = $o;
                                    $wordSpeaker = $diarSegment['speaker'];
                                }
                            }
                        }

                        // VAD-basiertes Lückenfüllen
                        if ((! $wordSpeaker || $wordSpeaker === 'Unbekannt') && ! empty($vadSegments)) {
                            $matchingVadSegment = null;
                            foreach ($vadSegments as $vadSeg) {
                                if ($wMid >= $vadSeg['start'] && $wMid <= $vadSeg['end']) {
                                    $matchingVadSegment = $vadSeg;
                                    break;
                                }
                            }

                            if ($matchingVadSegment) {
                                $bestSpeaker = null;
                                $minDist = null;

                                foreach ($diarizationSegments as $diarSegment) {
                                    $overlapStart = max($matchingVadSegment['start'], $diarSegment['start']);
                                    $overlapEnd = min($matchingVadSegment['end'], $diarSegment['end']);

                                    if ($overlapEnd > $overlapStart) {
                                        $dist = 0.0;
                                        if ($wMid < $diarSegment['start']) {
                                            $dist = $diarSegment['start'] - $wMid;
                                        } elseif ($wMid > $diarSegment['end']) {
                                            $dist = $wMid - $diarSegment['end'];
                                        }

                                        if ($minDist === null || $dist < $minDist) {
                                            $minDist = $dist;
                                            $bestSpeaker = $diarSegment['speaker'];
                                        }
                                    }
                                }

                                if ($bestSpeaker) {
                                    $wordSpeaker = $bestSpeaker;
                                }
                            }
                        }
                    }

                    $word['speaker'] = $wordSpeaker ?: 'Unbekannt';
                }
                unset($word);

                // Group consecutive words of the same speaker, but split at sentence boundaries with a pause
                $wordGroups = [];
                $currentGroup = null;

                foreach ($words as $word) {
                    if ($currentGroup === null) {
                        $currentGroup = [
                            'speaker' => $word['speaker'],
                            'words' => [$word],
                        ];
                    } else {
                        $prevWord = $currentGroup['words'][count($currentGroup['words']) - 1];
                        $gap = $word['start'] - $prevWord['end'];

                        $prevWordText = trim($prevWord['word'], " \t\n\r\0\x0B\"'»«›‹„“");
                        $lastChar = ! empty($prevWordText) ? substr($prevWordText, -1) : '';
                        $endsWithPunctuation = in_array($lastChar, ['.', '?', '!', ':', ';'], true);

                        // Split if speaker changed OR if there is a pause after punctuation
                        $shouldSplitGroup = ($currentGroup['speaker'] !== $word['speaker'])
                            || ($endsWithPunctuation && $gap >= 0.10);

                        if (! $shouldSplitGroup) {
                            $currentGroup['words'][] = $word;
                        } else {
                            $wordGroups[] = $currentGroup;
                            $currentGroup = [
                                'speaker' => $word['speaker'],
                                'words' => [$word],
                            ];
                        }
                    }
                }
                if ($currentGroup !== null) {
                    $wordGroups[] = $currentGroup;
                }

                // Create new segments from the word groups
                foreach ($wordGroups as $group) {
                    $groupWords = $group['words'];
                    $groupSpeaker = $group['speaker'];

                    // Map the speaker name
                    $mappedSpeaker = 'Unbekannt';
                    if ($groupSpeaker !== 'Unbekannt') {
                        if (isset($speakerMap[$groupSpeaker])) {
                            $mappedSpeaker = $speakerMap[$groupSpeaker];
                        } elseif (in_array($groupSpeaker, $speakerMap, true) ||
                                  (isset($options['known_speaker_names']) && in_array($groupSpeaker, $options['known_speaker_names'], true))) {
                            $mappedSpeaker = $groupSpeaker;
                        } else {
                            $speakerMap[$groupSpeaker] = 'Sprecher '.$nextSpeakerIndex++;
                            $mappedSpeaker = $speakerMap[$groupSpeaker];
                        }
                    }

                    // Concatenate the words
                    $text = '';
                    foreach ($groupWords as $gw) {
                        $text .= $gw['word'];
                    }
                    $text = trim($text);

                    // Build the new segment inheriting parent metadata
                    $newSegment = $transSegment;
                    $newSegment['start'] = $groupWords[0]['start'];
                    $newSegment['end'] = $groupWords[count($groupWords) - 1]['end'];
                    $newSegment['text'] = $text;
                    $newSegment['speaker'] = $mappedSpeaker;
                    $newSegment['words'] = $groupWords;

                    $newSegments[] = $newSegment;
                }
            } else {
                // Fallback if no words are available for this segment: use segment-level overlap
                $transStart = $transSegment['start'];
                $transEnd = $transSegment['end'];
                $speakerOverlaps = [];

                foreach ($diarizationSegments as $diarSegment) {
                    $diarStart = $diarSegment['start'];
                    $diarEnd = $diarSegment['end'];

                    $overlapStart = max($transStart, $diarStart);
                    $overlapEnd = min($transEnd, $diarEnd);
                    $overlap = max(0, $overlapEnd - $overlapStart);

                    if ($overlap > 0) {
                        $speaker = $diarSegment['speaker'];
                        $speakerOverlaps[$speaker] = ($speakerOverlaps[$speaker] ?? 0.0) + $overlap;
                    }
                }

                $bestSpeaker = null;
                if (! empty($speakerOverlaps)) {
                    arsort($speakerOverlaps);
                    $bestSpeaker = array_key_first($speakerOverlaps);
                }

                $mappedSpeaker = 'Unbekannt';
                if ($bestSpeaker) {
                    if (isset($speakerMap[$bestSpeaker])) {
                        $mappedSpeaker = $speakerMap[$bestSpeaker];
                    } elseif (in_array($bestSpeaker, $speakerMap, true) ||
                              (isset($options['known_speaker_names']) && in_array($bestSpeaker, $options['known_speaker_names'], true))) {
                        $mappedSpeaker = $bestSpeaker;
                    } else {
                        $speakerMap[$bestSpeaker] = 'Sprecher '.$nextSpeakerIndex++;
                        $mappedSpeaker = $speakerMap[$bestSpeaker];
                    }
                }

                $newSegment = $transSegment;
                $newSegment['speaker'] = $mappedSpeaker;
                $newSegments[] = $newSegment;
            }
        }

        // Zusammenhängende Segmente desselben Sprechers mergen,
        // um zu stark zersplitterte Textblöcke in der UI zu vermeiden.
        $mergedSegments = [];
        $currentSegment = null;

        foreach ($newSegments as $segment) {
            if ($currentSegment === null) {
                $currentSegment = $segment;

                continue;
            }

            $gap = $segment['start'] - $currentSegment['end'];

            $prevText = trim($currentSegment['text'], " \t\n\r\0\x0B\"'»«›‹„“");
            $lastChar = ! empty($prevText) ? substr($prevText, -1) : '';
            $endsWithPunctuation = in_array($lastChar, ['.', '?', '!', ':', ';'], true);

            $isPauseAfterSentence = $endsWithPunctuation && $gap >= 0.10;

            // Wenn gleicher Sprecher und Lücke nicht extrem groß (z.B. < 3 Sekunden)
            // aber nicht mergen, wenn es eine deutliche Pause nach einem Satzzeichen gibt
            if ($segment['speaker'] === $currentSegment['speaker'] && $gap < 3.0 && ! $isPauseAfterSentence) {
                $currentSegment['end'] = $segment['end'];
                $currentSegment['text'] = trim($currentSegment['text']).' '.trim($segment['text']);
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

        // Re-assign sequential IDs to the merged segments
        foreach ($mergedSegments as $mIdx => &$mergedSeg) {
            $mergedSeg['id'] = $mIdx + 1;
        }
        unset($mergedSeg);

        $result['segments'] = $mergedSegments;

        return $result;
    }

    /**
     * Ermittelt den dominanten Sprecher für ein einzelnes Wort basierend auf der Diarization.
     */
    protected function getWordSpeaker(array $word, array $diarizationSegments): ?string
    {
        $wStart = $word['start'];
        $wEnd = $word['end'];
        $bestSp = null;
        $maxOverlap = 0.0;

        foreach ($diarizationSegments as $diarSeg) {
            $overlapStart = max($wStart, $diarSeg['start']);
            $overlapEnd = min($wEnd, $diarSeg['end']);
            $overlap = max(0.0, $overlapEnd - $overlapStart);

            if ($overlap > $maxOverlap) {
                $maxOverlap = $overlap;
                $bestSp = $diarSeg['speaker'];
            }
        }

        return $bestSp;
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

    public function getSpeechTimestamps(string $audioPath, ?float $audioDurationSeconds = null): array
    {
        try {
            // VAD runs on the whole file, so a flat budget fails on long recordings
            // (a 105-min file blew the previous hardcoded 120s). Reuse the
            // duration-scaled diarization budget, capped at 900s — VAD is far
            // cheaper than diarization, and its result is optional (callers
            // degrade gracefully), so it should never block for the full ceiling.
            $timeout = (int) min(900, $this->diarizationTimeout($audioDurationSeconds));

            Log::info('Fetching VAD speech timestamps from Custom Speaches', [
                'audio_path' => $audioPath,
                'model' => 'silero_vad_v5',
                'timeout' => $timeout,
            ]);

            $payload = [
                'model' => 'silero_vad_v5',
            ];

            $payload['file'] = new \CURLFile($audioPath, mime_content_type($audioPath), basename($audioPath));

            $response = $this->postToServer($this->diarizationBaseUrl.'/audio/speech/timestamps', $payload, $timeout, 3, 15, $this->diarizationApiKey);
            $responseBody = $response['body'];
            $httpCode = $response['status'];
            $curlError = $response['error'];

            if ($curlError) {
                Log::warning('Custom Speaches VAD API curl error: '.$curlError);

                return [];
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                Log::warning('Custom Speaches VAD API error (Status '.$httpCode.'): '.$responseBody);

                return [];
            }

            $timestamps = json_decode($responseBody, true);
            if (! is_array($timestamps)) {
                return [];
            }

            $formatted = [];
            foreach ($timestamps as $ts) {
                if (isset($ts['start']) && isset($ts['end'])) {
                    $formatted[] = [
                        'start' => floatval($ts['start']) / 1000.0,
                        'end' => floatval($ts['end']) / 1000.0,
                    ];
                }
            }

            return $formatted;
        } catch (Exception $e) {
            Log::warning('getSpeechTimestamps failed: '.$e->getMessage());

            return [];
        }
    }
}
