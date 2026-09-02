<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ModelSettings;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

/**
 * Which MCP server serves a HAWKI tool, and which of the server's tools it calls
 * for each route.
 */
class HawkiToolBindingLayout extends Rows
{
    /**
     * Human readable explanation of the routes a tool picks between.
     */
    private const ROUTE_HELP = [
        'general' => 'Used for ordinary queries.',
        'local' => 'Used when the query names the university (JLU, Justus-Liebig, uni-giessen). A bare mention of the city does not count - it also matches questions about the city and the German verb "gießen".',
        'url' => 'Used when the query is a single URL whose content should be read.',
    ];

    /**
     * @return Field[]
     */
    public function fields(): array
    {
        $servers = array_keys(config('hawki_tools.mcp_servers', []));
        $serverOptions = array_combine($servers, $servers) ?: [];

        $fields = [];

        foreach (config('hawki_tools.bindings', []) as $toolKey => $binding) {
            $label = config('hawki_tools.tools.'.$toolKey.'.label', $toolKey);

            $fields[] = Select::make('bindings.'.$toolKey.'.server')
                ->title($label.': MCP server')
                ->options($serverOptions)
                ->empty('Not bound', '')
                ->help('The server this tool is executed on. A provider may pin a different server in its own HAWKI tool settings.');

            foreach (array_keys($binding['tools'] ?? []) as $route) {
                $fields[] = Input::make('bindings.'.$toolKey.'.tools.'.$route)
                    ->title($label.': tool for the "'.$route.'" route')
                    ->placeholder('mcp tool name')
                    ->help(self::ROUTE_HELP[$route] ?? 'The name of the MCP tool called for this route.');
            }
        }

        return $fields;
    }
}
