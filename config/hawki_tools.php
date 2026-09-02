<?php

return [

    /*
    |--------------------------------------------------------------------------
    |   HAWKI Tools
    |--------------------------------------------------------------------------
    |
    |   Tools that HAWKI can run on its own, for providers whose API does not
    |   bring a native implementation of them (e.g. the ki@JLU gateway, which
    |   speaks the OpenAI chat completions format but serves no server side
    |   tools of its own).
    |
    |   Each tool is offered as an override on the API provider edit screen:
    |   override off keeps the provider's native tool, override on routes the
    |   tool through HAWKI's own tool runtime.
    |
    |   The 'description' is sent to the model as the function description, so
    |   it decides how well the model picks the tool.
    |
    */
    'tools' => [
        'web_search' => [
            'label' => 'Web Search',
            'description' => 'Search the web for up-to-date information. Use this whenever the answer depends on current events, local knowledge or facts you cannot know.',
            'help' => 'Serve web search through HAWKI instead of the provider. Enable this for providers without a native web search tool.',

            /*
             * Added to the system prompt whenever this tool is attached.
             * Without it the models keep to their training and answer that they
             * have no access to the internet, instead of using the tool that is
             * sitting right there.
             */
            'awareness' => implode(' ', [
                'You have a `web_search` tool available in this conversation.',
                'Call it before answering whenever the answer depends on something that changes over time or that you cannot know: current events, news, weather, prices and fares, opening hours, schedules, deadlines, statistics, rankings, who currently holds a role, the state of an ongoing situation, or anything the user asks you to look up or to read from a specific URL.',
                'Do not answer such questions from memory even if you believe you know the value - your knowledge has a cutoff and these facts go stale, so verify first and then answer.',
                'For stable knowledge (established facts, definitions, maths, translation, writing, code, reasoning about text the user provided) answer directly without searching.',
                'Never tell the user that you cannot search the web, browse the internet or access current information: you can, by calling this tool.',
                'Answer in the language the user writes in.',
            ]),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    |   MCP Servers
    |--------------------------------------------------------------------------
    |
    |   The MCP servers the tools above are executed on. These defaults are the
    |   fallback for a fresh installation; the admin UI takes precedence once a
    |   server is configured there.
    |
    */
    'mcp_servers' => [
        'websearch-mcp' => [
            'url' => env('HAWKI_WEBSEARCH_MCP_URL', 'https://ki-dev2.hrz.uni-giessen.de/mcp'),
            'requires_session' => false,
            'timeout' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    |   Tool Bindings
    |--------------------------------------------------------------------------
    |
    |   Which MCP server and which of its tools a HAWKI tool maps to. The
    |   'tools' entries are the routing targets a HAWKI tool picks between; the
    |   web search tool decides by looking at the query it was given.
    |
    */
    'bindings' => [
        'web_search' => [
            'server' => 'websearch-mcp',
            'tools' => [
                'general' => 'google_search',
                'local' => 'search_uni_giessen',
                'url' => 'extract_webpage_content',
            ],
        ],
    ],
];
