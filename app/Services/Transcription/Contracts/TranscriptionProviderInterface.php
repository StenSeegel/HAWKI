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
     * @return array{text: string, segments: array, words: array}
     */
    public function transcribeAudio($audioFile, ?string $language = null, ?callable $onProgress = null): array;

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
