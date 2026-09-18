<?php

declare(strict_types=1);

namespace App\Orchid\Screens\System;

use App\Models\EmailDomainRoleRule;
use App\Orchid\Layouts\EmailDomainRule\EmailDomainRuleEditLayout;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class EmailDomainRuleEditScreen extends Screen
{
    /**
     * @var EmailDomainRoleRule
     */
    public $rule;

    /**
     * Fetch data to be displayed on the screen.
     *
     * @return array
     */
    public function query(?EmailDomainRoleRule $rule = null): iterable
    {
        $this->rule = ($rule && $rule->exists)
            ? $rule
            : new EmailDomainRoleRule(['priority' => 0, 'is_active' => true]);

        return [
            'rule' => $this->rule,
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return $this->rule->exists
            ? __('Edit Domain Rule')
            : __('Create Domain Rule');
    }

    /**
     * The permissions required to access this screen.
     */
    public function permission(): ?iterable
    {
        return [
            'platform.access.email-domain-rules',
        ];
    }

    /**
     * The screen's action buttons.
     *
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Button::make(__('Save'))
                ->icon('bs.check-circle')
                ->method('save'),

            Button::make(__('Remove'))
                ->icon('bs.trash3')
                ->method('remove')
                ->canSee($this->rule->exists),
        ];
    }

    /**
     * The screen's layout elements.
     *
     * @return string[]|\Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::block([
                EmailDomainRuleEditLayout::class,
            ])
                ->title(__('Domain Rule'))
                ->description(__('Rules apply to new registrations only, existing accounts keep the role they were given.')),
        ];
    }

    public function save(Request $request, ?EmailDomainRoleRule $rule = null)
    {
        $rule = ($rule && $rule->exists) ? $rule : new EmailDomainRoleRule;

        // Normalise before validating, so a pasted pattern with stray case or spaces
        // is not rejected for something the screen fixes anyway.
        $data = $request->get('rule', []);
        $data['pattern'] = strtolower(trim((string) ($data['pattern'] ?? '')));
        $request->merge(['rule' => $data]);

        $request->validate([
            'rule.pattern' => [
                'required',
                'string',
                'max:255',
                // An exact host, or a wildcard covering its subdomains.
                'regex:/^(\*\.)?([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i',
                Rule::unique('email_domain_role_rules', 'pattern')->ignore($rule),
            ],
            'rule.role_id' => 'required|integer|exists:roles,id',
            'rule.priority' => 'required|integer|min:0',
            'rule.description' => 'nullable|string',
        ], [
            'rule.pattern.regex' => __('The pattern must be a hostname such as stud.example.org or *.example.org'),
        ]);

        $data['is_active'] = $request->boolean('rule.is_active', false);

        $rule->fill($data)->save();

        Toast::info(__('Rule was saved'));

        return redirect()->route('platform.systems.email-domain-rules');
    }

    public function remove(EmailDomainRoleRule $rule)
    {
        $rule->delete();

        Toast::info(__('Rule was removed'));

        return redirect()->route('platform.systems.email-domain-rules');
    }
}
