<?php

declare(strict_types=1);

namespace App\Services\Transcription;

use App\Models\Transcription\TranscriptionJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AsyncTranscriptionService
{
    /**
     * Erzeugt eine S3 Upload-Session inkl. Job-Datenbankeintrag.
     *
     * @param  int  $userId  Der aktuelle Benutzer
     * @param  string  $filename  Der ursprüngliche Dateiname
     * @return array Gibt die Job-ID, den S3 File Path und die Presigned URL (gültig für 60 Minuten) zurück.
     */
    public function generateUploadSession(int $userId, string $filename, string $language = 'auto', string $speakerCount = 'auto'): array
    {
        $jobId = (string) Str::uuid();

        // Target path inside the S3 bucket
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $s3Path = "uploads/{$jobId}/original.{$extension}";

        // Persist the job
        $job = TranscriptionJob::create([
            'id' => $jobId,
            'user_id' => $userId,
            'file_path' => $s3Path,
            'status' => 'created',
            'manifest_data' => [
                'settings' => [
                    'filename' => $filename,
                    'language' => $language,
                    'speaker_count' => $speakerCount,
                ],
            ],
        ]);

        // Generate presigned URL for the client to upload via PUT
        $s3Disk = Storage::disk('s3');
        /** @var \Aws\S3\S3Client $client */
        $client = $s3Disk->getClient();

        $command = $client->getCommand('PutObject', [
            'Bucket' => config('filesystems.disks.s3.bucket'),
            'Key' => $s3Path,
        ]);

        $presignedRequest = $client->createPresignedRequest($command, '+60 minutes');
        $presignedUrl = (string) $presignedRequest->getUri();

        // Rewrite internal S3 endpoint to public proxy endpoint to avoid Mixed Content errors
        $s3Endpoint = config('filesystems.disks.s3.endpoint');
        $appUrl = config('app.url'); // https://app.hawki.dev

        if ($s3Endpoint && str_contains($presignedUrl, $s3Endpoint)) {
            $presignedUrl = str_replace($s3Endpoint, rtrim($appUrl, '/').'/s3', $presignedUrl);
        }

        return [
            'job_id' => $job->id,
            's3_path' => $s3Path,
            'upload_url' => $presignedUrl,
        ];
    }

    /**
     * Reiht den Job in die Redis Queue für den Python Audio Preprocessor ein.
     */
    public function dispatchPreprocessingJob(TranscriptionJob $job): void
    {
        // Wir setzen voraus, dass s3://bucket/... als Pfad erwartet wird.
        $bucket = config('filesystems.disks.s3.bucket');
        $inputUri = "s3://{$bucket}/{$job->file_path}";
        $outputUri = "s3://{$bucket}/jobs/{$job->id}/";

        $settings = $job->manifest_data['settings'] ?? [];
        $language = $settings['language'] ?? 'auto';

        $payload = [
            'job_id' => $job->id,
            'input_path' => $inputUri,
            'output_path' => $outputUri,
            'language' => $language === 'auto' ? null : $language,
            'options' => [
                'target_sample_rate' => 16000,
                'channels' => 1,
                'chunk_minutes' => 10,
                'overlap_seconds' => 3,
            ],
        ];

        $speakerSnippets = $settings['speaker_snippets'] ?? null;
        if ($speakerSnippets) {
            $payload['options']['extract_snippets'] = $speakerSnippets;
        }

        // Push to Redis list
        $queueName = env('AUDIO_PREPROCESSING_QUEUE', 'audio_preprocessing_jobs');
        Redis::connection('audio_ingest')->lpush($queueName, json_encode($payload));

        $job->update(['status' => 'preprocessing']);
        Log::info("Dispatched async transcription preprocessing for job {$job->id}");
    }

    /**
     * Startet die Analyse der Sprecheranzahl im Hintergrund.
     */
    public function dispatchAnalyzeJob(TranscriptionJob $job): void
    {
        $job->update(['status' => 'analyzing_speakers_queued']);
        \App\Jobs\Transcription\AnalyzeSpeakersJob::dispatch($job);
        Log::info("Dispatched async speaker analysis for job {$job->id}");
    }

    /**
     * Verarbeitet Status-Updates vom Python Worker, die via Daemon ausgelesen werden.
     */
    public function processStatusUpdate(array $payload): void
    {
        $jobId = $payload['job_id'] ?? null;
        if (! $jobId) {
            Log::warning('Received audio processing status without job_id', ['payload' => $payload]);

            return;
        }

        $job = TranscriptionJob::find($jobId);
        if (! $job) {
            Log::warning("Transcription job {$jobId} not found in database.");

            return;
        }

        $status = $payload['status'] ?? 'unknown';

        if ($status === 'completed') {
            $workerId = $payload['worker_id'] ?? 'unknown';

            // Preserve settings when worker returns new manifest
            $oldManifest = $job->manifest_data ?? [];
            $newManifest = $payload['manifest'] ?? [];
            $newManifest['settings'] = $oldManifest['settings'] ?? [];

            $job->update([
                'status' => 'preprocessed',
                'manifest_data' => $newManifest,
            ]);
            Log::info("Job {$jobId} preprocessed successfully by worker '{$workerId}'. Ready for transcription.");

            $this->transcribeChunksSequential($job);

        } elseif ($status === 'failed') {
            $errorMessage = $payload['error'] ?? 'Unknown error';
            $job->update([
                'status' => 'failed',
                'error_message' => $errorMessage,
            ]);

            // Fehler explizit werfen, damit der Exception Handler / Daemon ihn registriert
            throw new \RuntimeException("Job {$jobId} preprocessing failed: {$errorMessage}");
        }
    }

    protected function transcribeChunksSequential(TranscriptionJob $job): void
    {
        $manifest = $job->manifest_data;
        if (empty($manifest['chunks'])) {
            $job->update(['status' => 'failed', 'error_message' => 'No chunks in manifest']);
            throw new \RuntimeException("No chunks found in manifest for job {$job->id}");
        }

        $settings = $manifest['settings'] ?? [];
        $language = $settings['language'] ?? 'auto';
        $language = $language === 'auto' ? null : $language;
        $speakerCount = $settings['speaker_count'] ?? 'auto';
        $diarize = ($speakerCount === '1') ? false : true;

        $job->update(['status' => 'transcribing']);

        $transcriptionService = app(\App\Services\Transcription\TranscriptionService::class);
        $s3Disk = Storage::disk('s3');
        $allSegments = [];
        $allWords = [];
        $fullText = '';

        $totalChunks = count($manifest['chunks']);
        $currentChunkIndex = 0;

        foreach ($manifest['chunks'] as $chunk) {
            $currentChunkIndex++;
            $manifest['progress'] = [
                'current_chunk' => $currentChunkIndex,
                'total_chunks' => $totalChunks,
                'phase' => 'transcribing',
            ];
            $job->update([
                'status' => 'transcribing',
                'manifest_data' => $manifest,
            ]);

            $chunkPath = $chunk['path']; // e.g. "s3://bucket/jobs/job-id/chunks/chunk_000.wav"
            $bucketPrefix = 's3://'.config('filesystems.disks.s3.bucket').'/';
            $chunkKey = str_replace($bucketPrefix, '', $chunkPath);

            $startTime = (float) ($chunk['start'] ?? 0);
            $fileName = basename($chunkKey);
            $tmpPath = sys_get_temp_dir().'/'.$fileName;

            try {
                $s3Stream = $s3Disk->readStream($chunkKey);
                if (! $s3Stream) {
                    throw new \Exception("Could not read chunk {$fileName} from S3");
                }

                $tmpStream = fopen($tmpPath, 'w+');
                stream_copy_to_stream($s3Stream, $tmpStream);
                fclose($s3Stream);
                fclose($tmpStream);

                $uploadedFile = new \Illuminate\Http\UploadedFile(
                    $tmpPath,
                    $fileName,
                    'audio/wav',
                    null,
                    true
                );

                $result = $transcriptionService->transcribeAudio($uploadedFile, $language, function ($state) use ($job, &$manifest, $currentChunkIndex, $totalChunks) {
                    if ($state === 'diarizing') {
                        $manifest['progress'] = [
                            'current_chunk' => $currentChunkIndex,
                            'total_chunks' => $totalChunks,
                            'phase' => 'diarizing',
                        ];
                        $job->update([
                            'status' => 'transcribing',
                            'manifest_data' => $manifest,
                        ]);
                    }
                }, false);

                if (! empty($result['segments'])) {
                    foreach ($result['segments'] as $segment) {
                        $segment['start'] += $startTime;
                        $segment['end'] += $startTime;

                        // Auch die Wörter innerhalb des Segments anpassen, falls vorhanden
                        if (isset($segment['words']) && is_array($segment['words'])) {
                            foreach ($segment['words'] as &$w) {
                                $w['start'] += $startTime;
                                $w['end'] += $startTime;
                            }
                        }

                        $allSegments[] = $segment;
                    }
                }
                if (! empty($result['words'])) {
                    foreach ($result['words'] as $word) {
                        $word['start'] += $startTime;
                        $word['end'] += $startTime;
                        $allWords[] = $word;
                    }
                }
                if (! empty($result['text'])) {
                    $fullText .= ' '.trim($result['text']);
                }

                @unlink($tmpPath);
            } catch (\Exception $e) {
                $job->update(['status' => 'failed', 'error_message' => 'Chunk failed: '.$e->getMessage()]);
                @unlink($tmpPath);

                // Fehler weiterwerfen, damit der Daemon ihn melden kann
                throw new \RuntimeException("Failed to transcribe chunk {$fileName} for job {$job->id}: ".$e->getMessage(), 0, $e);
            }
        }

        $mergedResult = [
            'text' => trim($fullText),
            'segments' => $allSegments,
            'words' => $allWords,
            'language' => $language ?? 'de',
            'success' => true,
        ];

        if ($diarize) {
            // Diarization over the original file
            $manifest['progress'] = [
                'phase' => 'diarizing',
                'current_chunk' => 0,
                'total_chunks' => 0,
            ];
            $job->update([
                'status' => 'transcribing',
                'manifest_data' => $manifest,
            ]);

            $originalKey = $job->file_path;
            $originalExt = pathinfo($originalKey, PATHINFO_EXTENSION);
            $tmpOriginalPath = sys_get_temp_dir().'/'.uniqid('original_').'.'.($originalExt ?: 'wav');

            try {
                $s3Stream = $s3Disk->readStream($originalKey);
                if ($s3Stream) {
                    $tmpStream = fopen($tmpOriginalPath, 'w+');
                    stream_copy_to_stream($s3Stream, $tmpStream);
                    fclose($s3Stream);
                    fclose($tmpStream);

                    $diarizationOptions = [];
                    if ($speakerCount !== 'auto' && is_numeric($speakerCount)) {
                        $diarizationOptions['num_speakers'] = (int) $speakerCount;
                    }
                    if (isset($settings['speaker_mapping'])) {
                        $diarizationOptions['speaker_mapping'] = $settings['speaker_mapping'];
                    }

                    // Combine extracted snippets and speaker mapping for direct embedding
                    $extractedSnippets = $manifest['extracted_snippets'] ?? null;
                    $speakerSnippets = $settings['speaker_snippets'] ?? null;

                    if ($speakerSnippets) {
                        $knownNames = [];
                        $knownReferences = [];
                        foreach ($speakerSnippets as $snip) {
                            $spId = $snip['id'];

                            $b64 = null;
                            if (isset($extractedSnippets[$spId])) {
                                $b64 = $extractedSnippets[$spId];
                            } else {
                                // Fallback: extract inline if python worker didn't provide it
                                $b64 = $this->extractSnippetBase64($manifest, (float) $snip['start'], (float) $snip['end']);
                            }

                            if ($b64) {
                                $knownNames[] = $snip['name'];
                                $knownReferences[] = $b64;
                            }
                        }
                        if (! empty($knownNames)) {
                            $diarizationOptions['known_speaker_names'] = $knownNames;
                            $diarizationOptions['known_speaker_references'] = $knownReferences;
                        }
                    }

                    $mergedResult = $transcriptionService->diarizeAudio($tmpOriginalPath, $mergedResult, $diarizationOptions);

                    @unlink($tmpOriginalPath);
                }
            } catch (\Exception $e) {
                Log::warning("Failed to run diarization on full file for job {$job->id}: ".$e->getMessage());
            }
        }

        // Apply LLM speaker optimization automatically if enabled
        $runLlmCorrection = (bool) ($settings['llm_correction'] ?? false);
        if ($runLlmCorrection && ! empty($mergedResult['segments'])) {
            try {
                $manifest['progress'] = [
                    'phase' => 'optimizing',
                    'current_chunk' => 0,
                    'total_chunks' => 0,
                ];
                $job->update([
                    'manifest_data' => $manifest,
                ]);

                Log::info("Running automatic LLM speaker optimization for job {$job->id}");
                $mergedResult['segments'] = $this->optimizeTranscriptSpeakers($mergedResult['segments']);

                // Reconstruct full text based on corrected segments to keep them in sync
                $fullText = '';
                foreach ($mergedResult['segments'] as $seg) {
                    $fullText .= ' '.trim($seg['text']);
                }
                $mergedResult['text'] = trim($fullText);
            } catch (\Exception $e) {
                Log::warning("Automatic LLM speaker optimization failed for job {$job->id}: ".$e->getMessage());
            }
        }

        // Job abschließen
        $job->update([
            'status' => 'completed',
            'result_data' => $mergedResult,
        ]);
        Log::info("Job {$job->id} completed transcription successfully.");
    }

    /**
     * Extracts a base64 WAV snippet from the preprocessed chunks stored in S3.
     */
    protected function extractSnippetBase64(array $manifest, float $start, float $end): ?string
    {
        $s3Disk = \Illuminate\Support\Facades\Storage::disk('s3');
        $bucketPrefix = 's3://'.config('filesystems.disks.s3.bucket').'/';
        $chunks = $manifest['chunks'] ?? [];
        if (empty($chunks)) {
            return null;
        }

        // Finde den passenden Chunk
        $targetChunk = null;
        foreach ($chunks as $chunk) {
            $chunkStart = (float) ($chunk['start'] ?? 0);
            $chunkEnd = (float) ($chunk['end'] ?? 0);
            if ($start >= $chunkStart && $start < $chunkEnd) {
                $targetChunk = $chunk;
                break;
            }
        }

        if (! $targetChunk) {
            // Fallback auf den ersten Chunk, wenn die Zeit überlappt
            $targetChunk = $chunks[0];
        }

        $chunkStart = (float) ($targetChunk['start'] ?? 0);
        $relativeStart = max(0, $start - $chunkStart);
        $duration = $end - $start;

        $chunkKey = str_replace($bucketPrefix, '', $targetChunk['path']);

        try {
            $stream = $s3Disk->readStream($chunkKey);
            if (! $stream) {
                return null;
            }

            // WAV = 44 bytes header + 16kHz Mono 16-bit PCM (32000 bytes/sec)
            $bytesPerSec = 32000;
            $offset = 44 + (int) ($relativeStart * $bytesPerSec);
            $length = (int) ($duration * $bytesPerSec);
            if ($offset % 2 !== 0) {
                $offset--;
            } // 16-bit alignment

            $header = stream_get_contents($stream, 44);
            stream_get_contents($stream, $offset - 44);
            $data = stream_get_contents($stream, $length);
            fclose($stream);

            if (strlen($data) === 0) {
                return null;
            }

            $subchunk2Size = strlen($data);
            $chunkSize = 36 + $subchunk2Size;

            $header = substr_replace($header, pack('V', $chunkSize), 4, 4);
            $header = substr_replace($header, pack('V', $subchunk2Size), 40, 4);

            return 'data:audio/wav;base64,'.base64_encode($header.$data);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to extract snippet: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Optimiert die Sprecherzuordnung im Transkript semantisch mithilfe von KI.
     */
    public function optimizeTranscriptSpeakers(array $segments, ?string $model = null): array
    {
        // Textdarstellung der Segmente für das LLM vorbereiten
        $formattedTranscript = '';
        foreach ($segments as $idx => $seg) {
            $formattedTranscript .= 'Segment ['.$idx.'] ('.($seg['speaker'] ?? 'Unbekannt').'): '.($seg['text'] ?? '')."\n";
        }

        $prompt = "Du bist ein Experte für Gesprächsprotokolle und Transkriptionen.\n".
                  "Hier ist ein Transkript, bei dem die akustische Sprecherzuordnung (Diarization) fehlerhaft sein kann und manche Wörter falsch transkribiert wurden.\n".
                  "Deine Aufgabe ist es, das Transkript semantisch zu analysieren, Fehler in der Sprecherzuordnung zu korrigieren, Falschschreibungen/Hörfehler von Wörtern auszubessern und das Ergebnis als JSON zurückzugeben.\n\n".
                  "HÄUFIGE DIARIZATION-FEHLER (die du korrigieren musst):\n".
                  "1. Kurze Halbsätze, Satzanfänge (z. B. 'Oh, danke', 'Guten Morgen') oder persönliche Anreden/Namen wurden dem vorherigen/nächsten Sprecher zugeordnet, obwohl sie semantisch zu einem anderen gehören.\n".
                  "2. Kurze Reaktionen, Einwürfe oder Antworten (wie 'Schön!', 'Na gut.', 'Na klar.', 'Ja.', 'Nein.') am Ende eines langen Redebeitrags wurden fälschlicherweise dem vorherigen Sprecher zugeordnet, obwohl sie dem Gesprächspartner gehören. Analysiere das Frage-Antwort-Muster und den Dialogkontext logisch und korrigiere den Sprecher für solche Einwürfe.\n".
                  "3. Kurze Einwürfe oder Reaktionen (z. B. 'Oh Mann!', 'Ach so!', 'Echt?', 'Stimmt.') mitten in einem Segment können einem anderen Sprecher gehören. Wenn ein Sprecher einen Satz beendet, der Gesprächspartner kurz reagiert und der erste Sprecher danach fortfährt, teile das Segment auf und ordne den Einwurf dem Gesprächspartner zu.\n\n".
                  "KORREKTUR VON HÖRFEHLERN / TRANSKRIPTIONSFEHLERN:\n".
                  "- Manchmal erkennt die Spracherkennung (Whisper) bestimmte Wörter, Namen, Eigennamen oder Fachbegriffe nicht korrekt oder unvollständig (z. B. Halluzinationen, akustische Missverständnisse wie 'katamau' statt 'Kater Mau' oder 'projektfönix' statt 'Projekt Phoenix').\n".
                  "- Analysiere den Kontext des gesamten Transkripts: Wenn ein Begriff an einer Stelle falsch/akustisch entstellt transkribiert wurde, aber an einer anderen Stelle oder im Kontext korrekt vorkommt (z. B. 'Kater Mau' oder 'Projekt Phoenix'), korrigiere das fehlerhafte Wort an allen betroffenen Stellen im Text, damit es konsistent und korrekt ist.\n\n".
                  "REGELN FÜR DIE RÜCKGABE:\n".
                  "- Ändere den Text nur zur Behebung von eindeutigen Hörfehlern/Falschschreibungen basierend auf dem Gesprächskontext. Füge keine eigenen Sätze hinzu und lasse keine inhaltlichen Teile weg.\n".
                  "- Du darfst Segmente in kleinere Untersegmente aufteilen (splitten), wenn innerhalb eines Segments der Sprecher wechselt. Jedes Untersegment erhält denselben 'original_index'.\n".
                  "- WICHTIG: Jedes Eingabesegment MUSS exakt über sein original_index referenziert werden. Der Index entspricht der Zahl X in 'Segment [X]'. Du darfst unter keinen Umständen Indizes neu nummerieren, verschieben oder auslassen! Für jedes Eingabesegment X muss es mindestens ein Objekt mit 'original_index': X geben.\n".
                  "- Verwende ausschließlich die im bereitgestellten Transkript vorkommenden Sprechernamen. Erfinde keine neuen Namen.\n".
                  "- Das JSON-Feld 'text' darf unter keinen Umständen Bezeichner wie 'Segment [X]' oder Sprechernamen am Anfang enthalten.\n\n".
                  "Hier ist das Transkript:\n".
                  $formattedTranscript."\n".
                  "Gib das Ergebnis ausschließlich als JSON-Array von Objekten zurück, wobei jedes Objekt folgende Felder hat:\n".
                  "- \"original_index\": Die Zahl X des Originalsegments \"Segment [X]\" aus der Eingabe (MUSS exakt übereinstimmen, KEINE Neunummerierung!).\n".
                  "- \"text\": Der bereinigte und korrigierte Text dieses (Unter-)Segments.\n".
                  "- \"speaker\": Der korrigierte Sprechername (muss exakt einer der Sprechernamen aus dem obigen Transkript sein!).\n\n".
                  "Beispiel-Antwort:\n".
                  "[\n".
                  "  {\"original_index\": 0, \"text\": \"Guten Morgen allerseits. Wir wollen heute über das neue Projekt Phoenix sprechen.\", \"speaker\": \"Sprecher 1\"},\n".
                  "  {\"original_index\": 0, \"text\": \"Guten Morgen, Herr Schmidt.\", \"speaker\": \"Sprecher 2\"},\n".
                  "  {\"original_index\": 1, \"text\": \"Ich habe mir die Zahlen angeschaut.\", \"speaker\": \"Sprecher 2\"},\n".
                  "  {\"original_index\": 1, \"text\": \"Oh Mann!\", \"speaker\": \"Sprecher 1\"},\n".
                  "  {\"original_index\": 1, \"text\": \"Aber wir müssen noch etwas warten.\", \"speaker\": \"Sprecher 2\"},\n".
                  "  {\"original_index\": 2, \"text\": \"Das passt so. Auf jeden Fall läuft das Projekt Phoenix stabil.\", \"speaker\": \"Sprecher 1\"}\n".
                  "]\n".
                  'Antworte NUR mit dem validen JSON-Array. Keine Einleitung, keine Erklärung, kein Markdown-Fencing (kein ```json).';

        $aiService = app(\App\Services\AI\AiService::class);
        $aiConfigService = app(\App\Services\AI\Config\AiConfigService::class);
        $defaultModels = $aiConfigService->getDefaultModels();
        $resolvedModel = $model ?? $defaultModels['default_model'] ?? 'gpt-4o';

        $payload = [
            'model' => $resolvedModel,
            'stream' => false,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => ['text' => 'Du bist ein präziser Helfer, der Sprecherzuordnungen und Sprecherwechsel in Transkripten logisch korrigiert und ausschließlich valides JSON antwortet.'],
                ],
                [
                    'role' => 'user',
                    'content' => ['text' => $prompt],
                ],
            ],
        ];

        $response = $aiService->sendRequest($payload);

        $rawContent = '';
        if (is_object($response) && isset($response->content)) {
            $content = $response->content;
            if (is_array($content)) {
                $rawContent = $content['text'] ?? ($content[0]['text'] ?? '');
            } elseif (is_string($content)) {
                $rawContent = $content;
            }
        }

        // Bereinige eventuelle Markdown-Tags, falls die KI sich nicht an die Vorgabe gehalten hat
        $jsonString = trim($rawContent);
        if (str_starts_with($jsonString, '```')) {
            $jsonString = preg_replace('/^```(?:json)?\n?/i', '', $jsonString);
            $jsonString = preg_replace('/```$/', '', $jsonString);
            $jsonString = trim($jsonString);
        }

        $corrections = json_decode($jsonString, true);

        if (! is_array($corrections)) {
            Log::error('AI Speaker Optimization returned invalid JSON', ['raw' => $rawContent]);
            throw new \Exception('Die KI hat keine gültige JSON-Antwort geliefert.');
        }

        // Untersegmente nach original_index gruppieren
        $groupedCorrections = [];
        foreach ($corrections as $corr) {
            if (isset($corr['original_index']) && isset($corr['text']) && isset($corr['speaker'])) {
                $groupedCorrections[$corr['original_index']][] = $corr;
            }
        }

        // Alle bekannten Sprechernamen sammeln, um Präfixe wie "Sprecher 1:" oder "Conny:" im Text zu entfernen
        $uniqueSpeakers = array_unique(array_column($segments, 'speaker'));
        foreach ($corrections as $corr) {
            if (isset($corr['speaker'])) {
                $uniqueSpeakers[] = $corr['speaker'];
            }
        }
        $uniqueSpeakers = array_unique(array_filter($uniqueSpeakers));

        $escapedSpeakers = array_map(function ($sp) {
            return preg_quote((string) $sp, '/');
        }, $uniqueSpeakers);

        // Generische Fallbacks hinzufügen
        $escapedSpeakers[] = 'Sprecher\s+\d+';
        $escapedSpeakers[] = 'Unbekannt';

        $speakerPrefixRegex = '/^('.implode('|', $escapedSpeakers).'):\s*/ui';
        $speakerInlineRegex = '/\s+('.implode('|', $escapedSpeakers).'):\s*/ui';

        $newSegments = [];
        foreach ($segments as $idx => $parentSeg) {
            // Falls keine Korrekturen für diesen Index geliefert wurden, behalte das Originalsegment
            if (! isset($groupedCorrections[$idx]) || empty($groupedCorrections[$idx])) {
                $newSegments[] = $parentSeg;

                continue;
            }

            $subSegs = $groupedCorrections[$idx];
            $parentStart = $parentSeg['start'];
            $parentEnd = $parentSeg['end'];
            $parentDuration = $parentEnd - $parentStart;

            // Gesamtlänge des korrigierten Textes für diesen Index berechnen
            $totalTextLength = 0;
            foreach ($subSegs as $sub) {
                $totalTextLength += strlen($sub['text']);
            }

            if ($totalTextLength <= 0) {
                $newSegments[] = $parentSeg;

                continue;
            }

            // Untersegmente erzeugen und Timestamps interpolieren
            $currentStart = $parentStart;
            foreach ($subSegs as $subIdx => $sub) {
                $subLength = strlen($sub['text']);
                $subDuration = $parentDuration * ($subLength / $totalTextLength);
                $subEnd = $currentStart + $subDuration;

                // Letztes Untersegment exakt auf parentEnd setzen, um Rundungsfehler zu vermeiden
                if ($subIdx === count($subSegs) - 1) {
                    $subEnd = $parentEnd;
                }

                $cleanText = $sub['text'];
                $cleanText = preg_replace($speakerPrefixRegex, '', $cleanText);
                $cleanText = preg_replace($speakerInlineRegex, ' ', $cleanText);

                $newSeg = $parentSeg;
                $newSeg['start'] = round($currentStart, 2);
                $newSeg['end'] = round($subEnd, 2);
                $newSeg['text'] = trim($cleanText);
                $newSeg['speaker'] = $sub['speaker'];

                // Falls das Elternsegment Wörter mit Timestamps hatte, filtern und zuweisen
                if (isset($parentSeg['words']) && is_array($parentSeg['words'])) {
                    $newSeg['words'] = array_values(array_filter($parentSeg['words'], function ($word) use ($currentStart, $subEnd) {
                        $wMid = ($word['start'] + $word['end']) / 2;

                        return $wMid >= $currentStart && $wMid <= $subEnd;
                    }));
                }

                $newSegments[] = $newSeg;
                $currentStart = $subEnd;
            }
        }

        // Zusammenhängende Segmente desselben Sprechers mergen, falls die Lücke klein ist (< 3 Sekunden)
        $mergedSegments = [];
        $currentSegment = null;

        foreach ($newSegments as $segment) {
            if ($currentSegment === null) {
                $currentSegment = $segment;

                continue;
            }

            $gap = $segment['start'] - $currentSegment['end'];

            if ($segment['speaker'] === $currentSegment['speaker'] && $gap < 3.0) {
                $currentSegment['end'] = $segment['end'];
                $currentSegment['text'] = trim($currentSegment['text']).' '.trim($segment['text']);
                if (isset($segment['words']) && is_array($segment['words'])) {
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

        // IDs neu vergeben
        foreach ($mergedSegments as $mIdx => &$mergedSeg) {
            $mergedSeg['id'] = $mIdx + 1;
        }
        unset($mergedSeg);

        return $mergedSegments;
    }

    /**
     * Generiert eine Presigned URL fuer das Audio-Streaming aus S3 fuer einen bestimmten Job oder ein Transkript.
     */
    public function getAudioPresignedUrl(int $userId, ?string $jobId = null, ?string $slug = null, ?int $index = null): ?string
    {
        $job = null;

        if ($jobId) {
            $job = TranscriptionJob::where('id', $jobId)->where('user_id', $userId)->first();
        }

        if (! $job && $slug) {
            $transcription = \App\Models\Transcription\Transcription::where('slug', $slug)->where('user_id', $userId)->first();
            if ($transcription) {
                // 1. Try to find job_id from metadata.source_files
                $metadata = $transcription->metadata;
                if (isset($metadata['source_files']) && is_array($metadata['source_files'])) {
                    $idx = $index !== null ? $index : 0;
                    if (isset($metadata['source_files'][$idx]['job_id'])) {
                        $jid = $metadata['source_files'][$idx]['job_id'];
                        $job = TranscriptionJob::where('id', $jid)->where('user_id', $userId)->first();
                    }
                }

                // 2. Try to find job_id from metadata.job_id (single file save)
                if (! $job && isset($metadata['job_id'])) {
                    $job = TranscriptionJob::where('id', $metadata['job_id'])->where('user_id', $userId)->first();
                }

                // 3. Fallback: search for a job with transcription_id
                if (! $job) {
                    $job = TranscriptionJob::where('transcription_id', $transcription->id)->where('user_id', $userId)->first();
                }

                // 4. Ultimate Fallback for old transcripts: match by filename, size, and proximity of creation time (within 2 hours)
                if (! $job) {
                    $createdAt = $transcription->created_at;
                    $idx = $index !== null ? $index : 0;

                    $targetFilename = null;
                    $targetSize = null;
                    if (isset($metadata['source_files'][$idx])) {
                        if (isset($metadata['source_files'][$idx]['name'])) {
                            $targetFilename = $metadata['source_files'][$idx]['name'];
                        }
                        if (isset($metadata['source_files'][$idx]['size'])) {
                            $targetSize = (int) $metadata['source_files'][$idx]['size'];
                        }
                    } else {
                        // Fallback to splitting original_filename by comma
                        $parts = explode(',', $transcription->original_filename ?? '');
                        if (isset($parts[$idx])) {
                            $targetFilename = trim($parts[$idx]);
                        }
                    }

                    // Get all candidate jobs for this user in that 2-hour window
                    $jobsInWindow = TranscriptionJob::where('user_id', $userId)
                        ->where('created_at', '>=', $createdAt->copy()->subHours(2))
                        ->where('created_at', '<=', $createdAt->copy()->addHours(2))
                        ->get();

                    // 4a. Try to match by filename first if targetFilename is present
                    if ($targetFilename && $jobsInWindow->isNotEmpty()) {
                        foreach ($jobsInWindow as $candidateJob) {
                            $filenameInSettings = $candidateJob->manifest_data['settings']['filename'] ?? null;
                            if ($filenameInSettings === $targetFilename || ($candidateJob->file_path && str_contains($candidateJob->file_path, $targetFilename))) {
                                $job = $candidateJob;
                                break;
                            }
                        }
                    }

                    // 4b. Try to match by size if targetSize is present and we haven't found a job yet
                    if (! $job && $targetSize !== null && $jobsInWindow->isNotEmpty()) {
                        foreach ($jobsInWindow as $candidateJob) {
                            if ($candidateJob->file_path) {
                                try {
                                    $candidateSize = Storage::disk('s3')->size($candidateJob->file_path);
                                    if ((int) $candidateSize === $targetSize) {
                                        $job = $candidateJob;
                                        break;
                                    }
                                } catch (\Exception $e) {
                                    // ignore size check errors
                                }
                            }
                        }
                    }

                    // 4c. Ultimate chronological/fallback match: sort by created_at (asc) and take the one at $idx.
                    if (! $job && $jobsInWindow->isNotEmpty()) {
                        $sortedJobs = $jobsInWindow->sortBy('created_at');
                        $job = $sortedJobs->values()->get($idx) ?? $sortedJobs->first();
                    }
                }
            }
        }

        if (! $job || ! $job->file_path) {
            return null;
        }

        $s3Disk = Storage::disk('s3');
        if (! $s3Disk->exists($job->file_path)) {
            return null;
        }

        // Return a mock URL if getClient() is not available (e.g. during testing with Storage::fake())
        if (! method_exists($s3Disk, 'getClient')) {
            return $s3Disk->url($job->file_path);
        }

        /** @var \Aws\S3\S3Client $client */
        $client = $s3Disk->getClient();

        $command = $client->getCommand('GetObject', [
            'Bucket' => config('filesystems.disks.s3.bucket'),
            'Key' => $job->file_path,
        ]);

        $presignedRequest = $client->createPresignedRequest($command, '+2 hours');
        $presignedUrl = (string) $presignedRequest->getUri();

        $s3Endpoint = config('filesystems.disks.s3.endpoint');
        $appUrl = config('app.url');

        if ($s3Endpoint && str_contains($presignedUrl, $s3Endpoint)) {
            $presignedUrl = str_replace($s3Endpoint, rtrim($appUrl, '/').'/s3', $presignedUrl);
        }

        return $presignedUrl;
    }
}
