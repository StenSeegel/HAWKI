<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Transcription\Providers\CustomSpeachesProvider;
use App\Services\Transcription\TranscriptionSettingsService;
use Tests\TestCase;

class CustomSpeachesDiarizationTest extends TestCase
{
    protected function getProvider(): CustomSpeachesProvider
    {
        $settingsService = $this->createMock(TranscriptionSettingsService::class);
        $settingsService->method('get')->willReturnCallback(function (string $key, $default = null) {
            if ($key === 'base_url') {
                return 'http://localhost';
            }
            if ($key === 'api_key') {
                return 'test_key';
            }
            if ($key === 'model') {
                return 'test_model';
            }

            return $default;
        });

        return new CustomSpeachesProvider($settingsService);
    }

    public function test_maps_speaker_when_api_returns_names_directly(): void
    {
        $provider = $this->getProvider();

        $result = [
            'segments' => [
                [
                    'start' => 0.0,
                    'end' => 15.0,
                    'text' => 'Hello from Speaker 1.',
                ],
                [
                    'start' => 15.0,
                    'end' => 30.0,
                    'text' => 'Hello from Speaker 2.',
                ],
            ],
        ];

        $diarizationSegments = [
            ['start' => 0.0, 'end' => 15.0, 'speaker' => 'Speaker 1'],
            ['start' => 15.0, 'end' => 30.0, 'speaker' => 'Speaker 2'],
        ];

        $options = [
            'speaker_mapping' => [
                'SPEAKER_00' => 'Speaker 1',
                'SPEAKER_01' => 'Speaker 2',
            ],
            'known_speaker_names' => ['Speaker 1', 'Speaker 2'],
        ];

        $mappedResult = $provider->mapDiarizationSegments($result, $diarizationSegments, $options);

        $this->assertCount(2, $mappedResult['segments']);
        $this->assertEquals('Speaker 1', $mappedResult['segments'][0]['speaker']);
        $this->assertEquals('Speaker 2', $mappedResult['segments'][1]['speaker']);
    }

    public function test_maps_speaker_when_api_returns_speaker_ids(): void
    {
        $provider = $this->getProvider();

        $result = [
            'segments' => [
                [
                    'start' => 0.0,
                    'end' => 15.0,
                    'text' => 'Hello from Speaker 1.',
                ],
                [
                    'start' => 15.0,
                    'end' => 30.0,
                    'text' => 'Hello from Speaker 2.',
                ],
            ],
        ];

        $diarizationSegments = [
            ['start' => 0.0, 'end' => 15.0, 'speaker' => 'SPEAKER_00'],
            ['start' => 15.0, 'end' => 30.0, 'speaker' => 'SPEAKER_01'],
        ];

        $options = [
            'speaker_mapping' => [
                'SPEAKER_00' => 'Speaker 1',
                'SPEAKER_01' => 'Speaker 2',
            ],
            'known_speaker_names' => ['Speaker 1', 'Speaker 2'],
        ];

        $mappedResult = $provider->mapDiarizationSegments($result, $diarizationSegments, $options);

        $this->assertCount(2, $mappedResult['segments']);
        $this->assertEquals('Speaker 1', $mappedResult['segments'][0]['speaker']);
        $this->assertEquals('Speaker 2', $mappedResult['segments'][1]['speaker']);
    }

    public function test_maps_unknown_speaker_to_next_available(): void
    {
        $provider = $this->getProvider();

        $result = [
            'segments' => [
                [
                    'start' => 0.0,
                    'end' => 15.0,
                    'text' => 'Hello from Speaker 1.',
                ],
                [
                    'start' => 15.0,
                    'end' => 30.0,
                    'text' => 'Hello from Unknown Speaker.',
                ],
            ],
        ];

        $diarizationSegments = [
            ['start' => 0.0, 'end' => 15.0, 'speaker' => 'SPEAKER_00'],
            ['start' => 15.0, 'end' => 30.0, 'speaker' => 'SPEAKER_02'],
        ];

        $options = [
            'speaker_mapping' => [
                'SPEAKER_00' => 'Speaker 1',
                'SPEAKER_01' => 'Speaker 2',
            ],
            'known_speaker_names' => ['Speaker 1', 'Speaker 2'],
        ];

        $mappedResult = $provider->mapDiarizationSegments($result, $diarizationSegments, $options);

        $this->assertCount(2, $mappedResult['segments']);
        $this->assertEquals('Speaker 1', $mappedResult['segments'][0]['speaker']);
        $this->assertEquals('Sprecher 3', $mappedResult['segments'][1]['speaker']);
    }

