<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Services\Mcp\Value\McpServerConfig;

/**
 * Resolves the configured MCP servers and the tool bindings pointing at them.
 *
 * The source of truth is config/hawki_tools.php for now; the admin managed
 * servers of the HAWKI tools extension screen plug in here later without any
 * caller having to change.
 */
class McpServerRegistry
{
    public function get(string $name): ?McpServerConfig
    {
        $servers = config('hawki_tools.mcp_servers', []);

        if (! isset($servers[$name]) || ! is_array($servers[$name])) {
            return null;
        }

        $server = McpServerConfig::fromArray($name, $servers[$name]);

        return $server->isUsable() ? $server : null;
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys(config('hawki_tools.mcp_servers', []));
    }

    /**
     * The binding of a HAWKI tool: which server runs it and which of the server's
     * tools serve as its routing targets.
     *
     * @return array{server: ?McpServerConfig, tools: array<string, string>}
     */
    public function binding(string $toolKey, ?string $preferredServer = null): array
    {
        $binding = config('hawki_tools.bindings.'.$toolKey, []);
        if (! is_array($binding)) {
            $binding = [];
        }

        // A provider may pin a tool to a specific server; fall back to the
        // server named in the binding otherwise.
        $serverName = $preferredServer ?? ($binding['server'] ?? null);

        return [
            'server' => is_string($serverName) ? $this->get($serverName) : null,
            'tools' => is_array($binding['tools'] ?? null) ? $binding['tools'] : [],
        ];
    }
}
