<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Services\Mcp\Exception\McpException;
use App\Services\Mcp\McpClient;
use App\Services\Mcp\McpServerRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Web search served by HAWKI through an MCP server, for providers that bring no
 * native web search of their own.
 *
 * The model only ever sees one function. Which of the bound MCP tools actually
 * runs is decided here from the arguments: a list of URLs is read as a batch, a
 * single URL is read in full, a query marked 'deep' goes to the research
 * synthesis, a university related query to the local search, everything else to
 * the general web search.
 */
class WebSearchTool implements HawkiToolInterface
{
    public const KEY = 'web_search';

    /**
     * The most pages `extract_multiple_webpages` accepts in one call.
     */
    private const MAX_URLS = 5;

    /**
     * Query fragments that point at the university's own resources.
     *
     * The bare city name is deliberately not a hint. It matches questions about
     * the city rather than the university ("Wetter in Gießen"), which the local
     * search cannot answer, and in German it is also the verb "to water", so
     * "Pflanzen gießen" would end up searching the university site too. Both
     * cases send the model back for another search round and waste the round
     * budget, so a hit needs an actual university signal.
     */
    private const LOCAL_HINTS = [
        'jlu',
        'justus-liebig',
        'uni-giessen',
        'uni giessen',
        'uni gießen',
        'universität giessen',
        'universität gießen',
        'university of giessen',
    ];

    public function __construct(
        private readonly McpClient $client,
        private readonly McpServerRegistry $registry,
        private readonly WebSearchSources $sources,
    ) {}

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => self::KEY,
                'description' => (string) config(
                    'hawki_tools.tools.'.self::KEY.'.description',
                    'Search the web for up-to-date information.'
                ),
                'parameters' => $this->getArgumentSchema(),
            ],
        ];
    }

    /**
     * The argument schema is the second place the model is steered, next to the
     * awareness prompt: the parameter descriptions are what tell it that one
     * call can be a quick lookup, a full page read or a deep research synthesis.
     *
     * 'query' is not declared required, because a call that only passes 'urls'
     * is a legitimate way to use the tool. Sending neither is caught in
     * {@see self::execute()} with a message the model can act on.
     */
    public function getArgumentSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'The search keywords, the topic to research, or a single URL whose content should be read in full.',
                ],
                'urls' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Up to '.self::MAX_URLS.' URLs to read in one call, e.g. the most promising hits of a previous search. Returns a short preview of each page; pass a single URL as "query" instead to read one page in full.',
                ],
                'depth' => [
                    'type' => 'string',
                    'enum' => ['quick', 'deep'],
                    'description' => 'How thoroughly to search. "quick" (default) returns a list of search hits. "deep" researches the topic across 8 to 10 sources and returns a written synthesis of them; use it when the user asks for research, an overview, a comparison or a report.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ?string $serverBinding = null): string
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        $urls = $this->normaliseUrls($arguments['urls'] ?? null);
        $deep = mb_strtolower(trim((string) ($arguments['depth'] ?? ''))) === 'deep';

        if ($query === '' && $urls === []) {
            throw new McpException(
                'No search query was given. Pass "query" with keywords, a topic or a single URL, or "urls" with the pages to read.'
            );
        }

        $binding = $this->registry->binding(self::KEY, $serverBinding);
        $server = $binding['server'];

        if ($server === null) {
            throw new McpException('Web search is not bound to a reachable MCP server.');
        }

        $candidates = $this->routes($query, $urls, $deep);

        foreach ($candidates as [$route, $mcpArguments]) {
            $mcpTool = $binding['tools'][$route] ?? null;

            if ($mcpTool === null || $mcpTool === '') {
                continue;
            }

            Log::info('[WebSearchTool] Calling MCP tool', [
                'server' => $server->name,
                'route' => $route,
                'tool' => $mcpTool,
            ]);

            $result = $this->client->callTool($server, $mcpTool, $mcpArguments);

            // The pages this call used, kept for the citations auxiliary. The
            // model gets the result unchanged; the sources are for the user.
            $this->sources->collect($result);

            return $result;
        }

        throw new McpException(
            'The web search binding has no tool for the "'.$candidates[0][0].'" route.'
        );
    }

    /**
     * The bound tools that could serve this call, best first.
     *
     * A route beyond the first is a fallback for a binding that does not carry
     * the preferred tool: the deep research and batch read routes were added
     * after the first release, so an installation whose binding an admin saved
     * before that still has to answer rather than fail.
     *
     * @param  array<int, string>  $urls
     * @return array<int, array{0: string, 1: array}>
     */
    private function routes(string $query, array $urls, bool $deep): array
    {
        if ($urls !== []) {
            return [
                ['urls', ['urls' => $urls, 'format' => 'markdown']],
                ['url', $this->singlePageArguments($urls[0])],
            ];
        }

        $url = $this->asSingleUrl($query);
        if ($url !== null) {
            return [['url', $this->singlePageArguments($url)]];
        }

        // Deep research wins over the local search even for a university topic:
        // the synthesis picks up the university's own pages on its own, and a
        // user who asks for research wants more than a list of site hits.
        if ($deep) {
            return [
                ['research', ['topic' => $query, 'depth' => 'advanced']],
                ['general', ['query' => $query]],
            ];
        }

        $lower = mb_strtolower($query);
        foreach (self::LOCAL_HINTS as $hint) {
            if (str_contains($lower, $hint)) {
                return [
                    ['local', ['query' => $query]],
                    ['general', ['query' => $query]],
                ];
            }
        }

        return [['general', ['query' => $query]]];
    }

    /**
     * Reading a page the model explicitly asked for returns the whole text, not
     * the preview the MCP server sends by default - a preview is what the batch
     * read is for, and a model that names one URL wants what is on it.
     */
    private function singlePageArguments(string $url): array
    {
        return [
            'url' => $url,
            'full_content' => true,
            'max_length' => (int) config('hawki_tools.tools.'.self::KEY.'.max_page_chars', 20000),
        ];
    }

    /**
     * The URL a query consists of, or null if the query is a search phrase.
     */
    private function asSingleUrl(string $query): ?string
    {
        if (! preg_match('/^(?:https?:\/\/|www\.)[^\s]+$/i', $query)) {
            return null;
        }

        return str_starts_with(mb_strtolower($query), 'www.') ? 'https://'.$query : $query;
    }

    /**
     * The URLs of a batch read: absolute, capped at what the server accepts, and
     * without the entries a model filled with something that is not a URL.
     *
     * @return array<int, string>
     */
    private function normaliseUrls(mixed $urls): array
    {
        if (! is_array($urls)) {
            return [];
        }

        $clean = [];
        foreach ($urls as $url) {
            if (! is_string($url)) {
                continue;
            }

            $url = $this->asSingleUrl(trim($url));
            if ($url === null || in_array($url, $clean, true)) {
                continue;
            }

            $clean[] = $url;

            if (count($clean) === self::MAX_URLS) {
                break;
            }
        }

        return $clean;
    }
}
