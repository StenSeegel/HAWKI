<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ModelSettings;

use App\Orchid\Fields\BadgeField;
use App\Orchid\Traits\ApiFormatColorTrait;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Rows;

class LanguageModelBasicInfoLayout extends Rows
{
    use ApiFormatColorTrait;
    /**
     * The screen's layout elements.
     *
     * @return \Orchid\Screen\Field[]
     */
    public function fields(): array
    {
        $model = $this->query->get('model');
        
        return [
            BadgeField::make('model.system_id')
                ->title('System ID')
                ->help('Internal unique identifier for this model instance')
                ->badgeClass('bg-secondary-subtle text-secondary-emphasis rounded-pill fs-6'),

            BadgeField::make('model.provider.provider_name')
                ->title('Provider')
                ->help('The API provider for this model')
                ->badgeClass($this->getModelProviderBadgeClass($model)),

            BadgeField::make('model.model_id')
                ->title('Model ID')
                ->help('The model name sent in API requests (e.g., "gpt-4", "llama2")')
                ->badgeClass('bg-info-subtle text-info-emphasis rounded-pill fs-6'),

            Input::make('model.label')
                ->title('Display Name')
                ->required()
                ->help('User-friendly display name shown in the interface'),
        ];
    }
}
