<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\EmailDomainRule;

use App\Models\EmailDomainRoleRule;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\DropDown;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class EmailDomainRuleListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'rules';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('pattern', __('Pattern'))
                ->sort()
                ->cantHide()
                ->render(fn (EmailDomainRoleRule $rule) => Link::make($rule->pattern)
                    ->route('platform.systems.email-domain-rules.edit', $rule->id)),

            TD::make('role', __('Role'))
                ->render(fn (EmailDomainRoleRule $rule) => $rule->role
                    ? '<span class="badge bg-primary">'.e($rule->role->name).'</span>'
                    : '<span class="badge bg-danger">'.__('No role').'</span>'),

            TD::make('needs_admin_approval', __('Approval'))
                ->sort()
                ->render(fn (EmailDomainRoleRule $rule) => $rule->needs_admin_approval
                    ? '<span class="badge bg-warning text-dark">'.__('Admin approval').'</span>'
                    : '<span class="badge bg-success">'.__('Automatic').'</span>'),

            TD::make('priority', __('Priority'))
                ->sort()
                ->align(TD::ALIGN_RIGHT),

            TD::make('is_active', __('Active'))
                ->sort()
                ->render(fn (EmailDomainRoleRule $rule) => $rule->is_active
                    ? '<span class="badge bg-success">'.__('Active').'</span>'
                    : '<span class="badge bg-secondary">'.__('Inactive').'</span>'),

            TD::make('description', __('Description'))
                ->defaultHidden(),

            TD::make(__('Actions'))
                ->align(TD::ALIGN_CENTER)
                ->width('100px')
                ->render(fn (EmailDomainRoleRule $rule) => DropDown::make()
                    ->icon('bs.three-dots-vertical')
                    ->list([
                        Link::make(__('Edit'))
                            ->route('platform.systems.email-domain-rules.edit', $rule->id)
                            ->icon('bs.pencil'),

                        Button::make($rule->is_active ? __('Deactivate') : __('Activate'))
                            ->icon($rule->is_active ? 'bs.eye-slash' : 'bs.eye')
                            ->method('toggleActive', ['id' => $rule->id]),

                        Button::make(__('Remove'))
                            ->icon('bs.trash3')
                            ->confirm(__('Once the rule is removed, addresses of this domain are no longer accepted. Existing accounts keep their role.'))
                            ->method('remove', ['id' => $rule->id]),
                    ])),
        ];
    }

    protected function iconNotFound(): string
    {
        return 'bs.info-circle';
    }

    protected function textNotFound(): string
    {
        return __('No e-mail domain rules defined.');
    }

    protected function subNotFound(): string
    {
        return __('While no rule is active, self-registering users pick their own user group.');
    }
}
