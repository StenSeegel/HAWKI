<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Orchid\Filters\Filterable;
use Orchid\Screen\AsSource;

/**
 * AiModelInfo - Master catalog of all AI models from model_info.json
 * 
 * This table contains ALL models from the model_info.json, regardless of whether
 * they are linked to any ai_models entries. This allows for manual linking and
 * ensures we have a complete catalog of available models.
 */
class AiModelInfo extends Model
{
    use AsSource, Filterable, HasFactory;

    /**
     * Fields that can be locked/customized by admin.
     * These fields will not be overwritten during sync if locked.
     */
    public const LOCKABLE_FIELDS = [
        'name',
        'description_en',
        'description_de',
        'context_length',
        'output_limit',
        'price_input_usd',
        'price_output_usd',
        'price_input_eur',
        'price_output_eur',
    ];

    protected $table = 'ai_model_infos';

    protected $fillable = [
        'model_info_id',
        'base_model_id',
        'model_family',
        'provider_id',
        'name',
        'aliases',
        'description_en',
        'description_de',
        'knowledge_cutoff',
        'reasoning',
        'tool_calling',
        'open_weights',
        'input_types',
        'output_types',
        'parameters',
        'default_parameters',
        'context_length',
        'output_limit',
        'price_input_usd',
        'price_output_usd',
        'price_input_eur',
        'price_output_eur',
        'deprecated',
        'deprecation_date',
        'mode',
        'source_url',
        'additional_metadata',
        'locked_fields',
        'last_imported_at',
        'import_source',
    ];

    protected $casts = [
        'aliases' => 'array',
        'knowledge_cutoff' => 'date',
        'reasoning' => 'boolean',
        'tool_calling' => 'boolean',
        'open_weights' => 'boolean',
        'input_types' => 'array',
        'output_types' => 'array',
        'parameters' => 'array',
        'default_parameters' => 'array',
        'additional_metadata' => 'array',
        'locked_fields' => 'array',
        'context_length' => 'integer',
        'output_limit' => 'integer',
        'price_input_usd' => 'decimal:10',
        'price_output_usd' => 'decimal:10',
        'price_input_eur' => 'decimal:10',
        'price_output_eur' => 'decimal:10',
        'deprecated' => 'boolean',
        'deprecation_date' => 'date',
        'last_imported_at' => 'datetime',
    ];

    /**
     * Relationship to AI models that use this model info.
     * One model info can be linked to many AI models.
     */
    public function aiModels(): HasMany
    {
        return $this->hasMany(AiModel::class, 'ai_model_info_id');
    }

    /**
     * Relationship to the API Provider.
     * Belongs to one API Provider.
     */
    public function apiProvider(): BelongsTo
    {
        return $this->belongsTo(ApiProvider::class, 'provider_id');
    }

    /**
     * Ist das Modell deprecated?
     */
    public function isDeprecated(): bool
    {
        if ($this->deprecated) {
            return true;
        }

        if (!$this->deprecation_date) {
            return false;
        }

        return $this->deprecation_date->isPast();
    }

    /**
     * Berechne Kosten für eine bestimmte Token-Anzahl.
     * 
     * @param int $inputTokens Anzahl Input-Tokens
     * @param int $outputTokens Anzahl Output-Tokens
     * @param string $currency 'usd' oder 'eur'
     * @param array $options Zusätzliche Optionen (batch, priority, reasoning, etc.)
     * @return array ['input_cost', 'output_cost', 'total_cost', 'currency']
     */
    public function calculateCost(
        int $inputTokens,
        int $outputTokens,
        string $currency = 'usd',
        array $options = []
    ): array {
        $inputCostPerToken = $this->getInputCostPerToken($currency, $options);
        $outputCostPerToken = $this->getOutputCostPerToken($currency, $options);

        $inputCost = $inputTokens * $inputCostPerToken;
        $outputCost = $outputTokens * $outputCostPerToken;

        return [
            'input_cost' => $inputCost,
            'output_cost' => $outputCost,
            'total_cost' => $inputCost + $outputCost,
            'currency' => strtoupper($currency),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ];
    }

    /**
     * Hole Input-Kosten pro Token basierend auf Optionen.
     */
    protected function getInputCostPerToken(string $currency, array $options): float
    {
        // Priority Service Tier (OpenAI)
        if ($options['priority'] ?? false) {
            $cost = $this->input_cost_per_token_priority;
            if ($cost !== null) {
                return (float) $cost;
            }
        }

        // Batch Processing
        if ($options['batch'] ?? false) {
            $cost = $this->input_cost_per_token_batches;
            if ($cost !== null) {
                return (float) $cost;
            }
        }

        // Standard Kosten
        $field = $currency === 'eur' ? 'price_input_eur' : 'price_input_usd';
        return (float) ($this->$field ?? 0);
    }

