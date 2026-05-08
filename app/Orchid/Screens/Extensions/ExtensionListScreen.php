<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Extensions;

use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

class ExtensionListScreen extends Screen
{
    public function query(): iterable
    {
        return [];
    }

    public function name(): ?string
    {
        return 'Extensions';
    }

    public function description(): ?string
    {
        return 'Manage your HAWKI extensions here.';
    }

    public function permission(): ?iterable
    {
        return ['platform.extensions'];
    }

    public function commandBar(): iterable
    {
        return [];
    }

    public function layout(): iterable
    {
        return [
            Layout::rows([
                \Orchid\Screen\Fields\Group::make([
                    \Orchid\Screen\Fields\Label::make('transcription_label')
                        ->title('Transcription')
                        ->value('Provides Speech-to-Text transcription powered by Custom Speaches or an OpenAI model.'),
                    Link::make('Configure')
                        ->route('platform.extensions.transcription')
                        ->icon('bs.gear'),
                ])
                    ->alignCenter()
                    ->widthColumns('1fr max-content'),
            ]),
        ];
    }
}
