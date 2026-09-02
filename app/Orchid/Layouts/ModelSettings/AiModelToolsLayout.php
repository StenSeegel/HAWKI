<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ModelSettings;

use Orchid\Screen\Fields\CheckBox;
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
                ->placeholder('File Uploads'),

            CheckBox::make('model.settings.tools.vision')
                ->title('Vision')
                ->help('Enable image/vision processing capabilities')
                ->sendTrueOrFalse()
                ->placeholder('Image Analysis'),

            CheckBox::make('model.settings.tools.web_search')
                ->title('Web Search')
                ->help('Enable web search integration (requires provider support)')
                ->sendTrueOrFalse()
                ->placeholder('Web Searches'),

            CheckBox::make('model.settings.tools.code_interpreter')
                ->title('Code Interpreter')
                ->help('Enable code execution. There is no chat button for this: the model is offered the tool on every request and calls it when a result has to be computed.')
                ->sendTrueOrFalse()
                ->placeholder('Code Execution'),

            CheckBox::make('model.settings.tools.reasoning')
                ->title('Reasoning')
                ->help('Enable advanced reasoning and chain-of-thought capabilities')
                ->sendTrueOrFalse()
                ->placeholder('Advanced Reasoning'),

            CheckBox::make('model.settings.tools.image_gen')
                ->title('Image Generation')
                ->help('Enable image generation capabilities')
                ->sendTrueOrFalse()
                ->placeholder('Image Generation'),
        ];
    }
}
