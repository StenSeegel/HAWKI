<?php

declare(strict_types=1);

namespace App\Orchid\Screens\ModelSettings;

use App\Models\AiModel;
use App\Orchid\Layouts\ModelSettings\AiModelBasicInfoLayout;
use App\Orchid\Layouts\ModelSettings\AiModelCustomInfoLayout;
use App\Orchid\Layouts\ModelSettings\AiModelInformationLayout;
use App\Orchid\Layouts\ModelSettings\AiModelStatusLayout;
use App\Orchid\Layouts\ModelSettings\AiModelToolsLayout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class AiModelEditScreen extends Screen
{
    /**
     * @var AiModel
     */
    public $model;

    /**
     * Query data.
     *
     *
     * @return array
     */
    public function query(AiModel $model): iterable
    {
        $this->model = $model;
        
        // Load modelInfo relation if not already loaded
        $model->loadMissing('modelInfo');

        return [
            'model' => $model,
        ];
    }

    /**
     * Display header name.
     */
    public function name(): ?string
    {
        return 'Edit Model: '.$this->model->label;
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        return 'Configure model settings and view information';
    }

    /**
     * The screen's action buttons.
     *
     * @return \Orchid\Screen\Action[]
     */
    public function commandBar(): iterable
    {
        $queryParams = request()->only(['provider_filter', 'active_status', 'visible_status', 'search', 'date_range']);
        $backUrl = route('platform.models.language');
        
        if (!empty($queryParams)) {
            $backUrl .= '?' . http_build_query($queryParams);
        }
        
        return [
            Link::make('Back')
                ->href($backUrl)
                ->icon('bs.arrow-left-circle'),

            Button::make('Save')
                ->icon('bs.check-circle')
                ->method('save'),
        ];
    }

    /**
     * Views.
     *
     * @return \Orchid\Screen\Layout[]|string[]
     */
    public function layout(): iterable
    {
        return [
            Layout::block(AiModelBasicInfoLayout::class)
                ->title('Model Information')
                ->description('System ID, provider details, model identification, and capabilities from models.hawki.info.'),

            Layout::block(AiModelStatusLayout::class)
                ->title('Model Status')
                ->description('Control model availability and visibility for users.'),

            Layout::block(AiModelToolsLayout::class)
                ->title('Model Capabilities (Tools)')
                ->description('Configure which features and tools this model supports.'),

            Layout::split([
                AiModelInformationLayout::class,
                AiModelCustomInfoLayout::class,
            ])->canSee($this->model->modelInfo !== null),

            // Fallback wenn kein Model Info verknüpft
            Layout::block(AiModelInformationLayout::class)
                ->title('Technical Specs & Pricing')
                ->description('Link a model info to enable custom overrides.')
                ->canSee($this->model->modelInfo === null),
        ];
    }

    /**
     * Save model changes.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function save(Request $request)
    {
        try {
            // Validate basic model data
            $request->validate([
                'model.label' => 'required|string|max:255',
                'model.is_active' => 'boolean',
                'model.is_visible' => 'boolean',
                'model.settings.tools.file_upload' => 'nullable|boolean',
                'model.settings.tools.vision' => 'nullable|boolean',
                'model.settings.tools.web_search' => 'nullable|boolean',
                'model_info.ai_model_info_id' => 'nullable|exists:ai_model_infos,id',
            ]);

            // Get current settings and merge with tools from UI
            $settings = $this->model->settings ?? [];
            
            // Merge tools from UI checkboxes into settings
            $modelData = $request->get('model', []);
            if (isset($modelData['settings']['tools'])) {
                $settings['tools'] = $modelData['settings']['tools'];
            }

            // Store original values for change tracking
            $originalLabel = $this->model->label;
            $originalActive = $this->model->is_active;
            $originalVisible = $this->model->is_visible;
            $originalSettings = $this->model->settings;

            // Update model fields (exclude nested settings, we handle it separately)
            unset($modelData['settings']);
            $this->model->fill($modelData);
            $this->model->settings = $settings;
            $this->model->save();

            // Handle Model Info ID change
            $modelInfoData = $request->get('model_info', []);
            if (isset($modelInfoData['ai_model_info_id'])) {
                $newModelInfoId = $modelInfoData['ai_model_info_id'] !== '' 
                    ? (int) $modelInfoData['ai_model_info_id'] 
                    : null;
                $this->updateModelInfoLink($newModelInfoId);
            }

            // Handle Custom Model Info (field locking)
            $customInfoData = $request->get('custom_info', []);
            if (!empty($customInfoData) && $this->model->modelInfo) {
                $this->handleCustomModelInfo($customInfoData);
            }

            // Log successful update with change details
            $changes = [];
            if ($originalLabel !== $this->model->label) {
                $changes['label'] = ['from' => $originalLabel, 'to' => $this->model->label];
            }
            if ($originalActive !== $this->model->is_active) {
                $changes['is_active'] = ['from' => $originalActive, 'to' => $this->model->is_active];
            }
            if ($originalVisible !== $this->model->is_visible) {
                $changes['is_visible'] = ['from' => $originalVisible, 'to' => $this->model->is_visible];
            }
            if ($originalSettings !== $this->model->settings) {
                $changes['settings'] = 'updated';
            }

            Log::info('Language model updated successfully', [
                'model_id' => $this->model->id,
                'model_label' => $this->model->label,
                'provider_id' => $this->model->provider_id,
                'changes' => $changes,
                'updated_by' => auth()->id(),
            ]);

            Toast::success("Model '{$this->model->label}' has been updated successfully.");

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validation failed for language model update', [
                'model_id' => $this->model->id,
                'errors' => $e->errors(),
                'updated_by' => auth()->id(),
            ]);

            throw $e; // Re-throw validation exceptions to show form errors
        } catch (\Exception $e) {
            Log::error('Error updating language model', [
                'model_id' => $this->model->id,
                'model_label' => $this->model->label,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'updated_by' => auth()->id(),
            ]);

            Toast::error('An error occurred while saving: '.$e->getMessage());

            return back()->withInput();
        }

        return redirect()->route('platform.models.language.edit', $this->model);
    }

    /**
     * Update the model info link for this AI model.
     */
    protected function updateModelInfoLink(?int $newModelInfoId): void
    {
        try {
            $oldModelInfoId = $this->model->ai_model_info_id;

            // If empty/null, remove the link
            if (empty($newModelInfoId)) {
                $this->model->ai_model_info_id = null;
                $this->model->match_type = 'none';
                $this->model->last_matched_at = null;
                $this->model->save();
                
                Log::info('Model info link removed', [
                    'ai_model_id' => $this->model->id,
                    'old_model_info_id' => $oldModelInfoId,
                    'updated_by' => auth()->id(),
                ]);
                
                Toast::info('Model info link has been removed.');
                return;
            }

            // Check if the model info exists
            $modelInfo = \App\Models\AiModelInfo::find($newModelInfoId);

            if (!$modelInfo) {
                Toast::warning("Model info entry not found.");
                return;
            }

            // Update the link
            $this->model->ai_model_info_id = $newModelInfoId;
            $this->model->match_type = 'manual'; // Mark as manually linked
            $this->model->last_matched_at = now();
            $this->model->save();

            Log::info('Model info link updated', [
                'ai_model_id' => $this->model->id,
                'old_model_info_id' => $oldModelInfoId,
                'new_model_info_id' => $newModelInfoId,
                'model_info_id' => $modelInfo->model_info_id,
                'match_type' => 'manual',
                'updated_by' => auth()->id(),
            ]);

            Toast::success("Model info linked to '{$modelInfo->model_info_id}' successfully.");

        } catch (\Exception $e) {
            Log::error('Failed to update model info link', [
                'ai_model_id' => $this->model->id,
                'new_model_info_id' => $newModelInfoId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            Toast::error('Failed to update model info link: ' . $e->getMessage());
        }
    }

    /**
     * Handle custom model info changes and field locking.
     */
    protected function handleCustomModelInfo(array $customData): void
    {
        $modelInfo = $this->model->modelInfo;
        if (!$modelInfo) {
            return;
        }

        $lockedCount = 0;
        $unlockedCount = 0;

        foreach (\App\Models\AiModelInfo::LOCKABLE_FIELDS as $field) {
            if (!array_key_exists($field, $customData)) {
                continue;
            }

            $newValue = $customData[$field];
            $currentValue = $modelInfo->$field;

            // Handle empty values (unlock and clear)
            if (empty($newValue) && $newValue !== '0' && $newValue !== 0) {
                if ($modelInfo->isFieldLocked($field)) {
                    $modelInfo->unlockField($field);
                    $modelInfo->$field = null;
                    $unlockedCount++;
                    
                    Log::info('Field unlocked and cleared', [
                        'model_info_id' => $modelInfo->id,
                        'field' => $field,
                        'user_id' => auth()->id(),
                    ]);
                }
                continue;
            }

            // Handle numeric fields (normalize types)
            if (in_array($field, ['context_length', 'output_limit'])) {
                $newValue = (int) $newValue;
                $currentValue = (int) $currentValue;
            } elseif (str_contains($field, 'price_')) {
                $newValue = (float) $newValue;
                $currentValue = (float) $currentValue;
            }

            // Check if value has ACTUALLY changed (strict comparison after type normalization)
            if ($newValue !== $currentValue) {
                $modelInfo->$field = $newValue;
                
                // Lock field if not already locked
                if (!$modelInfo->isFieldLocked($field)) {
                    $modelInfo->lockField($field);
                    $lockedCount++;
                    
                    Log::info('Field locked with custom value', [
                        'model_info_id' => $modelInfo->id,
                        'field' => $field,
                        'old_value' => $currentValue,
                        'new_value' => $newValue,
                        'user_id' => auth()->id(),
                    ]);
                }
            }
        }

        $modelInfo->save();

        // Show feedback
        if ($lockedCount > 0 || $unlockedCount > 0) {
            $messages = [];
            if ($lockedCount > 0) {
                $messages[] = "{$lockedCount} field(s) locked with custom values";
            }
            if ($unlockedCount > 0) {
                $messages[] = "{$unlockedCount} field(s) unlocked";
            }
            
            Toast::info(implode(', ', $messages) . '. Run "Import & Match Models" to sync unlocked fields.');
        }
    }

    /**
     * Reset a custom field to its original value.
     */
    public function resetCustomField(AiModel $model, string $field)
    {
        $modelInfo = $model->modelInfo;
        if (!$modelInfo) {
            Toast::error('No model information linked');
            return redirect()->back();
        }

        if (!in_array($field, \App\Models\AiModelInfo::LOCKABLE_FIELDS)) {
            Toast::error("Field '{$field}' is not customizable");
            return redirect()->back();
        }

        // Unlock the field
        $modelInfo->unlockField($field);
        $modelInfo->save();

        Log::info('Field reset (unlocked)', [
            'model_info_id' => $modelInfo->id,
            'field' => $field,
            'user_id' => auth()->id(),
        ]);

        Toast::info("Field '{$field}' unlocked. Run 'Import & Match Models' to restore original value.");

        return redirect()->back();
    }

    /**
     * The permissions required to access this screen.
     */
    public function permission(): ?iterable
    {
        return [
            'platform.modelsettings.models',
        ];
    }
}
