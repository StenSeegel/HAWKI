<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ModelSettings;

use App\Orchid\Fields\BadgeField;
use Orchid\Screen\Fields\Group;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Rows;

class AiModelBasicInfoLayout extends Rows
{
    /**
     * The screen's layout elements.
     *
     * @return \Orchid\Screen\Field[]
     */
    public function fields(): array
    {
        $model = $this->query->get('model');
        $modelInfo = $model->modelInfo ?? null;

        $fields = [
            BadgeField::make('model.system_id')
                ->title('System ID')
                ->help('Internal unique identifier for this model instance')
                ->badgeClass('bg-secondary-subtle text-secondary-emphasis'),

            BadgeField::make('model.provider.provider_name')
                ->title('Provider')
                ->help('The API provider for this model')
                ->badgeClass('bg-primary-subtle text-primary-emphasis'),

            BadgeField::make('model.model_id')
                ->title('Model ID')
                ->help('The model name sent in API requests (e.g., "gpt-4", "llama2")')
                ->badgeClass('bg-info-subtle text-info-emphasis'),

            Input::make('model.label')
                ->title('Display Name')
                ->required()
                ->help('User-friendly display name shown in the interface'),
        ];

        // Add Model Info metadata if available
        if ($modelInfo) {
            // Capabilities
            $capabilities = $this->getCapabilities($modelInfo);
            if (!empty($capabilities)) {
                $fields[] = Input::make('capabilities_display')
                    ->title('Capabilities')
                    ->value(implode(', ', $capabilities))
                    ->readonly()
                    ->help('Model capabilities from models.hawki.info');
            }

            // Input/Output Types
            if ($modelInfo->input_types || $modelInfo->output_types) {
                $typeFields = [];
                
                if (!empty($modelInfo->input_types)) {
                    $typeFields[] = Input::make('input_types_display')
                        ->title('Input Types')
                        ->value($this->formatTypes($modelInfo->input_types))
                        ->readonly();
                }

                if (!empty($modelInfo->output_types)) {
                    $typeFields[] = Input::make('output_types_display')
                        ->title('Output Types')
                        ->value($this->formatTypes($modelInfo->output_types))
                        ->readonly();
                }

                if (!empty($typeFields)) {
                    $fields[] = Group::make($typeFields);
                }
            }

            // Knowledge Cutoff & Last Imported
            $metadataFields = [];
            
            if ($modelInfo->knowledge_cutoff) {
                $metadataFields[] = Input::make('knowledge_cutoff_display')
                    ->title('Knowledge Cutoff')
                    ->value($modelInfo->knowledge_cutoff->format('Y-m-d'))
                    ->readonly();
            }

            if ($modelInfo->last_imported_at) {
                $metadataFields[] = Input::make('last_imported_display')
                    ->title('Last Imported')
                    ->value($modelInfo->last_imported_at->format('Y-m-d H:i:s'))
                    ->readonly()
                    ->help('Last sync from models.hawki.info');
            }

            if (!empty($metadataFields)) {
                $fields[] = Group::make($metadataFields);
            }
        }

        return $fields;
    }

    /**
     * Get capabilities as array.
     */
    protected function getCapabilities($modelInfo): array
    {
        $capabilities = [];

        if ($modelInfo->reasoning) {
            $capabilities[] = 'Reasoning';
        }

        if ($modelInfo->tool_calling) {
            $capabilities[] = 'Tool Calling';
        }

        if ($modelInfo->open_weights) {
            $capabilities[] = 'Open Weights';
        }

        if ($modelInfo->deprecated) {
            $capabilities[] = '⚠️ DEPRECATED';
        }

        return $capabilities;
    }

    /**
     * Format types array to string.
     */
    protected function formatTypes(?array $types): string
    {
        if (empty($types)) {
            return 'N/A';
        }

        return implode(', ', array_map('ucfirst', $types));
    }
}
