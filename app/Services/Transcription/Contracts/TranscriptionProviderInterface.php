<?php

declare(strict_types=1);

namespace App\Services\Transcription\Contracts;

/**
 * Interface for transcription service providers
 */
interface TranscriptionProviderInterface
{
    /**
     * Transcribe an audio file and optionally perform speaker diarization.
     *
     * @param  \Illuminate\Http\UploadedFile  $audioFile  The audio file to transcribe
     * @param  string|null  $language  Optional language code
     * @param  callable|null  $onProgress  Progress callback
     * @param  bool  $diarize  Whether to perform diarization (if supported)
     * @return array{text: string, segments: array, words: array}
     */
    public function transcribeAudio($audioFile, ?string $language = null, ?callable $onProgress = null, bool $diarize = true): array;

    /**
     * Perform speaker diarization on a complete audio file and map to existing transcription results.
     *
     * @param  string  $audioPath  Path to the local audio file
     * @param  array  $result  The merged transcription result (contains segments/words)
     * @param  array  $options  Optional parameters for diarization (e.g., speaker counts)
     * @return array The updated transcription result with speakers mapped
     */
    public function diarizeAudio(string $audioPath, array $result, array $options = []): array;

    /**
     * Get the provider name/identifier
     */
    public function getName(): string;

    /**
     * Get the status of a transcription job
     *
     * @param  string|int  $jobId
     */
    public function getTranscriptionStatus($jobId): array;

    /**
     * Get the configuration for the provider
     */
    public function getConfiguration(): array;

    /**
     * Test the connection to the provider's API
     */
    public function testConnection(): array;
}
