<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vite Frontend Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration defines the environment variables that are passed
    | to the frontend build process via Vite. These values should typically
    | mirror the backend WebSocket configuration but are prefixed with VITE_
    | for frontend access via import.meta.env.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Reverb WebSocket Configuration for Frontend
    |--------------------------------------------------------------------------
    |
    | These settings configure how the frontend JavaScript connects to the
    | Reverb WebSocket server. They should match the backend Reverb configuration
    | in config/reverb.php and config/broadcasting.php.
    |
    */

    'reverb_app_key' => env('VITE_REVERB_APP_KEY', env('REVERB_APP_KEY', 'laravel-herd')),
    'reverb_host' => env('VITE_REVERB_HOST', env('REVERB_HOST', 'hawki.test')),
    'reverb_port' => env('VITE_REVERB_PORT', env('REVERB_PORT', '8080')),
    'reverb_scheme' => env('VITE_REVERB_SCHEME', env('REVERB_SCHEME', 'https')),
    'reverb_app_cluster' => env('VITE_REVERB_APP_CLUSTER', 'your_cluster'),

];
