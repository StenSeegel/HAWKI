<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ModelSettings;

use App\Orchid\Fields\BadgeField;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Fields\TextArea;
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
        // The English fields stay disabled while empty: saving the model machine-
        // translates the German text into them (see AiModelEditScreen::save), and
        // only once they hold text does hand-editing them make sense.
        $settings = $this->query->get('model.settings') ?? [];
        $settings = is_array($settings) ? $settings : (array) $settings;
        $hasText = static fn (string $key): bool => is_string($settings[$key] ?? null)
            && trim($settings[$key]) !== '';

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
                ->title('Beschreibung (Deutsch)')
                ->help('Kurze Beschreibung der Stärken und Fähigkeiten des Modells (für die Modellkarte)'),

            TextArea::make('model.settings.description_en')
                ->title('Description (English)')
                ->disabled(! $hasText('description_en'))
                ->help($hasText('description_en')
                    ? 'Shown on the model card when the interface language is English.'
                    : 'Filled automatically by translating the German description when you save. Edit it here afterwards.'),

            Input::make('model.settings.context_size')
                ->title('Kontext-Tokengrenze (z. B. 128000)')
                ->type('number')
                ->help('Geben Sie das Maximum an Tokens an'),

            // A month picker: the cutoff is only ever a month and year, and storing
            // the ISO value (2023-10) keeps it language-neutral so the model card
            // can render "Oktober 2023" or "October 2023" from the same data.
            Input::make('model.settings.knowledge_cutoff')
                ->type('month')
                ->title('Wissensgrenze')
                ->help('Monat und Jahr des Knowledge-Cutoff. Wird auf der Modellkarte in der jeweiligen Sprache angezeigt (z. B. Oktober 2023 / October 2023).'),

            Select::make('model.settings.cost_indicator')
                ->title('Kosten-Indikator')
                ->options([
                    '0' => 'Kostenlos',
                    '€' => '€ - Sehr günstig',
                    '€€' => '€€ - Günstig',
                    '€€€' => '€€€ - Mittel',
                    '€€€€' => '€€€€ - Teuer',
                ])
                ->help('Wie teuer ist das Modell?'),

            Input::make('model.settings.documentation_url')
                ->title('Dokumentations-URL')
                ->help('Webadresse zur offiziellen Dokumentation des Modells'),
        ];
    }
}
