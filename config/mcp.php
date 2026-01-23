<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MCP Client Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration for the MCP client integration
    | using the Neuron AI framework.
    |
    */

    'enabled' => env('MCP_CLIENT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | MCP Servers
    |--------------------------------------------------------------------------
    |
    | Define the MCP servers that HAWKI should connect to.
    | Each server requires a command and optional arguments.
    |
    */
    'servers' => [
        'tavily' => [
            'url' => 'https://mcp.tavily.com/mcp/?tavilyApiKey=',
        ],
        'jlu-mcp' => [
            'url' => 'https://api.hrz.uni-giessen.de/mcp',
            'headers' => [
                'x-litellm-api-key' => '',
            ],
        ],
        //'boost' => [
        //    'command' => 'php',
        //    'args' => [base_path('artisan'), 'boost:mcp'],
        //],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider for MCP
    |--------------------------------------------------------------------------
    |
    | Define which provider and model to use for the MCP Agent.
    |
    */
    'provider' => env('MCP_DEFAULT_PROVIDER', 'ki-at-jlu'),
    'model' => env('MCP_DEFAULT_MODEL', 'jlu/gpt-oss-20b'),
];
