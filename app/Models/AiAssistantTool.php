<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAssistantTool extends Model
{
    protected $table = 'ai_assistant_tools';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'assistant_id',
        'tool_type',
        'is_enabled',
        'configuration',
    ];

    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'configuration' => 'array',
        ];
    }

    /**
     * Get the assistant that owns this tool.
     */
    public function assistant(): BelongsTo
    {
        return $this->belongsTo(AiAssistant::class, 'assistant_id');
    }
}
