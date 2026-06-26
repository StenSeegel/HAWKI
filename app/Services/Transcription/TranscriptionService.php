<?php

namespace App\Services\Transcription;

use Exception;

/**
 * Service class for handling audio transcriptions.
 * Uses TranscriptionFactory to resolve the appropriate provider.
 */
class TranscriptionService
{
    /**
     * Transkribiert eine Audiodatei
     *
     * @param  \Illuminate\Http\UploadedFile  $audioFile
     *
     * @throws Exception
     */
    public function transcribeAudio($audioFile, ?string $language = null, ?callable $onProgress = null, bool $diarize = true): array
    {
        $provider = TranscriptionFactory::create();

        return $provider->transcribeAudio($audioFile, $language, $onProgress, $diarize);
    }

    /**
     * Transcribe multiple audio files in parallel.
     */
    public function transcribeAudioParallel(array $audioFiles, ?string $language = null): array
    {
        $provider = TranscriptionFactory::create();

        return $provider->transcribeAudioParallel($audioFiles, $language);
    }

    /**
     * Führt Diarization auf einer Audiodatei aus und ordnet die Speaker den Transkriptions-Segmenten zu.
     */
    public function diarizeAudio(string $audioPath, array $result, array $options = []): array
    {
        $provider = TranscriptionFactory::create();

        return $provider->diarizeAudio($audioPath, $result, $options);
    }

    /**
     * Prüft den Status einer Transkription
     */
    public function getTranscriptionStatus($jobId): array
    {
        $provider = TranscriptionFactory::create();

        return $provider->getTranscriptionStatus($jobId);
    }

    /**
     * Gibt Informationen über die aktuelle Konfiguration zurück
     */
    public function getConfiguration(): array
    {
        $provider = TranscriptionFactory::create();

        return $provider->getConfiguration();
    }

    /**
     * Testet die Verbindung zur API
     */
    public function testConnection(): array
    {
        $provider = TranscriptionFactory::create();

        return $provider->testConnection();
    }
}
