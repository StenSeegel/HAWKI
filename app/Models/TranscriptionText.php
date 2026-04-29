<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TranscriptionText extends Model
{
    use HasFactory;

    protected $fillable = [
        'transcription_id',
        'transcript_text',
        'segments',
        'words',
    ];

    protected $casts = [
        'segments' => 'array',
        'words' => 'array',
    ];

    public function transcription()
    {
        return $this->belongsTo(Transcription::class);
    }
}
