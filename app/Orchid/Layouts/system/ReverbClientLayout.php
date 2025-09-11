<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\System;

use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

class ReverbClientLayout extends Rows
{
    /**
     * The screen's layout elements.
     *
     * @return \Orchid\Screen\Field[]
     */
    public function fields(): array
    {
        return [
            Input::make('settings.reverb_apps__apps__0__options__host')
                ->title('Client Host')
                ->placeholder('hawki.test')
                ->help('config(\'reverb.apps.apps.0.options.host\')<br/>The hostname that WebSocket clients will connect to'),

            Input::make('settings.reverb_apps__apps__0__options__port')
                ->type('number')
                ->title('Client Port')
                ->placeholder('443')
                ->help('config(\'reverb.apps.apps.0.options.port\')<br/>The port that WebSocket clients will connect to (default: 443 for HTTPS, 80 for HTTP)'),

            Select::make('settings.reverb_apps__apps__0__options__scheme')
                ->title('Connection Scheme')
                ->options([
                    'https' => 'HTTPS (Secure)',
                    'http' => 'HTTP (Insecure)',
                ])
                ->help('config(\'reverb.apps.apps.0.options.scheme\')<br/>The protocol scheme for WebSocket connections (HTTPS recommended for production)'),
        ];
    }
}
