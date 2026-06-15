<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Transcription\TranscriptionText;
use PHPUnit\Framework\TestCase;

class TranscriptionTextTest extends TestCase
{
    /**
     * Test formatting transcription segments to plain text transcript.
     */
    public function test_transcript_from_segments_formats_correctly(): void
    {
        $segments = [
            [
                'start' => 0.0,
                'end' => 2.5,
                'text' => 'Hallo, wie geht es dir?',
                'speaker' => 'Sprecher 1',
            ],
            [
                'start' => 2.5,
                'end' => 5.0,
                'text' => 'Mir geht es gut, danke!',
                'speaker' => 'Sprecher 2',
            ],
        ];

        $expected = "Sprecher 1: Hallo, wie geht es dir?\n\nSprecher 2: Mir geht es gut, danke!";
        $actual = TranscriptionText::transcriptFromSegments($segments);

        $this->assertEquals($expected, $actual);
    }

    /**
     * Test formatting segments when speaker is empty or missing.
     */
    public function test_transcript_from_segments_handles_missing_speakers(): void
    {
        $segments = [
            [
                'start' => 0.0,
                'end' => 2.5,
                'text' => 'Erster Text ohne Sprecher.',
            ],
            [
                'start' => 2.5,
                'end' => 5.0,
                'text' => 'Zweiter Text mit leerem Sprecher.',
                'speaker' => '',
            ],
        ];

        $expected = "Erster Text ohne Sprecher.\n\nZweiter Text mit leerem Sprecher.";
        $actual = TranscriptionText::transcriptFromSegments($segments);

        $this->assertEquals($expected, $actual);
    }

    /**
     * Test formatting when segments are empty.
     */
    public function test_transcript_from_segments_handles_empty_segments(): void
    {
        $this->assertEquals('', TranscriptionText::transcriptFromSegments(null));
        $this->assertEquals('', TranscriptionText::transcriptFromSegments([]));
    }
}
