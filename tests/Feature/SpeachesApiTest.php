<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * @group api
 * @group speaches
 */
class SpeachesApiTest extends TestCase
{
    protected string $baseUrl = 'http://134.176.150.177/v1';

    /**
     * Teste, ob die Modelle geladen werden können
     */
    public function test_can_fetch_models()
    {
        $response = Http::timeout(5)->get("{$this->baseUrl}/models");

        $this->assertTrue($response->successful(), 'Models request failed: '.$response->body());
        $this->assertArrayHasKey('data', $response->json());
    }

    public static function audioFileProvider()
    {
        $files = glob(__DIR__.'/transcription/*.mp3');
        $data = [];
        foreach ($files as $file) {
            $data[basename($file)] = [$file];
        }

        return $data;
    }

    /**
     * @dataProvider audioFileProvider
     */
    public function test_transcription_endpoint(string $audioPath)
    {
        if (! file_exists($audioPath)) {
            $this->markTestSkipped("Test-Audiofile existiert nicht: {$audioPath}");
        }

        $apiKey = 'speaches_direct_token';

        $response = Http::timeout(120)->withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
        ])->attach(
            'file',
            file_get_contents($audioPath),
            basename($audioPath)
        )->post("{$this->baseUrl}/audio/transcriptions", [
            'model' => 'Systran/faster-whisper-large-v3',
            'response_format' => 'verbose_json',
        ]);

        $this->assertTrue($response->successful(), 'Transcription request failed: '.$response->body());
        $result = $response->json();

        $this->assertArrayHasKey('text', $result);
        $this->assertArrayHasKey('segments', $result);
    }

    /**
     * @dataProvider audioFileProvider
     */
    public function test_diarization_endpoint(string $audioPath)
    {
        if (! file_exists($audioPath)) {
            $this->markTestSkipped("Test-Audiofile existiert nicht: {$audioPath}");
        }

        $apiKey = 'speaches_direct_token';

        $response = Http::timeout(300)->withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
        ])->attach(
            'file',
            file_get_contents($audioPath),
            basename($audioPath)
        )->post("{$this->baseUrl}/audio/diarization", [
            'model' => 'pyannote/speaker-diarization-community-1',
        ]);

        $this->assertTrue($response->successful(), "Diarization failed with Status {$response->status()}: ".$response->body());

        $result = $response->json();
        $this->assertArrayHasKey('segments', $result);

        if (! empty($result['segments'])) {
            $this->assertArrayHasKey('speaker', $result['segments'][0]);
        }
    }
}
