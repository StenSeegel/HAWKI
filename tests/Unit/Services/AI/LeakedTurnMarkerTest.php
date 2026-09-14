<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\Providers\OpenAiHawkiTools\Request\OpenAiHawkiToolsStreamingRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LeakedTurnMarkerTest extends TestCase
{
    /**
     * @return array<string,array{string,string}>
     */
    public static function deltas(): array
    {
        return [
            'gemma end of turn as the last delta' => ['<turn|>', ''],
            'marker glued to the text' => ["ohne die JLU-Elemente.<turn|>", 'ohne die JLU-Elemente.'],
            'pipe on both sides' => ['<|turn|>', ''],
            'gemma 3 style' => ['<end_of_turn>', ''],
            'chatml' => ['done<|im_end|>', 'done'],
            'llama' => ['done<|eot_id|>', 'done'],
            'plain text with angle brackets stays' => ['a < b und <b>fett</b>', 'a < b und <b>fett</b>'],
            'no brackets: untouched fast path' => ['turn|>', 'turn|>'],
        ];
    }

    #[DataProvider('deltas')]
    public function test_a_leaked_chat_template_marker_is_dropped(string $delta, string $expected): void
    {
        $this->assertSame($expected, OpenAiHawkiToolsStreamingRequest::stripLeakedTurnMarkers($delta));
    }
}
