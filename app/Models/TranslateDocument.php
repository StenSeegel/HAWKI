<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TranslateDocument extends Model
{
    use HasFactory;

    protected $table = 'translate_documents';

    protected $fillable = [
        'job_id',
        'user_id',
        'original_name',
        'output_extension',
        'target_lang',
        'source_lang',
        'download_id',
        'file_path',
        'file_size',
        'status',
        'error_message',
        'translated_at',
        'downloaded_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'file_size' => 'integer',
            'translated_at' => 'datetime',
            'downloaded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: documents ready for download.
     */
    public function scopeDone($query)
    {
        return $query->where('status', 'done');
    }

    /**
     * Scope: expired documents whose files should be cleaned up.
     */
    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereNotIn('status', ['deleted']);
    }

    /**
     * Check if the file still physically exists on disk.
     * file_path is stored as a relative path within the local storage disk.
     */
    public function fileExists(): bool
    {
        return ! empty($this->file_path) && Storage::disk('local')->exists($this->file_path);
    }
}
