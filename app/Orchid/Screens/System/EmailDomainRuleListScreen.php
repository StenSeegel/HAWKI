<?php

declare(strict_types=1);

namespace App\Orchid\Screens\System;

use App\Models\EmailDomainRoleRule;
use App\Orchid\Layouts\AccessControls\AccessControlsTabMenu;
use App\Orchid\Layouts\EmailDomainRule\EmailDomainRuleListLayout;
use Illuminate\Http\Request;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Toast;

class EmailDomainRuleListScreen extends Screen
{
    /**
     * Fetch data to be displayed on the screen.
     *
     * @return array
     */
    public function query(): iterable
    {
        return [
            'rules' => EmailDomainRoleRule::with('role')
                ->orderBy('priority')
                ->orderBy('id')
                ->paginate(),
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return __('E-mail Domain Rules');
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        return __('Assign the role of a self-registering user from the domain of their e-mail address. While at least one rule is active, addresses matching no rule cannot register.');
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
            Link::make(__('Add'))
                ->icon('bs.plus-circle')
                ->route('platform.systems.email-domain-rules.create'),
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
            AccessControlsTabMenu::class,
            EmailDomainRuleListLayout::class,
        ];
    }

    public function toggleActive(Request $request): void
    {
        $rule = EmailDomainRoleRule::findOrFail($request->get('id'));
        $rule->update(['is_active' => ! $rule->is_active]);

        Toast::info($rule->is_active
            ? __('Rule was activated')
            : __('Rule was deactivated'));
    }

    public function remove(Request $request): void
    {
        EmailDomainRoleRule::findOrFail($request->get('id'))->delete();

        Toast::info(__('Rule was removed'));
    }
}
