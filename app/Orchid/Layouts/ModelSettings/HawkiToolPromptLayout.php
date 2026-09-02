<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ModelSettings;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

/**
 * The model facing texts of every HAWKI tool.
 *
 * The awareness prompt is the only place where the decision to call a tool is
 * steered - there is no code side heuristic looking at the user's message - so
 * this is where that behaviour is tuned.
 */
class HawkiToolPromptLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        $fields = [
            Select::make('awareness_placement')
                ->title('Where the tool prompt is placed')
                ->options([
                    'user' => 'In front of the newest user message (recommended)',
                    'system' => 'In the system prompt',
                ])
                ->help('Placement changes how reliably the prompt is followed. Measured on the ki@JLU models with questions that should trigger a search: the system prompt got 4/5 on gemma-4-26b-it and gpt-oss-20b, in front of the user message all three models got 5/5. Gemma has no native system role, so a system message carries less weight there.'),
        ];

        foreach (config('hawki_tools.tools', []) as $key => $tool) {
            $label = $tool['label'] ?? $key;

            $fields[] = Input::make('tools.'.$key.'.description')
                ->title($label.': function description')
                ->help('Sent to the model as the description of the '.$key.' function. Keep it short; it is what the model reads when deciding whether the tool fits.')
                ->maxlength(1000);

            $fields[] = TextArea::make('tools.'.$key.'.awareness')
                ->title($label.': tool prompt')
                ->rows(16)
                ->help('Added to the system prompt whenever this tool is attached to a request. Without it the models answer that they have no internet access instead of calling the tool. This text alone decides when a search happens - name the wordings and the kinds of question that must trigger it, and the ones that must not.');
        }

        return $fields;
    }
}
