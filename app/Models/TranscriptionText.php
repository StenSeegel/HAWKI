<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TranscriptionText extends Model
{
    use HasFactory;

    protected $fillable = [
        'transcription_id',
        'segments',
        'words',
    ];

    protected $casts = [
        'segments' => 'array',
        'words' => 'array',
    ];

    public function transcription(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Transcription::class);
    }

    /**
     * Build a plain-text transcript from segment payload.
     */
    public static function transcriptFromSegments(?array $segments): string
    {
        if (empty($segments)) {
            return '';
        }

        $lines = [];
        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $text = trim((string) ($segment['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $speaker = trim((string) ($segment['speaker'] ?? ''));
            $lines[] = $speaker !== '' ? $speaker.': '.$text : $text;
        }

        return trim(implode("\n\n", $lines));
    }

    /**
     * Derive the full transcript text from segments.
     * Segments are the single source of truth after the transcript_text column was dropped.
     */
    public function resolvedTranscriptText(): string
    {
        return self::transcriptFromSegments($this->segments);
    }
}
