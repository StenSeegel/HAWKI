<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ModelSettings;

use App\Models\ApiProvider;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Group;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
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
                ->placeholder('https://api.example.org/mcp')
                ->help('The JSON-RPC endpoint. Behind an API gateway this is the gateway\'s own MCP endpoint - for LiteLLM the API base URL plus /mcp. Use "Test MCP servers" above to see which tools it offers.'),

            Group::make([
                Select::make('mcp_servers.'.$this->name.'.api_key_provider')
                    ->title('Authenticate with the key of')
                    ->options($this->providers())
                    ->empty('No authentication')
                    ->help('The API provider whose key opens this endpoint. The key is read from that provider, so it is not stored twice and rotating it here is not necessary.'),

                Input::make('mcp_servers.'.$this->name.'.api_key_header')
                    ->title('Key header')
                    ->placeholder('Authorization')
                    ->help('The header the key is sent in, always as "Bearer <key>". LiteLLM documents x-litellm-api-key and also accepts Authorization, which is the default.'),
            ])->widthColumns('1fr 1fr'),

            Group::make([
                Input::make('mcp_servers.'.$this->name.'.gateway_server')
                    ->title('Gateway server')
                    ->placeholder('google_search_http')
                    ->help('Which of the gateway\'s MCP servers this entry uses. It scopes the endpoint to that server, so only its tools are offered. Leave empty for a server reached directly.'),

                Input::make('mcp_servers.'.$this->name.'.tool_prefix')
                    ->title('Tool name prefix')
                    ->placeholder('defaults to the gateway server plus a dash')
                    ->help('Only needed for a gateway that names the tools differently. By default the prefix is the gateway server plus a dash, e.g. google_search_http-google_search. The bindings stay written in the plain tool names either way.'),
            ])->widthColumns('1fr 1fr'),

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

    /**
     * @return array<string, string>
     */
    private function providers(): array
    {
        return ApiProvider::orderBy('unique_name')
            ->pluck('provider_name', 'unique_name')
            ->map(fn ($label, $key) => $label ?: $key)
            ->all();
    }
}
