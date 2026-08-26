<?php

namespace Tests\Feature;

use App\Services\Transcription\TranscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * @group api
 * @group speaches
 */
class DiarizationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_transcribe_and_diarize()
    {
        $audioPath = public_path('audio/notification1.mp3');
        if (! file_exists($audioPath)) {
            $this->markTestSkipped("Test-Audiofile existiert nicht: {$audioPath}");
        }

        $service = app(TranscriptionService::class);
        $file = new UploadedFile($audioPath, 'notification1.mp3', 'audio/mpeg', null, true);

        $result = $service->transcribeAudio($file, 'de');

        $this->assertArrayHasKey('text', $result);
        $this->assertArrayHasKey('segments', $result);

        if (! empty($result['segments'])) {
            $this->assertArrayHasKey('speaker', $result['segments'][0]);
            // Just echo it to ensure we can see it
            echo "\nSegments:\n";
            echo json_encode($result['segments'], JSON_PRETTY_PRINT)."\n";
        }
    }
}
