<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ModelSettings;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Fields\TextArea;

/**
 * The fields of one HAWKI tool: what the model is told about it, and which MCP
 * server tool serves each of its routes.
 *
 * Not a Rows layout: the fields are placed inside the tool's expandable section
 * on the Tools screen, which builds the Rows itself.
 */
class HawkiToolFieldsLayout
{
    /**
     * Human readable explanation of the routes a tool picks between.
     */
    private const ROUTE_HELP = [
        'general' => 'Used for ordinary queries.',
        'local' => 'Used when the query names the university (JLU, Justus-Liebig, uni-giessen). A bare mention of the city does not count - it also matches questions about the city and the German verb "gießen".',
        'url' => 'Used when the query is a single URL whose content should be read.',
        'run' => 'Used for every execution.',
        'generate' => 'Used for every generation.',
    ];

    public function __construct(
        private readonly string $key
    ) {}

    /**
     * @return Field[]
     */
    public function getFields(): array
    {
        $tool = config('hawki_tools.tools.'.$this->key, []);
        $label = $tool['label'] ?? $this->key;

        $servers = array_keys(config('hawki_tools.mcp_servers', []));
        $serverOptions = array_combine($servers, $servers) ?: [];

        $fields = [
            Select::make('tools.'.$this->key.'.activation')
                ->title('When the tool is offered')
                ->options([
                    'toggle' => 'Only when the user switches it on in the chat',
                    'always' => 'On every request of a model that carries it',
                ])
                ->help('"Only when the user switches it on" needs a button in the chat UI - web search and image generation have one. A tool without a button must be set to "on every request", otherwise it never reaches a model. The model flag and the provider override still apply, and the model still decides whether to call it.'),

            Input::make('tools.'.$this->key.'.description')
                ->title('Function description')
                ->help('Sent to the model as the description of the '.$this->key.' function. Keep it short; it is what the model reads when deciding whether the tool fits.')
                ->maxlength(1000),

            TextArea::make('tools.'.$this->key.'.awareness')
                ->title('Tool prompt')
                ->rows(16)
                ->help('Added to every request this tool is attached to. Without it the models answer that they cannot do this instead of calling the tool. This text alone decides when the tool is called - name the wordings and the kinds of request that must trigger it, and the ones that must not.'),

            Select::make('bindings.'.$this->key.'.server')
                ->title('MCP server')
                ->options($serverOptions)
                ->empty('Not bound', '')
                ->help('The server this tool is executed on. A provider may pin a different server in its own HAWKI tool settings.'),
        ];

        foreach (array_keys(config('hawki_tools.bindings.'.$this->key.'.tools', [])) as $route) {
            $fields[] = Input::make('bindings.'.$this->key.'.tools.'.$route)
                ->title('Tool for the "'.$route.'" route')
                ->placeholder('mcp tool name')
                ->help(self::ROUTE_HELP[$route] ?? 'The name of the MCP tool called for this route.');
        }

        unset($label);

        return $fields;
    }
}
