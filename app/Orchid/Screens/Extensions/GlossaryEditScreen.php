<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Extensions;

use App\Models\TranslateGlossary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Orchid\Platform\Models\Role;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class GlossaryEditScreen extends Screen
{
    public TranslateGlossary $glossary;

    public function __construct()
    {
        $this->glossary = new TranslateGlossary;
    }

    public function query(TranslateGlossary $glossary): iterable
    {
        $this->glossary = $glossary;

        return [
            'glossary' => $glossary,
        ];
    }

    public function name(): ?string
    {
        return $this->glossary->exists
            ? 'Edit Glossary: '.$this->glossary->display_name
            : 'Create Glossary';
    }

    public function description(): ?string
    {
        return 'Manage glossary properties.';
    }

    public function permission(): ?iterable
    {
        return ['platform.extensions'];
    }

    public function commandBar(): iterable
    {
        return [
            Link::make('Back')
                ->icon('bs.arrow-left-circle')
                ->route('platform.extensions.translation'),

            Button::make('Save')
                ->icon('bs.check-circle')
                ->method('save'),

            Button::make('Delete')
                ->icon('bs.trash3')
                ->confirm('Are you sure you want to delete this glossary? This cannot be undone.')
                ->method('destroy')
                ->canSee($this->glossary->exists),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::block([
                Layout::rows([
                    Input::make('glossary.display_name')
                        ->title('Display Name')
                        ->required()
                        ->help('The human-readable name shown to users.')
                        ->value($this->glossary->display_name),

                    Input::make('glossary.unique_name')
                        ->title('Unique Name')
                        ->required()
                        ->help('Internal unique identifier for this glossary.')
                        ->value($this->glossary->unique_name),

                    Input::make('glossary.domain')
                        ->title('Domain')
                        ->help('The subject domain of this glossary (e.g. general, medical, legal).')
                        ->value($this->glossary->domain),

                    Select::make('glossary.visibility')
                        ->title('Visibility')
                        ->options([
                            'private' => 'Private',
                            'org' => 'Organisation',
                            'public' => 'Public',
                        ])
                        ->value($this->glossary->visibility ?? 'private')
                        ->help('Controls who can see and use this glossary.'),

                    Select::make('glossary.organization_id')
                        ->fromModel(Role::class, 'name')
                        ->title('Organisation / Role')
                        ->help('Specify which role can see this glossary when visibility is "Organisation".')
                        ->empty('No role selected')
                        ->value($this->glossary->organization_id),

                    TextArea::make('glossary.description')
                        ->title('Description')
                        ->rows(4)
                        ->help('Optional description of this glossary\'s purpose and contents.')
                        ->value($this->glossary->description),
                ]),
            ])
                ->title('Glossary Details')
                ->description('Edit all properties of this glossary entry.'),
        ];
    }

    public function save(Request $request): RedirectResponse
    {
        $request->validate([
            'glossary.display_name' => 'required|string|max:255',
            'glossary.unique_name' => 'required|string|max:255|unique:translate_glossaries,unique_name,'.$this->glossary->id,
            'glossary.domain' => 'nullable|string|max:255',
            'glossary.visibility' => 'required|in:private,org,public',
            'glossary.organization_id' => 'nullable|integer',
            'glossary.description' => 'nullable|string',
        ]);

        $data = $request->input('glossary');
        $data['description'] = $data['description'] ?? '';
        $data['domain'] = $data['domain'] ?? '';

        $this->glossary->fill($data)->save();

        Toast::success("Glossary '{$this->glossary->display_name}' updated successfully.");

        return redirect()->route('platform.extensions.glossary.edit', $this->glossary);
    }

    public function destroy(): RedirectResponse
    {
        $name = $this->glossary->display_name;
        $this->glossary->delete();

        Toast::success("Glossary '{$name}' deleted.");

        return redirect()->route('platform.extensions.translation');
    }
}
