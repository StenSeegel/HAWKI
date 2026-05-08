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
    public function transcribeAudio($audioFile, ?string $language = null): array
    {
        $provider = TranscriptionFactory::create();

        return $provider->transcribeAudio($audioFile, $language);
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
