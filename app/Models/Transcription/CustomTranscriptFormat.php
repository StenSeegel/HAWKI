<?php

declare(strict_types=1);

namespace App\Models\Transcription;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomTranscriptFormat extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'speakers',
        'timestamps',
        'avatars',
        'bubbles',
        'anonymize',
        'order',
    ];

    /**
     * Get the casts for the model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'speakers' => 'boolean',
            'timestamps' => 'boolean',
            'avatars' => 'boolean',
            'bubbles' => 'boolean',
            'anonymize' => 'boolean',
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
