<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAssistantFile extends Model
{
    protected $table = 'ai_assistant_files';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'assistant_id',
        'attachment_id',
        'purpose',
        'description',
        'display_order',
    ];

    /**
     * Get the assistant that owns this file.
     */
    public function assistant(): BelongsTo
    {
        return $this->belongsTo(AiAssistant::class, 'assistant_id');
    }

    /**
     * Get the attachment for this file.
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }
}
