<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ModelSettings;

use Orchid\Screen\Fields\CheckBox;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

class AiModelToolsLayout extends Rows
{
    /**
     * The screen's layout elements.
     *
     * @return \Orchid\Screen\Field[]
     */
    public function fields(): array
    {
        return [
            CheckBox::make('model.settings.tools.file_upload')
                ->title('File Upload')
                ->help('Enable file upload and processing capabilities')
                ->sendTrueOrFalse()
                ->placeholder('Support file uploads'),

            CheckBox::make('model.settings.tools.vision')
                ->title('Vision')
                ->help('Enable image/vision processing capabilities')
                ->sendTrueOrFalse()
                ->placeholder('Support image analysis'),

            CheckBox::make('model.settings.tools.web_search')
                ->title('Web Search')
                ->help('Enable web search integration (requires provider support)')
                ->sendTrueOrFalse()
                ->placeholder('Allow web searches'),

            CheckBox::make('model.settings.tools.reasoning')
                ->title('Reasoning')
                ->help('Enable advanced reasoning capabilities (OpenAI Responses API with reasoning.effort parameter)')
                ->sendTrueOrFalse()
                ->placeholder('Support reasoning mode (Experimental)'),

            Select::make('model.settings.reasoning_effort')
                ->title('Reasoning Effort')
                ->options([
                    'low' => 'Low - Fast, economical token usage',
                    'medium' => 'Medium - Balanced (recommended)',
                    'high' => 'High - Maximum reasoning quality',
                ])
                ->value('medium')
                ->help('Controls how many reasoning tokens the model generates. Only used when Reasoning is enabled.'),

            Select::make('model.settings.reasoning_summary')
                ->title('Reasoning Summary')
                ->options([
                    'none' => 'None - No summary (default)',
                    'auto' => 'Auto - Most detailed summary available',
                    'concise' => 'Concise - Brief summary',
                    'detailed' => 'Detailed - Comprehensive summary',
                ])
                ->value('none')
                ->help('Include a summary of the model\'s reasoning process in the response. Requires additional tokens. Only used when Reasoning is enabled.'),
        ];
    }
}
