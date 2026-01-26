<?php

declare(strict_types=1);

namespace App\Orchid\Screens\ModelSettings;

use App\Models\ApiMcp;
use App\Models\AppSetting;
use App\Orchid\Layouts\ModelSettings\AssistantsTabMenu;
use App\Orchid\Traits\OrchidSettingsManagementTrait;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Screen\TD;
use Orchid\Support\Facades\Layout;

class McpSettingsScreen extends Screen
{
    use OrchidSettingsManagementTrait;

    /**
     * Fetch data to be displayed on the screen.
     */
    public function query(): iterable
    {
        $settings = AppSetting::where('group', 'mcp')->get();

        return [
            'servers' => ApiMcp::orderBy('display_order')->get(),
            'settings' => $settings,
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return 'MCP Server Configuration';
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        return 'Configure Model Context Protocol (MCP) servers for AI tool integrations.';
    }

    /**
     * Permission required to access this screen.
     */
    public function permission(): ?iterable
    {
        return [
            'platform.modelsettings.assistants',
        ];
    }

    /**
     * The screen's action buttons.
     *
     * @return \Orchid\Screen\Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make('Create Server')
                ->icon('bs.plus-circle')
                ->route('platform.models.mcp.create'),
        ];
    }

    /**
     * The screen's layout elements.
     */
    public function layout(): iterable
    {
        $fields = [];
        foreach ($this->query()['settings'] as $setting) {
            $fields[] = $this->generateFieldForSetting($setting);
        }

        return [
            AssistantsTabMenu::class,

            Layout::rows(array_merge($fields, [
                Button::make('Save General Settings')
                    ->icon('bs.check-circle')
                    ->method('saveSettings'),
            ]))->title('General Settings'),

            Layout::table('servers', [
                TD::make('name', 'Name')
                    ->sort()
                    ->render(fn (ApiMcp $server) => Link::make($server->name ?? '')
                        ->route('platform.models.mcp.edit', $server)
                        ->icon('bs.pencil')
                    ),

                TD::make('label', 'Label')
                    ->sort(),

                TD::make('type', 'Type')
                    ->sort()
                    ->render(fn (ApiMcp $server) => $server->type === 'http'
                            ? '<span class="badge bg-info">HTTP</span>'
                            : '<span class="badge bg-secondary">STDIO</span>'
                    ),

                TD::make('is_active', 'Status')
                    ->sort()
                    ->render(fn (ApiMcp $server) => $server->is_active
                            ? '<span class="badge bg-success">Active</span>'
                            : '<span class="badge bg-secondary">Inactive</span>'
                    ),

                TD::make('display_order', 'Order')
                    ->sort(),
            ])->title('MCP Servers'),
        ];
    }
}