    public function test_real_diarization_e2e(): void
    {
        $audioPath = __DIR__.'/transcription/mockup_diarization.mp3';
        if (! file_exists($audioPath)) {
            $this->markTestSkipped("Mockup audio file not found: {$audioPath}");
        }

        $settingsService = $this->createMock(TranscriptionSettingsService::class);
        $settingsService->method('get')->willReturnCallback(function (string $key, $default = null) {
            if ($key === 'base_url') {
                return 'http://134.176.150.177/v1';
            }
            if ($key === 'api_key') {
                return 'speaches_direct_token';
            }
            if ($key === 'model') {
                return 'Systran/faster-whisper-large-v3';
            }
            if ($key === 'diarization_model') {
                return 'pyannote/speaker-diarization-community-1';
            }

            return $default;
        });

        $provider = new CustomSpeachesProvider($settingsService);

        // 1. Run speaker analysis (Pre-transcription)
        $analysisSegments = $provider->analyzeSpeakers($audioPath);
        echo "\n--- PRE-TRANSCRIPTION SEGMENTS ---\n";
        echo json_encode($analysisSegments, JSON_PRETTY_PRINT)."\n";

        // 2. Perform raw transcription
        $tmpAudioPath = sys_get_temp_dir().'/mockup_diarization_test.mp3';
        copy($audioPath, $tmpAudioPath);
        $uploadedFile = new \Illuminate\Http\UploadedFile(
            $tmpAudioPath,
            'mockup_diarization.mp3',
            'audio/mpeg',
            null,
            true
        );
        $transResult = $provider->transcribeAudio($uploadedFile, 'de', null, false);
        echo "\n--- RAW TRANSCRIPTION RESULT ---\n";
        echo json_encode($transResult, JSON_PRETTY_PRINT)."\n";

        // 3. Build mapping options
        $speakers = [];
        foreach ($analysisSegments as $segment) {
            $speakerName = $segment['speaker'];
            if (! isset($speakers[$speakerName])) {
                $speakers[$speakerName] = [
                    'id' => $speakerName,
                    'start' => $segment['start'],
                ];
            }
        }
        uasort($speakers, function ($a, $b) {
            return $a['start'] <=> $b['start'];
        });

        $speakerMapping = [];
        $knownSpeakerNames = [];
        $knownSpeakerReferences = [];
        $idx = 1;
        foreach ($speakers as $spId => $spData) {
            $name = 'Sprecher '.$idx++;
            $speakerMapping[$spId] = $name;
            $knownSpeakerNames[] = $name;
            $knownSpeakerReferences[] = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQAAAAA=';
        }

        $diarizationOptions = [
            'speaker_mapping' => $speakerMapping,
            'known_speaker_names' => $knownSpeakerNames,
            'known_speaker_references' => $knownSpeakerReferences,
        ];

        // 4. Run post-transcription diarization
        $finalResult = $provider->diarizeAudio($audioPath, $transResult, $diarizationOptions);
        echo "\n--- FINAL DIARIZED RESULT ---\n";
        echo json_encode($finalResult['segments'], JSON_PRETTY_PRINT)."\n";

        $this->assertNotEmpty($finalResult['segments']);
        $this->assertGreaterThan(5, count($finalResult['segments']));
        $this->assertEquals('Sprecher 1', $finalResult['segments'][0]['speaker']);

        $speakers = array_unique(array_column($finalResult['segments'], 'speaker'));
        $this->assertContains('Sprecher 1', $speakers);
        $this->assertContains('Sprecher 2', $speakers);
    }

    public function test_maps_speaker_using_word_timestamps(): void
    {
        $provider = $this->getProvider();

        $result = [
            'segments' => [
                [
                    'id' => 1,
                    'start' => 0.0,
                    'end' => 10.0,
                    'text' => 'Hello. How are you doing today?',
                ],
            ],
            'words' => [
                ['start' => 0.0, 'end' => 2.0, 'word' => 'Hello.'],
                ['start' => 3.0, 'end' => 4.0, 'word' => ' How'],
                ['start' => 4.0, 'end' => 5.0, 'word' => ' are'],
                ['start' => 5.0, 'end' => 6.0, 'word' => ' you'],
                ['start' => 6.0, 'end' => 8.0, 'word' => ' doing'],
                ['start' => 8.0, 'end' => 10.0, 'word' => ' today?'],
            ],
        ];

        $diarizationSegments = [
            ['start' => 0.0, 'end' => 2.5, 'speaker' => 'SPEAKER_00'],
            ['start' => 2.5, 'end' => 10.0, 'speaker' => 'SPEAKER_01'],
        ];

        $options = [
            'speaker_mapping' => [
                'SPEAKER_00' => 'Speaker 1',
                'SPEAKER_01' => 'Speaker 2',
            ],
        ];

        $mappedResult = $provider->mapDiarizationSegments($result, $diarizationSegments, $options);

        // We expect the original segment to be split into two segments because SPEAKER_00 spoke first, then SPEAKER_01!
        $this->assertCount(2, $mappedResult['segments']);

        $seg1 = $mappedResult['segments'][0];
        $this->assertEquals('Hello.', $seg1['text']);
        $this->assertEquals('Speaker 1', $seg1['speaker']);
        $this->assertEquals(0.0, $seg1['start']);
        $this->assertEquals(2.0, $seg1['end']);

        $seg2 = $mappedResult['segments'][1];
        $this->assertEquals('How are you doing today?', $seg2['text']);
        $this->assertEquals('Speaker 2', $seg2['speaker']);
        $this->assertEquals(3.0, $seg2['start']);
        $this->assertEquals(10.0, $seg2['end']);
    }

