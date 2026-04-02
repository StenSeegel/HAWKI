<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ModelSettings;

use App\Orchid\Fields\BadgeField;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Rows;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Fields\Select;

class AiModelBasicInfoLayout extends Rows
{
    /**
     * The screen's layout elements.
     *
     * @return \Orchid\Screen\Field[]
     */
    public function fields(): array
    {
        return [
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

            TextArea::make('model.settings.description')
                ->title('Beschreibung')
                ->help('Kurze Beschreibung der Stärken und Fähigkeiten des Modells (für das Informations-Popup)'),

            Input::make('model.settings.context_size')
                ->title('Kontext-Tokengrenze (z. B. 128000)')
                ->type('number')
                ->help('Geben Sie das Maximum an Tokens an'),

            Select::make('model.settings.cost_indicator')
                ->title('Kosten-Indikator')
                ->options([
                    '€' => '€ - Sehr günstig',
                    '€€' => '€€ - Günstig',
                    '€€€' => '€€€ - Mittel',
                    '€€€€' => '€€€€ - Teuer',
                ])
                ->help('Wie teuer ist das Modell?'),

            Input::make('model.settings.capabilities')
                ->title('Fähigkeiten (Kommagetrennt)')
                ->help('Beispiele: Reasoning, Coding, Multimodal'),

            Input::make('model.settings.documentation_url')
                ->title('Dokumentations-URL')
                ->help('Webadresse zur offiziellen Dokumentation des Modells'),
        ];
    }
}
