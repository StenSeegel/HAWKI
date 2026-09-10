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
    /*
    |--------------------------------------------------------------------------
    |   Tool Prompt Placement
    |--------------------------------------------------------------------------
    |
    |   Where the tool prompt is added to a request: 'user' puts it in front of
    |   the newest user message, 'system' into the system prompt.
    |
    |   Measured on the ki@JLU models with questions that should trigger a
    |   search: gemma-4-26b-it got 4/5 with 'system' and 5/5 with 'user';
    |   qwen3-coder-next and qwen3.8-27b got 5/5 either way. No placement made a
    |   model search when it should not. Gemma has no native system role, so its
    |   template folds a system message into the conversation and the instruction
    |   carries less weight there - gemma is the model this setting matters for.
    |
    */
    'awareness_placement' => env('HAWKI_TOOL_PROMPT_PLACEMENT', 'user'),

    /*
    |--------------------------------------------------------------------------
    |   Activation
    |--------------------------------------------------------------------------
    |
    |   Each tool below says how it is switched on for a request:
    |
    |   'toggle' - only when the user turned it on in the chat UI. For tools the
    |              user expects to control, and whose result they pay for in
    |              latency: web search and image generation have a button.
    |   'always' - offered on every request of a model that carries the tool.
    |              For tools with no button, where the model alone decides
    |              whether the request needs them.
    |
    |   Either way the model flag (Language Models) and the provider override
    |   (API Management) still have to be on, and the model still decides
    |   whether to call the tool at all.
    |
    */


    'tools' => [
        'web_search' => [
            'label' => 'Web Search',
            // The chat UI has a web search button.
            'activation' => 'toggle',
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
                '',
                'DEEP RESEARCH',
                'The same tool serves a quick lookup and a deep investigation; the arguments decide which of the two you get.',
                '- `depth: "deep"` returns a synthesis written from 8 to 10 sources instead of a list of hits. Use it whenever the user asks you to research something, to compare options, or for an overview, a report, the state of the art, or advantages and disadvantages - anything that no single page answers.',
                '- `urls: ["...", "..."]` reads up to 5 pages in one call and returns a short preview of each. Use it on the most promising hits of a search to find out which pages actually carry the answer.',
                '- `query: "<one URL>"` reads that single page in full. Use it on the page a preview showed to be the right one.',
                '',
                'A researched answer takes more than one call: search or research first, then read the pages that matter, then answer. Do not stop at the first list of hits when the user asked you to research something.',
                '',
                'CITE THE PAGES YOU USED. Every statement taken from a page carries a markdown link to that page: [short label](the URL exactly as the tool result printed it). Copy the URL character for character - do not shorten it, do not tidy it up, do not invent one, and never link a page you did not read. HAWKI turns those links into numbered sources under your answer, so a borrowed statement without a link reaches the user without its source.',
                'Put the link at the end of the sentence or bullet it backs, not on a phrase inside it: HAWKI replaces the whole link with a small numbered marker, and a marker mid sentence reads as an interruption. Several pages backing one statement get one link each, side by side.',
                'Do not write a sources, references or "Quellen" list yourself, and never put a bracketed number such as [1] into your text. HAWKI builds both the numbered markers and the source list from your links alone - a number or a list you write by hand arrives beside HAWKI\'s own as a visible duplicate.',
            ]),

            /*
             * Cap on the characters a single page may contribute when the model
             * asks for a URL to be read in full. Without it a long page arrives
             * whole and crowds the conversation out of the context window.
             */
            'max_page_chars' => (int) env('HAWKI_WEBSEARCH_MAX_PAGE_CHARS', 20000),
        ],

        'code_interpreter' => [
            'label' => 'Code Interpreter',
            /*
             * No button in the chat UI, and none wanted: a user should not have
             * to know that an exact answer needs code. The tool is offered on
             * every request of a model that carries it, and the model calls it
             * when the answer has to be computed rather than recalled.
             */
            'activation' => 'always',
            'description' => 'Execute Python code and return its output. Use it to compute, transform data or verify a result instead of calculating in your head.',
            'help' => 'Serve code execution through HAWKI instead of the provider. Enable this for providers without a native code interpreter.',
            'awareness' => implode("\n", [
                'CODE INTERPRETER TOOL',
                'You have a `code_interpreter` tool in this conversation. It runs Python code and returns whatever the code prints.',
                'Never tell the user that you cannot run or execute code: you can, by calling this tool.',
                '',
                'You can also produce images and plots with it. Never tell the user that this environment cannot show images, or that you were unable to run the code with image output: it can, and you were.',
                '',
                'Call the tool when:',
                '- a result has to be exact: arithmetic beyond simple mental maths, statistics, unit conversion, dates and durations;',
                '- the user asks you to compute, simulate, sort, count or transform data;',
                '- the user asks for a plot, a chart or a diagram;',
                '- the user gives you a script and asks you to run it, execute it, or show its output - run it, do not read it and describe what it would do;',
                '- the user gives you code and asks what it outputs, or asks you to verify that your own code works.',
                '',
                'NEVER state a number, a statistic or a result as if the code had produced it unless you actually called the tool and read it back. If you did not run the code, say so plainly instead of presenting values you worked out or guessed.',
                '',
                'Print what you want to read back - only stdout comes back to you. Keep the code self contained; there is no network and no access to the user\'s files.',
                'Write any temporary file to /tmp, which is writable; the working directory is not.',
                '',
                'PLOTS AND IMAGES work like this: keep the figure in memory and print it as a data URI. Save it to an io.BytesIO buffer, then print "data:image/png;base64," followed by the base64 of that buffer, and HAWKI turns it into a picture in the chat. Use that instead of plt.show() or savefig() to a filename, which return the figure to nobody. Print one data URI per figure; several are fine in one run.',
                '',
                'Answer directly WITHOUT running code for explanations, for code the user only wants read, reviewed or explained, and for arithmetic you are certain of. A request to RUN something is never such a case.',
                '',
                'HAWKI already shows the user the code you ran, what it printed and any image it produced. Do not repeat the code, and never write base64 or a data URI into your answer - the picture is already there. Give the result and say what it means. Answer in the language the user writes in.',
            ]),
        ],

        'image_generation' => [
            'label' => 'Image Generation',
            // The chat UI has an image generation button.
            'activation' => 'toggle',

            /*
             * The model capability this tool needs, when the model settings call
             * it something else than the tool key. Image generation is the one
             * case: the capability has been 'image_gen' in the model settings and
             * the model library since long before HAWKI tools existed, while the
             * tool - and the flag the frontend sends - is 'image_generation'.
             * Without this the tool would never be offered to any model.
             */
            'model_flag' => 'image_gen',
            'description' => 'Generate an image from a textual description, or change the image attached to the message.',
            'help' => 'Serve image generation through HAWKI instead of the provider. Enable this for providers without a native image generation tool.',
            'awareness' => implode("\n", [
                'IMAGE GENERATION TOOL',
                'You have an `image_generation` tool in this conversation. It creates an image from a description, and changes an image the message has attached.',
                'Never tell the user that you cannot create images: you can, by calling this tool.',
                '',
                'Call the tool when the user asks for an image, a picture, an illustration, a logo or a diagram to be drawn.',
                'Write the description yourself: turn a short request into a precise prompt naming subject, style, composition and lighting.',
                '',
                'THE SAME TOOL EDITS AN IMAGE. When the message has an image attached and the user asks for a change to it - remove the background, change a colour, another aspect ratio, add or take something out - call the tool with the change as the prompt: "Change the red sails to blue; keep the boat and the background unchanged." Name what should stay the same, not only what should differ.',
                'HAWKI passes the attached image to the editor for you. You do not need its data and must never invent image content: describe the change and call the tool.',
                'This works even when you cannot see the picture yourself. If the conversation carries a note that an attachment was not included because this model does not support images, that note is about YOUR eyes, not about the tool: the image is there and the editor gets it. Never answer that the image did not reach you or ask the user to attach it again - call the tool with the change as the prompt.',
                'Editing is what happens by default whenever an image is attached. Only if the user wants a completely new, unrelated picture although one is attached, call the tool with `edit: false`.',
                '',
                'The image is shown to the user as soon as it is finished, and you never see it. Do not claim to have looked at it, and do not describe its content in detail: say what you generated or changed in one sentence.',
                '',
                'Answer directly WITHOUT generating when the user only wants to talk about an image, or asks for text, code or an explanation.',
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
    |   The servers are called through the ki@JLU API gateway (LiteLLM), which
    |   protects its MCP endpoint with the same key as its chat completions
    |   endpoint - so a server names the API provider its key comes from instead
    |   of carrying a copy of that key.
    |
    */
    'mcp_servers' => [
        'websearch-mcp' => [
            /*
             * The gateway's own MCP endpoint - the API base URL plus '/mcp' -
             * rather than the upstream server directly: the gateway aggregates
             * the MCP servers behind one key protected endpoint, so a server can
             * be reachable for HAWKI without being open to the app network.
             */
            'url' => env('HAWKI_MCP_URL', 'https://api.hrz.uni-giessen.de/mcp'),

            /*
             * The API provider whose key opens this endpoint. The key is read -
             * and decrypted - from that provider, so it is never duplicated here
             * and rotating the provider key rotates the tool access with it.
             */
            'api_key_provider' => env('HAWKI_MCP_KEY_PROVIDER', 'ki-at-jlu'),

            // LiteLLM documents its MCP endpoint with this header; it accepts
            // 'Authorization' too, which is the default for any other server.
            'api_key_header' => env('HAWKI_MCP_KEY_HEADER', 'x-litellm-api-key'),

            /*
             * Which of the gateway's servers this HAWKI tool uses. It scopes the
             * endpoint to that server ('x-mcp-servers') and gives the prefix its
             * tools are addressed by ('google_search_http-google_search'), so the
             * bindings below stay written in the plain tool names.
             */
            'gateway_server' => env('HAWKI_WEBSEARCH_MCP_SERVER', 'google_search_http'),

            'requires_session' => false,
            'timeout' => 60,
        ],
        'code-exec-mcp' => [
            'url' => env('HAWKI_MCP_URL', 'https://api.hrz.uni-giessen.de/mcp'),
            'api_key_provider' => env('HAWKI_MCP_KEY_PROVIDER', 'ki-at-jlu'),
            'api_key_header' => env('HAWKI_MCP_KEY_HEADER', 'x-litellm-api-key'),
            'gateway_server' => env('HAWKI_CODE_EXEC_MCP_SERVER', 'mcp_gVisor'),

            // The gateway answers tool calls straight away; a directly reached
            // execution server hands out an mcp-session-id and expects it back.
            'requires_session' => false,
            'timeout' => 120,
        ],
        'image-mcp' => [
            'url' => env('HAWKI_MCP_URL', 'https://api.hrz.uni-giessen.de/mcp'),
            'api_key_provider' => env('HAWKI_MCP_KEY_PROVIDER', 'ki-at-jlu'),
            'api_key_header' => env('HAWKI_MCP_KEY_HEADER', 'x-litellm-api-key'),
            'gateway_server' => env('HAWKI_IMAGE_MCP_SERVER', 'image_mcp'),

            'requires_session' => false,

            /*
             * Diffusion on one GPU, so this is the slowest tool by an order of
             * magnitude: the server itself asks for a client timeout of at least
             * 310 seconds and holds the connection until the image is finished -
             * there is no job id to poll and no cache to fetch it from later.
             * A 1024x1024 Z-Image Turbo image took 19s on an idle Spark; the
             * headroom is for a queued one.
             */
            'timeout' => (int) env('HAWKI_IMAGE_MCP_TIMEOUT', 320),
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
                'urls' => 'extract_multiple_webpages',
                'research' => 'research_topic',
            ],
        ],
        'code_interpreter' => [
            'server' => 'code-exec-mcp',
            'tools' => [
                'run' => 'code_exec',
            ],
        ],
        'image_generation' => [
            'server' => 'image-mcp',
            'tools' => [
                'generate' => 'generate_image',

                /*
                 * Taken whenever the message has an image attached. The model
                 * never has the bytes of that image - a picture leaves the tool as
                 * a stored attachment and comes back to it as a one line note - so
                 * the tool reads them out of the conversation itself and sends them
                 * with the instruction.
                 */
                'edit' => 'edit_image',
            ],
        ],
    ],
];
