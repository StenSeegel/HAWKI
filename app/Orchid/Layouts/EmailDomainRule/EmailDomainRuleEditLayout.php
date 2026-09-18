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
                ->title(__('Automatically assign the following role'))
                ->help(__('Every user who registers with a matching address receives this role. Saving the rule also creates the matching entry on the role assignment screen.')),

            CheckBox::make('rule.needs_admin_approval')
                ->title(__('Needs admin approval'))
                ->placeholder(__('An administrator has to release the account before the role takes effect'))
                ->help(__('Off means a confirmed address from this domain is enough and the role is granted right away. On keeps the account waiting in the approval list.'))
                ->sendTrueOrFalse(),

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
