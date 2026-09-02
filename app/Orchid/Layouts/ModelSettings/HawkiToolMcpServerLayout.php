<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ModelSettings;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Group;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Switcher;
use Orchid\Screen\Layouts\Rows;

/**
 * The MCP servers the HAWKI tools are executed on.
 */
class HawkiToolMcpServerLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        $fields = [];

        foreach (array_keys(config('hawki_tools.mcp_servers', [])) as $name) {
            $fields[] = Input::make('mcp_servers.'.$name.'.url')
                ->title($name.': endpoint URL')
                ->placeholder('https://example.org/mcp')
                ->help('The JSON-RPC endpoint of the MCP server. Use "Test MCP servers" above to see which tools it offers.');

            $fields[] = Group::make([
                Input::make('mcp_servers.'.$name.'.timeout')
                    ->title('Timeout (seconds)')
                    ->type('number')
                    ->min(1)
                    ->max(300)
                    ->help('A tool call happens inside a user request, so keep this well below the request time limit.'),

                Switcher::make('mcp_servers.'.$name.'.requires_session')
                    ->title('Requires session handshake')
                    ->sendTrueOrFalse()
                    ->help('On for servers that expect an initialize call and an mcp-session-id header, off for servers that answer tool calls straight away.'),
            ])->widthColumns('1fr 1fr');
        }

        return $fields;
    }
}
