<?php

namespace App\Orchid\Layouts\ModelSettings;

use Orchid\Screen\Fields\CheckBox;
use Orchid\Screen\Fields\Code;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

class McpServerConfigurationLayout extends Rows
{
    /**
     * Get the fields to be displayed.
     *
     * @return array
     */
    protected function fields(): iterable
    {
        $server = $this->query->get('server');

        return [
            Input::make('server.name')
                ->title('System Name')
                ->placeholder('e.g., memory-server')
                ->help('Unique identifier for the server (slug).')
                ->required(),

            Input::make('server.label')
                ->title('Display Label')
                ->placeholder('e.g., Memory Storage')
                ->help('The name shown in the user interface.')
                ->required(),

            TextArea::make('server.description')
                ->title('Description')
                ->placeholder('Describe what this server provides...')
                ->rows(3),

            Select::make('server.type')
                ->title('Connection Type')
                ->options([
                    'http' => 'HTTP/SSE',
                    'stdio' => 'STDIO (Local Process)',
                ])
                ->required(),

            Input::make('server.url')
                ->title('HTTP URL')
                ->placeholder('https://mcp-server.example.com/sse')
                ->help('The endpoint for HTTP/SSE connections.')
                ->canSee($server->type === 'http'),

            Input::make('server.command')
                ->title('Process Command')
                ->placeholder('e.g., npx, python, node')
                ->help('The command to start the MCP process.')
                ->canSee($server->type === 'stdio'),

            Code::make('server.args')
                ->title('Process Arguments')
                ->language(Code::JS)
                ->help('JSON array of command line arguments.')
                ->canSee($server->type === 'stdio'),

            Code::make('server.headers')
                ->title('HTTP Headers')
                ->language(Code::JS)
                ->help('JSON object of headers (e.g., for Authorization).')
                ->canSee($server->type === 'http'),

            Input::make('server.display_order')
                ->title('Display Order')
                ->type('number')
                ->value(0)
                ->help('Servers are sorted by this value.'),

            CheckBox::make('server.is_active')
                ->title('Active')
                ->placeholder('Enable this server')
                ->sendTrueValue()
                ->help('Inactive servers will not be used for tool calls.'),
        ];
    }
}
