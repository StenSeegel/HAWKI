<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Orchid\Filters\Filterable;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\WhereDateStartEnd;
use Orchid\Screen\AsSource;

class AiModel extends Model
{
    use AsSource, Filterable, HasFactory;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        // Clear AI config cache when model is saved or deleted
        static::saved(function ($model) {
            static::clearAiConfigCache();
        });

        static::deleted(function ($model) {
            static::clearAiConfigCache();
        });
    }

    /**
     * Clear all AI configuration caches
     */
    protected static function clearAiConfigCache(): void
    {
        Cache::forget('ai_config_default_models');
        Cache::forget('ai_config_system_models');
        Cache::forget('ai_config_providers');
        
        // Clear tagged cache if driver supports it
        try {
            Cache::tags(['ai_config'])->flush();
        } catch (\BadMethodCallException $e) {
            // Cache driver doesn't support tags, skip
        }
    }

    protected $table = 'ai_models';

    protected $fillable = [
        'system_id',
        'model_id',
        'label',
        'provider_id',
        'ai_model_info_id',
        'matching_candidates',
        'match_type',
        'is_active',
        'streamable',
        'is_visible',
        'display_order',
        'information',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'streamable' => 'boolean',
        'is_visible' => 'boolean',
        'display_order' => 'integer',
        'information' => 'array',
        'settings' => 'array',
        'matching_candidates' => 'array',
        'last_matched_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->system_id)) {
                $model->system_id = Str::uuid();
            }
        });
    }

    /**
     * The attributes for which you can use filters in url.
     *
     * @var array
     */
    protected $allowedFilters = [
        'id' => Where::class,
        'system_id' => Like::class,
        'model_id' => Like::class,
        'label' => Like::class,
        'provider_id' => Where::class,
        'is_active' => Where::class,
        'is_visible' => Where::class,
        'streamable' => Where::class,
        'created_at' => WhereDateStartEnd::class,
        'updated_at' => WhereDateStartEnd::class,
    ];

    /**
     * The attributes for which can use sort in url.
     *
     * @var array
     */
    protected $allowedSorts = [
        'id',
        'system_id',
        'model_id',
        'label',
        'provider_id',
        'provider_name',
        'is_active',
        'is_visible',
        'streamable',
        'display_order',
        'created_at',
        'updated_at',
    ];

    /**
     * Get the provider that owns the model.
     */
    public function provider()
    {
        return $this->belongsTo(ApiProvider::class, 'provider_id');
    }

    /**
     * Get the linked model information from the master catalog.
     * An AI model can optionally be linked to one entry in the model info catalog.
     */
    public function modelInfo()
    {
        return $this->belongsTo(AiModelInfo::class, 'ai_model_info_id');
    }

    /**
     * Check if this model is linked to a model info entry.
     */
    public function hasModelInfo(): bool
    {
        return $this->ai_model_info_id !== null;
    }

    /**
     * Get matching candidates for this model.
     */
    public function getMatchingCandidates(): array
    {
        return $this->matching_candidates ?? [];
    }

    /**
     * Set matching candidates for this model.
     */
    public function setMatchingCandidates(array $candidates): void
    {
        $this->matching_candidates = $candidates;
    }
}
