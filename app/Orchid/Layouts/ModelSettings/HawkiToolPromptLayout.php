<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ModelSettings;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

/**
 * Settings that apply to every HAWKI tool.
 *
 * The per tool prompts live in the expandable sections built by
 * {@see \App\Orchid\Screens\ModelSettings\ToolsScreen::layout()}.
 */
class HawkiToolPromptLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Select::make('awareness_placement')
                ->title('Where the tool prompt is placed')
                ->options([
                    'user' => 'In front of the newest user message (recommended)',
                    'system' => 'In the system prompt',
                ])
                ->help('Placement changes how reliably the prompt is followed. Gemma used to need the prompt in front of the user message (4/5 in the system message against 5/5 in the user message), because its old chat template folded a system message into the conversation. Since the gateway deployed gemma\'s new chat template both placements measure 15/15, so the system message is the default again and the user\'s own text stays untouched. Keep the user placement for models that fold or ignore the system role.'),
        ];
    }
}
