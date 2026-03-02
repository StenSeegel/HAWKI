<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Transcription extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'user_id',
        'transcript_text',
        'segments',
        'words',
        'language',
        'user_locale',
        'duration',
        'model_used',
        'provider',
        'original_filename',
        'file_size',
        'metadata',
    ];

    protected $casts = [
        'segments' => 'array',
        'words' => 'array',
        'metadata' => 'array',
        'duration' => 'integer',
        'file_size' => 'integer',
    ];

    /**
     * Boot the model and auto-generate slug
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transcription) {
            if (empty($transcription->slug)) {
                $transcription->slug = Str::uuid()->toString();
            }
        });
    }

    /**
     * Relationship with User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: Get transcriptions for a specific user
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Recent transcriptions
     */
    public function scopeRecent($query, $limit = 20)
    {
        return $query->orderBy('updated_at', 'desc')->limit($limit);
    }
}
