<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ModelSettings;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Switcher;
use Orchid\Screen\Layouts\Rows;

class ProviderHawkiToolsLayout extends Rows
{
    /**
     * The screen's layout elements.
     *
     * @return Field[]
     */
    public function fields(): array
    {
        $fields = [];

        foreach (config('hawki_tools.tools', []) as $key => $tool) {
            $fields[] = Switcher::make('provider.hawki_tools.'.$key.'.override')
                ->title($tool['label'] ?? $key)
                ->sendTrueOrFalse()
                ->help($tool['help'] ?? 'Serve this tool through HAWKI instead of the provider.');
        }

        return $fields;
    }
}
