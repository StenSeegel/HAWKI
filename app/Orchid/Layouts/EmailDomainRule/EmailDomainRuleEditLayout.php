<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\EmailDomainRule;

use App\Models\Role;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\CheckBox;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

class EmailDomainRuleEditLayout extends Rows
{
    /**
     * The screen's layout elements.
     *
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Input::make('rule.pattern')
                ->type('text')
                ->max(255)
                ->required()
                ->title(__('Pattern'))
                ->placeholder('stud.example.org')
                ->help(__('An exact host (stud.example.org) or a subdomain wildcard (*.example.org)')),

            Select::make('rule.role_id')
                ->fromModel(Role::class, 'name')
                ->required()
                ->title(__('Role'))
                ->help(__('The role a user registering with a matching address receives')),

            Input::make('rule.priority')
                ->type('number')
                ->required()
                ->title(__('Priority'))
                ->help(__('The lowest value among the matching rules wins')),

            CheckBox::make('rule.is_active')
                ->title(__('Active'))
                ->placeholder(__('Use this rule for new registrations'))
                ->help(__('As soon as one rule is active, the user group dropdown disappears and every registration is verified'))
                ->sendTrueOrFalse(),

            TextArea::make('rule.description')
                ->rows(3)
                ->title(__('Description')),
        ];
    }
}
