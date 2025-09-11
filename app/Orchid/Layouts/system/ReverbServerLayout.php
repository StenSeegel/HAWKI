<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\System;

use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

class ReverbServerLayout extends Rows
{
    /**
     * The screen's layout elements.
     *
     * @return \Orchid\Screen\Field[]
     */
    public function fields(): array
    {
        return [
            Select::make('settings.reverb_default')
                ->title('Default Server')
                ->options([
                    'reverb' => 'Reverb',
                ])
                ->value('reverb')
                ->help('config(\'reverb.default\')<br/>The default Reverb server to use for WebSocket connections'),

            Input::make('settings.reverb_servers__reverb__host')
                ->title('Server Host')
                ->placeholder('127.0.0.1')
                ->help('config(\'reverb.servers.reverb.host\')<br/>The host address the Reverb server binds to (0.0.0.0 for all interfaces)'),

            Input::make('settings.reverb_servers__reverb__hostname')
                ->title('Server Hostname')
                ->placeholder('hawki.test')
                ->help('config(\'reverb.servers.reverb.hostname\')<br/>The public hostname for the Reverb server (used for client connections)'),

            Input::make('settings.reverb_servers__reverb__port')
                ->type('number')
                ->title('Server Port')
                ->placeholder('8080')
                ->help('config(\'reverb.servers.reverb.port\')<br/>The port the Reverb server listens on (default: 8080)'),

            Input::make('settings.reverb_servers__reverb__max_request_size')
                ->type('number')
                ->title('Max Request Size')
                ->placeholder('10000')
                ->help('config(\'reverb.servers.reverb.max_request_size\')<br/>Maximum size in bytes for WebSocket requests'),
        ];
    }
}
