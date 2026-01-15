<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Orchid\Filters\Filterable;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\WhereDateStartEnd;
use Orchid\Screen\AsSource;

class AiAssistant extends Model
{
    use AsSource, Filterable, HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'key',
        'name',
        'description',
        'status',
        'visibility',
        'required_role',
        'owner_id',
        'ai_model',
        'prompt',
        'tools',
        'avatar_path',
        'conversation_starters',
        'usage_count',
        'category',
        'full_system_prompt',
        'published_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'tools' => 'array',
            'conversation_starters' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Name of columns to which http filtering can be applied
     */
    protected $allowedFilters = [
        'name' => Like::class,
        'key' => Like::class,
        'description' => Like::class,
        'status' => Where::class,
        'visibility' => Where::class,
        'required_role' => Where::class,
        'owner_id' => Where::class,
        'prompt' => Like::class,
        'created_at' => WhereDateStartEnd::class,
        'updated_at' => WhereDateStartEnd::class,
    ];

    /**
     * Name of columns to which http sorting can be applied
     */
    protected $allowedSorts = [
        'name',
        'key',
        'status',
        'visibility',
        'required_role',
        'owner_id',
        'ai_model',
        'prompt',
        'created_at',
        'updated_at',
        // Relationship sorting
        'owner.name',
    ];

    /**
     * Get the owner of the AI assistant.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Relationship: AI Model assigned to this assistant
     */
    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model', 'system_id');
    }

    /**
     * Relationship: Required role for group visibility
     */
    public function requiredRole(): BelongsTo
    {
        return $this->belongsTo(\Orchid\Platform\Models\Role::class, 'required_role', 'slug');
    }

    /**
     * Scope query to active assistants.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope query to visible assistants based on visibility level.
     */
    public function scopeVisible($query, $level = 'public')
    {
        return $query->where('visibility', $level);
    }

    /**
     * Scope query to assistants owned by specific user.
     */
    public function scopeOwnedBy($query, $userId)
    {
        return $query->where('owner_id', $userId);
    }

    /**
     * Get files attached to this assistant.
     */
    public function files(): HasMany
    {
        return $this->hasMany(AiAssistantFile::class, 'assistant_id');
    }

    /**
     * Get tool configurations for this assistant.
     */
    public function toolConfigs(): HasMany
    {
        return $this->hasMany(AiAssistantTool::class, 'assistant_id');
    }

    /**
     * Users who favorited this assistant.
     */
    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_favorite_assistants')
            ->withTimestamps();
    }

    /**
     * Scope query to assistants accessible by a user.
     */
    public function scopeAccessibleBy($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('visibility', 'public')
                ->orWhere('owner_id', $user->id)
                ->orWhere(function ($q2) use ($user) {
                    $q2->where('visibility', 'group')
                        ->whereIn('required_role', $user->roles->pluck('slug'));
                });
        })->where('status', 'active');
    }

    /**
     * Increment usage counter.
     */
    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }
}
