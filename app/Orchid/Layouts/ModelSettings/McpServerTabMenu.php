<?php

namespace App\Orchid\Layouts\ModelSettings;

use Orchid\Screen\Actions\Menu;
use Orchid\Screen\Layouts\TabMenu;

class McpServerTabMenu extends TabMenu
{
    /**
     * Get the menu items for the MCP server management tabs.
     *
     * @return Menu[]
     */
    protected function navigations(): array
    {
        $server = $this->query->get('server');
        $serverId = $server?->id;

        if (! $serverId) {
            return [
                Menu::make('Configuration')
                    ->route('platform.models.mcp.create')
                    ->active('platform.models.mcp.create'),
            ];
        }

        return [
            Menu::make('MCP Tools')
                ->route('platform.models.mcp.edit', ['server' => $serverId])
                ->active('platform.models.mcp.edit'),

            Menu::make('Configuration')
                ->route('platform.models.mcp.config', ['server' => $serverId])
                ->active('platform.models.mcp.config'),
        ];
    }
}
