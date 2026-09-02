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
             *
             * This prompt is the ONLY place where the decision to search is
             * steered. There is deliberately no code side heuristic inspecting
             * the user's message: everything the model needs in order to judge
             * when a search is due - including which wordings signal that an
             * answer would go stale - belongs in this text, so the behaviour can
             * be tuned here without touching the tool runtime.
             */
            'awareness' => implode("\n", [
                'WEB SEARCH TOOL',
                'You have a `web_search` tool in this conversation. It searches the live web and reads URLs.',
                'Never tell the user that you cannot search the web, browse the internet or access current information: you can, by calling this tool.',
                '',
                'Call the tool BEFORE answering when:',
                '- the question is about anything that can change over time: news and current events, weather, prices, fares and fees, opening hours, timetables, deadlines, availability, statistics, rankings, standings, versions, who currently holds a role, or the state of an ongoing situation;',
                '- the question contains a word that ties it to now, such as "aktuell", "derzeit", "momentan", "heute", "gerade", "neueste", "current", "currently", "today", "now", "latest", "this year";',
                '- the user asks how expensive something is, when something takes place, whether something is still valid, or what the situation is;',
                '- the user asks who currently holds a position or office ("Wer ist Präsident/Rektor/Vorsitzende/Leiter von ...?"), since people leave roles without your knowledge being updated. Historical figures and people known for past work are stable knowledge and need no search;',
                '- the user asks you to look something up, to check something, or to read a specific URL;',
                '- you would otherwise have to add a caveat that your knowledge may be out of date.',
                '',
                'Your training data has a cutoff and the facts above go stale without you noticing. Being confident that you remember a value is NOT a reason to skip the search - remembered prices, fares and dates are exactly the ones that have since changed. Search first, then answer with what you found.',
                '',
                'Answer directly WITHOUT searching when the question is stable knowledge: established facts, definitions, history, mathematics, translation, summarising or rewriting text the user provided, writing, code, and reasoning tasks.',
                '',
                'Search with precise keywords. If the results do not answer the question, refine the query and search again rather than guessing. Answer in the language the user writes in.',
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
