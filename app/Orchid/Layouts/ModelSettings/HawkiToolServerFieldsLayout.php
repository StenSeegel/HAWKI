<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ModelSettings;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Group;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Switcher;

/**
 * The fields of one MCP server.
 *
 * Not a Rows layout: the fields are placed inside the server's expandable
 * section on the Tools screen, which builds the Rows itself.
 */
class HawkiToolServerFieldsLayout
{
    public function __construct(
        private readonly string $name
    ) {}

    /**
     * @return Field[]
     */
    public function getFields(): array
    {
        return [
            Input::make('mcp_servers.'.$this->name.'.url')
                ->title('Endpoint URL')
                ->placeholder('https://example.org/mcp')
                ->help('The JSON-RPC endpoint of the MCP server. Use "Test MCP servers" above to see which tools it offers.'),

            Group::make([
                Input::make('mcp_servers.'.$this->name.'.timeout')
                    ->title('Timeout (seconds)')
                    ->type('number')
                    ->min(1)
                    ->max(300)
                    ->help('A tool call happens inside a user request, so keep this well below the request time limit.'),

                Switcher::make('mcp_servers.'.$this->name.'.requires_session')
                    ->title('Requires session handshake')
                    ->sendTrueOrFalse()
                    ->help('On for servers that expect an initialize call and an mcp-session-id header, off for servers that answer tool calls straight away.'),
            ])->widthColumns('1fr 1fr'),
        ];
    }
}
