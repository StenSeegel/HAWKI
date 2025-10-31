<?php

namespace App\Models\Records;

use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Orchid\Filters\Filterable;
use Orchid\Screen\AsSource;

class UsageRecord extends Model
{
    use AsSource, Filterable;
    protected $fillable = [
        'user_id',
        'room_id',
        'prompt_tokens',
        'completion_tokens',
        'type',
        'api_provider',
        'model',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