    public function test_maps_speaker_using_vad_gap_filling(): void
    {
        $provider = $this->getProvider();

        $result = [
            'segments' => [
                [
                    'id' => 1,
                    'start' => 0.0,
                    'end' => 5.0,
                    'text' => 'Hello World.',
                ],
            ],
            'words' => [
                ['start' => 0.0, 'end' => 2.0, 'word' => 'Hello'],
                ['start' => 3.0, 'end' => 5.0, 'word' => ' World.'],
            ],
        ];

        // There is a gap in diarization between 0.0 and 3.0
        $diarizationSegments = [
            ['start' => 3.0, 'end' => 5.0, 'speaker' => 'SPEAKER_01'],
        ];

        $options = [
            'speaker_mapping' => [
                'SPEAKER_00' => 'Speaker 1',
                'SPEAKER_01' => 'Speaker 2',
            ],
            // VAD covers the entire utterance
            'vad_segments' => [
                ['start' => 0.0, 'end' => 5.0],
            ],
        ];

        $mappedResult = $provider->mapDiarizationSegments($result, $diarizationSegments, $options);

        // With VAD gap filling, 'Hello' (midpoint 1.0) is inside VAD segment 0.0-5.0
        // and closest overlapping diarization segment is SPEAKER_01 (3.0-5.0).
        // So both words are mapped to SPEAKER_01.
        // They merge into a single segment of Speaker 2.
        $this->assertCount(1, $mappedResult['segments']);
        $this->assertEquals('Hello World.', $mappedResult['segments'][0]['text']);
        $this->assertEquals('Speaker 2', $mappedResult['segments'][0]['speaker']);
        $this->assertEquals(0.0, $mappedResult['segments'][0]['start']);
        $this->assertEquals(5.0, $mappedResult['segments'][0]['end']);
    }

    public function test_save_real_transcription_for_ui_manual(): void
    {
        $audioPath = __DIR__.'/transcription/mockup_diarization.mp3';
        if (! file_exists($audioPath)) {
            $this->markTestSkipped("Mockup audio file not found: {$audioPath}");
        }

        // Get actual settings from DB
        $settingsService = app(\App\Services\Transcription\TranscriptionSettingsService::class);

        // If api_key is empty in DB, we use speaches_direct_token (default for private instances)
        $apiKey = $settingsService->get('api_key');
        if (empty($apiKey)) {
            $apiKey = 'speaches_direct_token';
            // Mock settings service so it returns this key
            $settingsMock = $this->createMock(\App\Services\Transcription\TranscriptionSettingsService::class);
            $settingsMock->method('get')->willReturnCallback(function (string $key, $default = null) use ($settingsService, $apiKey) {
                if ($key === 'api_key') {
                    return $apiKey;
                }

                return $settingsService->get($key, $default);
            });
            $provider = new CustomSpeachesProvider($settingsMock);
        } else {
            $provider = new CustomSpeachesProvider($settingsService);
        }

        $tmpAudioPath = sys_get_temp_dir().'/mockup_diarization_ui_test.mp3';
        copy($audioPath, $tmpAudioPath);
        $uploadedFile = new \Illuminate\Http\UploadedFile(
            $tmpAudioPath,
            'mockup_diarization.mp3',
            'audio/mpeg',
            null,
            true
        );

        echo "\n--- RUNNING REAL E2E TRANSCRIPTION FOR UI ---\n";
        $result = $provider->transcribeAudio($uploadedFile, 'de', null, true);
        echo 'Transcription completed. Segments count: '.count($result['segments'] ?? [])."\n";

        // Save to Database for User 2 (admin)
        $transcription = \App\Models\Transcription\Transcription::create([
            'title' => 'VAD E2E Test - '.now()->format('d.m.Y H:i:s'),
            'user_id' => 2, // admin user ID
            'language' => $result['language'] ?? 'de',
            'user_locale' => 'de',
            'duration' => (int) ($result['duration'] ?? 174),
            'model_used' => $settingsService->get('model', 'Systran/faster-whisper-large-v3'),
            'provider' => 'custom_speaches',
            'original_filename' => 'mockup_diarization.mp3',
            'file_size' => filesize($audioPath),
        ]);

        $transcription->textData()->create([
            'segments' => $result['segments'],
            'words' => $result['words'] ?? null,
        ]);

        echo "Saved transcription ID: {$transcription->id} to DB. You can view it in the UI now!\n";

        $this->assertDatabaseHas('transcriptions', [
            'id' => $transcription->id,
            'user_id' => 2,
        ]);
    }
}
