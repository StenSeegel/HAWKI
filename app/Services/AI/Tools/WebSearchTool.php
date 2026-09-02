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
 * runs is decided here from the query: a URL is extracted, a university related
 * query goes to the local search, everything else to the general web search.
 */
class WebSearchTool implements HawkiToolInterface
{
    public const KEY = 'web_search';

    /**
     * Query fragments that point at the university's own resources.
     */
    private const LOCAL_HINTS = ['jlu', 'gießen', 'giessen', 'justus-liebig'];

    public function __construct(
        private readonly McpClient $client,
        private readonly McpServerRegistry $registry,
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

    public function getArgumentSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'The search keywords, or a single URL whose content should be read.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $arguments, ?string $serverBinding = null): string
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            throw new McpException('No search query was given.');
        }

        $binding = $this->registry->binding(self::KEY, $serverBinding);
        $server = $binding['server'];

        if ($server === null) {
            throw new McpException('Web search is not bound to a reachable MCP server.');
        }

        [$route, $mcpArguments] = $this->route($query);
        $mcpTool = $binding['tools'][$route] ?? null;

        if ($mcpTool === null) {
            throw new McpException('The web search binding has no tool for the "'.$route.'" route.');
        }

        Log::info('[WebSearchTool] Calling MCP tool', [
            'server' => $server->name,
            'route' => $route,
            'tool' => $mcpTool,
        ]);

        return $this->client->callTool($server, $mcpTool, $mcpArguments);
    }

    /**
     * Decide which bound MCP tool serves this query.
     *
     * @return array{0: string, 1: array}
     */
    private function route(string $query): array
    {
        if (preg_match('/^(?:https?:\/\/|www\.)[^\s]+$/i', $query)) {
            $url = str_starts_with(strtolower($query), 'www.') ? 'https://'.$query : $query;

            return ['url', ['url' => $url]];
        }

        $lower = mb_strtolower($query);
        foreach (self::LOCAL_HINTS as $hint) {
            if (str_contains($lower, $hint)) {
                return ['local', ['query' => $query]];
            }
        }

        return ['general', ['query' => $query]];
    }
}
