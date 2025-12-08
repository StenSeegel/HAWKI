<?php

namespace App\Observers;

use App\Models\AiModelInfo;
use Illuminate\Support\Facades\Log;

class AiModelInfoObserver
{
    /**
     * Lockable fields that should be tracked for manual overwrites.
     */
    protected array $lockableFields = [
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
    ];

    /**
     * Handle the AiModelInfo "updating" event.
     * Auto-lock fields when they are manually changed (not via sync).
     */
    public function updating(AiModelInfo $aiModelInfo): void
    {
        // Check if this is a manual update (not from sync)
        // Sync operations should set a flag to bypass auto-lock
        if ($aiModelInfo->isDirty() && ! $aiModelInfo->getAttribute('_syncing')) {
            $dirtyFields = array_keys($aiModelInfo->getDirty());
            $overwrittenFields = $aiModelInfo->overwritten_fields ?? [];

            foreach ($dirtyFields as $field) {
                // Only lock if field is lockable and not already locked
                if (in_array($field, $this->lockableFields)) {
                    // Skip if field is being cleared (set to null)
                    if ($aiModelInfo->getAttribute($field) === null) {
                        continue;
                    }

                    // Lock the field
                    if (! isset($overwrittenFields[$field]) || $overwrittenFields[$field] === false) {
                        $overwrittenFields[$field] = true;

                        Log::info('AiModelInfo field auto-locked', [
                            'model_info_id' => $aiModelInfo->id,
                            'ai_model_id' => $aiModelInfo->ai_model_id,
                            'field' => $field,
                            'old_value' => $aiModelInfo->getOriginal($field),
                            'new_value' => $aiModelInfo->getAttribute($field),
                        ]);
                    }
                }
            }

            $aiModelInfo->overwritten_fields = $overwrittenFields;
        }
    }

    /**
     * Handle the AiModelInfo "created" event.
     */
    public function created(AiModelInfo $aiModelInfo): void
    {
        Log::info('AiModelInfo created', [
            'id' => $aiModelInfo->id,
            'ai_model_id' => $aiModelInfo->ai_model_id,
            'match_type' => $aiModelInfo->match_type,
            'model_info_id' => $aiModelInfo->model_info_id,
        ]);
    }

    /**
     * Handle the AiModelInfo "updated" event.
     */
    public function updated(AiModelInfo $aiModelInfo): void
    {
        // Only log if not syncing (to avoid excessive logs during bulk sync)
        if (! $aiModelInfo->getAttribute('_syncing')) {
            $changes = $aiModelInfo->getChanges();
            unset($changes['updated_at']); // Ignore timestamp

            if (! empty($changes)) {
                Log::info('AiModelInfo manually updated', [
                    'id' => $aiModelInfo->id,
                    'ai_model_id' => $aiModelInfo->ai_model_id,
                    'changed_fields' => array_keys($changes),
                    'locked_fields_count' => $aiModelInfo->getLockedFieldsCount(),
                ]);
            }
        }
    }

    /**
     * Handle the AiModelInfo "deleted" event.
     */
    public function deleted(AiModelInfo $aiModelInfo): void
    {
        Log::info('AiModelInfo deleted', [
            'id' => $aiModelInfo->id,
            'ai_model_id' => $aiModelInfo->ai_model_id,
            'model_info_id' => $aiModelInfo->model_info_id,
        ]);
    }

    /**
     * Handle the AiModelInfo "restored" event.
     */
    public function restored(AiModelInfo $aiModelInfo): void
    {
        //
    }

    /**
     * Handle the AiModelInfo "force deleted" event.
     */
    public function forceDeleted(AiModelInfo $aiModelInfo): void
    {
        //
    }
}
