<?php

declare(strict_types=1);

namespace App\Models\Transcription;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranscriptionJob extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'id',
        'user_id',
        'transcription_id',
        'status',
        'file_path',
        'manifest_data',
        'result_data',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'string',
            'manifest_data' => 'array',
            'result_data' => 'array',
        ];
    }

    /**
     * Get the user that owns the transcription job.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the transcription associated with the job.
     */
    public function transcription(): BelongsTo
    {
        return $this->belongsTo(Transcription::class);
    }
}
