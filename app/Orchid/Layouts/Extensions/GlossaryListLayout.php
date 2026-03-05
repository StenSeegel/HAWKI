<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Extensions;

use App\Models\TranslateGlossary;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\DropDown;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class GlossaryListLayout extends Table
{
    public $target = 'glossaries';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('display_name', 'Name')
                ->cantHide()
                ->sort()
                ->render(fn (TranslateGlossary $glossary) => $glossary->display_name),

            TD::make('domain', 'Domain')
                ->sort()
                ->render(fn (TranslateGlossary $glossary) => $glossary->domain ?? '-'),

            TD::make('visibility', 'Visibility')
                ->sort()
                ->align(TD::ALIGN_CENTER)
                ->width('120px')
                ->render(function (TranslateGlossary $glossary) {
                    $class = match ($glossary->visibility) {
                        'public' => 'bg-success',
                        'organization' => 'bg-info',
                        default => 'bg-secondary',
                    };

                    return "<span class=\"badge {$class}\">{$glossary->visibility}</span>";
                }),

            TD::make('entries_count', 'Entries')
                ->align(TD::ALIGN_CENTER)
                ->width('90px')
                ->render(fn (TranslateGlossary $glossary) => $glossary->entries_count ?? 0),

            TD::make('created_at', 'Created')
                ->render(fn (TranslateGlossary $glossary) => $glossary->created_at?->format('d.m.Y'))
                ->align(TD::ALIGN_RIGHT)
                ->sort(),

            TD::make('Actions')
                ->align(TD::ALIGN_CENTER)
                ->width('80px')
                ->render(fn (TranslateGlossary $glossary) => DropDown::make()
                    ->icon('bs.three-dots-vertical')
                    ->list([
                        Link::make('Edit')
                            ->icon('bs.pencil')
                            ->route('platform.extensions.glossary.edit', $glossary),

                        Button::make('Delete')
                            ->icon('bs.trash3')
                            ->confirm("Are you sure you want to delete glossary '{$glossary->display_name}'?")
                            ->method('deleteGlossary', ['id' => $glossary->id]),
                    ])),
        ];
    }
}
