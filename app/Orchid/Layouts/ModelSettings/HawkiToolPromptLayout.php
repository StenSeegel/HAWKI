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
                ->help('Placement changes how reliably the prompt is followed. Measured on the ki@JLU models with questions that should trigger a search: gemma-4-26b-it got 4/5 with the prompt in the system message and 5/5 with it in front of the user message; both qwen models got 5/5 either way. Gemma has no native system role, so a system message carries less weight there.'),
        ];
    }
}
