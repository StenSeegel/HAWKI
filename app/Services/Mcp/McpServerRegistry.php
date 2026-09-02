<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Models\ApiProvider;
use App\Services\Mcp\Value\McpServerConfig;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the configured MCP servers and the tool bindings pointing at them.
 *
 * The source of truth is config/hawki_tools.php, overridden by the values an
 * admin edits on the Tools screen.
 */
class McpServerRegistry
{
    /**
     * Keys resolved from API providers, cached for the lifetime of the request:
     * every lookup is a query plus a decrypt.
     *
     * @var array<string, ?string>
     */
    private array $providerKeys = [];

    public function get(string $name): ?McpServerConfig
    {
        $servers = config('hawki_tools.mcp_servers', []);

        if (! isset($servers[$name]) || ! is_array($servers[$name])) {
            return null;
        }

        $config = $servers[$name];
        $config['api_key'] = $this->resolveApiKey($name, $config);

        $server = McpServerConfig::fromArray($name, $config);

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

    /**
     * The bearer token a server is called with.
     *
     * MCP servers are reached through the API gateway, which protects its MCP
     * endpoint with the same key as its chat completions endpoint. Rather than
     * copying that key into the tool configuration, a server names the API
     * provider it belongs to and the key is read - and decrypted - from there,
     * so rotating the provider key rotates the tool access with it.
     */
    private function resolveApiKey(string $name, array $config): ?string
    {
        // An explicit token still wins, for a server that is not behind a gateway.
        if (! empty($config['api_key'])) {
            return (string) $config['api_key'];
        }

        $providerName = trim((string) ($config['api_key_provider'] ?? ''));
        if ($providerName === '') {
            return null;
        }

        if (array_key_exists($providerName, $this->providerKeys)) {
            return $this->providerKeys[$providerName];
        }

        $key = null;

        try {
            $provider = ApiProvider::where('unique_name', $providerName)->first();

            if ($provider === null) {
                Log::warning('[McpServerRegistry] MCP server points at an unknown API provider', [
                    'server' => $name,
                    'provider' => $providerName,
                ]);
            } else {
                $key = $provider->api_key ? (string) $provider->api_key : null;

                if ($key === null) {
                    Log::warning('[McpServerRegistry] API provider of an MCP server has no API key', [
                        'server' => $name,
                        'provider' => $providerName,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // A broken key or an unavailable database must not take the whole
            // request down: the call then fails with the server's own 401.
            Log::error('[McpServerRegistry] Could not read the API key of an MCP server', [
                'server' => $name,
                'provider' => $providerName,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->providerKeys[$providerName] = $key;
    }
}
