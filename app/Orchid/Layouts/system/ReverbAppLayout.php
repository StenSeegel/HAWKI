<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\System;

use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

class ReverbAppLayout extends Rows
{
    /**
     * The screen's layout elements.
     *
     * @return \Orchid\Screen\Field[]
     */
    public function fields(): array
    {
        return [
            Select::make('settings.reverb_apps__provider')
                ->title('Apps Provider')
                ->options([
                    'config' => 'Config (Static configuration)',
                ])
                ->value('config')
                ->help('config(\'reverb.apps.provider\')<br/>How Reverb applications are managed (currently only config is supported)'),

            Input::make('settings.reverb_apps__apps__0__key')
                ->title('App Key')
                ->placeholder('hawki-app-key')
                ->help('config(\'reverb.apps.apps.0.key\')<br/>The application key for WebSocket authentication'),

            Input::make('settings.reverb_apps__apps__0__secret')
                ->title('App Secret')
                ->type('password')
                ->placeholder('hawki-app-secret')
                ->help('config(\'reverb.apps.apps.0.secret\')<br/>The application secret for WebSocket authentication'),

            Input::make('settings.reverb_apps__apps__0__app_id')
                ->title('App ID')
                ->placeholder('hawki')
                ->help('config(\'reverb.apps.apps.0.app_id\')<br/>The application identifier for WebSocket connections'),

            TextArea::make('settings.reverb_apps__apps__0__allowed_origins')
                ->title('Allowed Origins')
                ->rows(3)
                ->placeholder('["*"]')
                ->help('config(\'reverb.apps.apps.0.allowed_origins\')<br/>JSON array of allowed origins for CORS (use ["*"] for all origins)'),

            Input::make('settings.reverb_apps__apps__0__ping_interval')
                ->type('number')
                ->title('Ping Interval')
                ->placeholder('60')
                ->help('config(\'reverb.apps.apps.0.ping_interval\')<br/>Interval in seconds for ping messages to keep connections alive'),

            Input::make('settings.reverb_apps__apps__0__max_message_size')
                ->type('number')
                ->title('Max Message Size')
                ->placeholder('250000')
                ->help('config(\'reverb.apps.apps.0.max_message_size\')<br/>Maximum size in bytes for WebSocket messages'),
        ];
    }
}
