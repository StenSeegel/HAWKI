<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ModelSettings;

use App\Models\AiModelInfo;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\Group;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Label;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

class AiModelCustomInfoLayout extends Rows
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

        if (!$modelInfo) {
            return [
                Input::make('no_model_info')
                    ->title('No Model Information')
                    ->value('Link a Model Info to customize these fields')
                    ->readonly()
                    ->canSee(true),
            ];
        }

        $fields = [];

        // Name Field
        $fields[] = Group::make([
            Input::make('custom_info.name')
                ->title('Model Name')
                ->value($modelInfo->name)
                ->help($this->getFieldHelp($modelInfo, 'name'))
                ->placeholder('Leave empty to use default from models.hawki.info'),
                
            Button::make('Reset')
                ->icon('bs.arrow-counterclockwise')
                ->method('resetCustomField', ['field' => 'name'])
                ->confirm('This will unlock the field and restore the original value on next sync.')
                ->canSee($modelInfo->isFieldLocked('name')),
        ]);

        // Description EN
        $fields[] = Group::make([
            TextArea::make('custom_info.description_en')
                ->title('Description (English)')
                ->value($modelInfo->description_en)
                ->rows(3)
                ->help($this->getFieldHelp($modelInfo, 'description_en'))
                ->placeholder('Leave empty to use default'),
                
            Button::make('Reset')
                ->icon('bs.arrow-counterclockwise')
                ->method('resetCustomField', ['field' => 'description_en'])
                ->confirm('This will unlock the field and restore the original value on next sync.')
                ->canSee($modelInfo->isFieldLocked('description_en')),
        ]);

        // Description DE
        $fields[] = Group::make([
            TextArea::make('custom_info.description_de')
                ->title('Description (German)')
                ->value($modelInfo->description_de)
                ->rows(3)
                ->help($this->getFieldHelp($modelInfo, 'description_de'))
                ->placeholder('Leave empty to use default'),
                
            Button::make('Reset')
                ->icon('bs.arrow-counterclockwise')
                ->method('resetCustomField', ['field' => 'description_de'])
                ->confirm('This will unlock the field and restore the original value on next sync.')
                ->canSee($modelInfo->isFieldLocked('description_de')),
        ]);

        // Pricing USD
        $fields[] = Group::make([
            Input::make('custom_info.price_input_usd')
                ->type('number')
                ->step('0.0000000001')
                ->title('Input Price USD (per 1M tokens)')
                ->value($modelInfo->price_input_usd)
                ->help($this->getFieldHelp($modelInfo, 'price_input_usd'))
                ->placeholder('0.00'),
                
            Input::make('custom_info.price_output_usd')
                ->type('number')
                ->step('0.0000000001')
                ->title('Output Price USD (per 1M tokens)')
                ->value($modelInfo->price_output_usd)
                ->help($this->getFieldHelp($modelInfo, 'price_output_usd'))
                ->placeholder('0.00'),
        ]);

        // Pricing EUR
        $fields[] = Group::make([
            Input::make('custom_info.price_input_eur')
                ->type('number')
                ->step('0.0000000001')
                ->title('Input Price EUR (per 1M tokens)')
                ->value($modelInfo->price_input_eur)
                ->help($this->getFieldHelp($modelInfo, 'price_input_eur'))
                ->placeholder('0.00'),
                
            Input::make('custom_info.price_output_eur')
                ->type('number')
                ->step('0.0000000001')
                ->title('Output Price EUR (per 1M tokens)')
                ->value($modelInfo->price_output_eur)
                ->help($this->getFieldHelp($modelInfo, 'price_output_eur'))
                ->placeholder('0.00'),
        ]);

        // Locked Fields Summary
        if ($modelInfo->getLockedFieldsCount() > 0) {
            $fields[] = Input::make('locked_summary')
                ->title('🔒 Locked Fields')
                ->value(implode(', ', $modelInfo->getLockedFields()))
                ->readonly()
                ->help('These fields have custom values and will not be overwritten during sync');
        }

        return $fields;
    }

    /**
     * Get help text for a field based on lock status.
     */
    protected function getFieldHelp(AiModelInfo $modelInfo, string $field): string
    {
        if ($modelInfo->isFieldLocked($field)) {
            return '🔒 Custom value (locked) - will not be updated by sync';
        }

        return '🔓 Synced from models.hawki.info - leave empty to use default';
    }
}
