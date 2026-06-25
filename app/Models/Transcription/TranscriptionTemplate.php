<?php

declare(strict_types=1);

namespace App\Models\Transcription;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranscriptionTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'structure',
    ];

    /**
     * Get the casts for the model.
     */
    protected function casts(): array
    {
        return [
            'structure' => 'array',
        ];
    }

    /**
     * Relationship with User.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
