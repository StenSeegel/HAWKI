<?php

namespace App\Orchid\Layouts\System;

use App\Orchid\Traits\OrchidSettingsManagementTrait;
use Orchid\Screen\Layouts\Rows;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;

class ViteLayout extends Rows
{
    use OrchidSettingsManagementTrait;

    /**
     * Used to create the title of a group of form elements.
     *
     * @var string|null
     */
    protected $title = 'Frontend Configuration';

    /**
     * Get the fields elements to be displayed.
     *
     * @return \Orchid\Screen\Field[]
     */
    protected function fields(): iterable
    {
        return [
            Input::make('settings.vite_reverb_app_key')
                ->title('Frontend App Key')
                ->help('config(\'vite.reverb_app_key\')<br/>Application key used by frontend JavaScript for WebSocket authentication')
                ->placeholder('laravel-herd'),

            Input::make('settings.vite_reverb_host')
                ->title('Frontend Host')
                ->help('config(\'vite.reverb_host\')<br/>Hostname used by frontend for WebSocket connections')
                ->placeholder('hawki.test'),

            Input::make('settings.vite_reverb_port')
                ->title('Frontend Port')
                ->help('config(\'vite.reverb_port\')<br/>Port used by frontend for WebSocket connections')
                ->type('number')
                ->placeholder('8080'),

            Select::make('settings.vite_reverb_scheme')
                ->title('Frontend Scheme')
                ->help('config(\'vite.reverb_scheme\')<br/>Protocol scheme used by frontend for WebSocket connections')
                ->options([
                    'http' => 'HTTP (insecure)',
                    'https' => 'HTTPS (secure)',
                ])
                ->placeholder('Select scheme'),

            Input::make('settings.vite_reverb_app_cluster')
                ->title('Frontend App Cluster')
                ->help('config(\'vite.reverb_app_cluster\')<br/>Cluster designation for Reverb setup (optional)')
                ->placeholder('your_cluster'),
        ];
    }
}