    /**
     * Hole Output-Kosten pro Token basierend auf Optionen.
     */
    protected function getOutputCostPerToken(string $currency, array $options): float
    {
        // Reasoning Tokens (o1, DeepSeek-R1, etc.)
        if ($options['reasoning'] ?? false) {
            $cost = $this->output_cost_per_reasoning_token;
            if ($cost !== null) {
                return (float) $cost;
            }
        }

        // Priority Service Tier (OpenAI)
        if ($options['priority'] ?? false) {
            $cost = $this->output_cost_per_token_priority;
            if ($cost !== null) {
                return (float) $cost;
            }
        }

        // Batch Processing
        if ($options['batch'] ?? false) {
            $cost = $this->output_cost_per_token_batches;
            if ($cost !== null) {
                return (float) $cost;
            }
        }

        // Standard Kosten
        $field = $currency === 'eur' ? 'price_output_eur' : 'price_output_usd';
        return (float) ($this->$field ?? 0);
    }

    /**
     * Get provider data for a specific provider ID.
     */
    public function getProviderData(string $providerId): ?array
    {
        if (!$this->providers || !is_array($this->providers)) {
            return null;
        }

        foreach ($this->providers as $provider) {
            if (isset($provider['providerId']) && $provider['providerId'] === $providerId) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Get all provider IDs available for this model.
     */
    public function getProviderIds(): array
    {
        if (!$this->providers || !is_array($this->providers)) {
            return [];
        }

        return array_map(fn($p) => $p['providerId'] ?? null, $this->providers);
    }

    /**
     * Check if this model has a specific provider.
     */
    public function hasProvider(string $providerId): bool
    {
        return in_array($providerId, $this->getProviderIds());
    }

    /**
     * Get the best/default provider data (usually the first one).
     */
    public function getDefaultProvider(): ?array
    {
        if (!$this->providers || !is_array($this->providers) || empty($this->providers)) {
            return null;
        }

        return $this->providers[0];
    }

    /**
     * Scope: Only non-deprecated models.
     */
    public function scopeNotDeprecated($query)
    {
        return $query->where('deprecated', false)
            ->where(function ($q) {
                $q->whereNull('deprecation_date')
                    ->orWhere('deprecation_date', '>', now());
            });
    }

    /**
     * Scope: Only active (nicht deprecated) Modelle.
     */
    public function scopeActive($query)
    {
        return $this->scopeNotDeprecated($query);
    }

    /**
     * Scope: Nur für bestimmten Provider.
     */
    public function scopeForProvider($query, string $providerId)
    {
        return $query->where('provider_id', $providerId);
    }

    /**
     * Scope: Nur für bestimmten Mode.
     */
    public function scopeForMode($query, string $mode)
    {
        return $query->where('mode', $mode);
    }

    /**
     * Scope: Models with reasoning capability.
     */
    public function scopeWithReasoning($query)
    {
        return $query->where('reasoning', true);
    }

    /**
     * Scope: Models with tool calling capability.
     */
    public function scopeWithToolCalling($query)
    {
        return $query->where('tool_calling', true);
    }

    /**
     * Scope: Open weights models.
     */
    public function scopeOpenWeights($query)
    {
        return $query->where('open_weights', true);
    }

    /**
     * Scope: Search by model_info_id or name.
     */
    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('model_info_id', 'like', "%{$term}%")
              ->orWhere('name', 'like', "%{$term}%");
        });
    }

    /**
     * Get a human-readable display name.
     */
    public function getDisplayName(): string
    {
        return $this->name ?? $this->model_info_id;
    }

    /**
     * Get linked AI models count.
     */
    public function getLinkedModelsCount(): int
    {
        return $this->aiModels()->count();
    }

    // ========================================
    // Field Locking Methods
    // ========================================

    /**
     * Lock a field to prevent it from being overwritten during sync.
     */
    public function lockField(string $field): void
    {
        if (!in_array($field, self::LOCKABLE_FIELDS)) {
            throw new \InvalidArgumentException("Field '{$field}' is not lockable");
        }

        $locked = $this->locked_fields ?? [];
        if (!in_array($field, $locked)) {
            $locked[] = $field;
            $this->locked_fields = $locked;
        }
    }

    /**
     * Unlock a field to allow it to be updated during sync.
     */
    public function unlockField(string $field): void
    {
        $locked = $this->locked_fields ?? [];
        $this->locked_fields = array_values(array_diff($locked, [$field]));
    }

    /**
     * Check if a field is locked.
     */
    public function isFieldLocked(string $field): bool
    {
        return in_array($field, $this->locked_fields ?? []);
    }

    /**
     * Unlock all fields.
     */
    public function unlockAllFields(): void
    {
        $this->locked_fields = [];
    }

    /**
     * Get list of locked fields.
     */
    public function getLockedFields(): array
    {
        return $this->locked_fields ?? [];
    }

    /**
     * Get count of locked fields.
     */
    public function getLockedFieldsCount(): int
    {
        return count($this->locked_fields ?? []);
    }
}
