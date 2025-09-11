<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\System;

use Orchid\Screen\Fields\Select;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Rows;

class BroadcastingLayout extends Rows
{
    /**
     * The screen's layout elements.
     *
     * @return \Orchid\Screen\Field[]
     */
    public function fields(): array
    {
        return [
            Select::make('settings.broadcasting_default')
                ->title('Default Broadcaster')
                ->options([
                    'reverb' => 'Reverb (Real-time WebSocket)',
                    'log' => 'Log (For testing)',
                    'null' => 'Null (Disabled)',
                ])
                ->value('reverb')
                ->help('config(\'broadcasting.default\')<br/>The default broadcaster for real-time events (recommended: reverb)'),

            Input::make('settings.broadcasting_connections__reverb__driver')
                ->title('Reverb Driver')
                ->value('reverb')
                ->readonly()
                ->help('config(\'broadcasting.connections.reverb.driver\')<br/>The driver type for Reverb broadcasting (fixed value)'),

            Input::make('settings.broadcasting_connections__reverb__key')
                ->title('Reverb App Key')
                ->placeholder('hawki-app-key')
                ->help('config(\'broadcasting.connections.reverb.key\')<br/>Application key for Reverb broadcasting authentication'),

            Input::make('settings.broadcasting_connections__reverb__secret')
                ->title('Reverb App Secret')
                ->type('password')
                ->placeholder('hawki-app-secret')
                ->help('config(\'broadcasting.connections.reverb.secret\')<br/>Application secret for Reverb broadcasting authentication'),

            Input::make('settings.broadcasting_connections__reverb__app_id')
                ->title('Reverb App ID')
                ->placeholder('hawki')
                ->help('config(\'broadcasting.connections.reverb.app_id\')<br/>Application identifier for Reverb broadcasting'),

            Input::make('settings.broadcasting_connections__reverb__options__host')
                ->title('Broadcasting Host')
                ->placeholder('hawki.test')
                ->help('config(\'broadcasting.connections.reverb.options.host\')<br/>Hostname for broadcasting connections'),

            Input::make('settings.broadcasting_connections__reverb__options__port')
                ->type('number')
                ->title('Broadcasting Port')
                ->placeholder('8080')
                ->help('config(\'broadcasting.connections.reverb.options.port\')<br/>Port for broadcasting connections'),

            Select::make('settings.broadcasting_connections__reverb__options__scheme')
                ->title('Broadcasting Scheme')
                ->options([
                    'https' => 'HTTPS (Secure)',
                    'http' => 'HTTP (Insecure)',
                ])
                ->value('https')
                ->help('config(\'broadcasting.connections.reverb.options.scheme\')<br/>Protocol scheme for broadcasting connections'),
        ];
    }
}
