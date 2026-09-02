<?php

declare(strict_types=1);

namespace App\Services\Mcp\Value;

/**
 * An object representation of a single MCP server HAWKI can call tools on.
 */
readonly class McpServerConfig
{
    public function __construct(
        /**
         * The unique name of the server, e.g. 'websearch-mcp'.
         */
        public string $name,
        /**
         * The endpoint that accepts JSON-RPC POST requests.
         */
        public string $url,
        /**
         * Whether the server expects an 'initialize' handshake and an mcp-session-id
         * header on every following request. Servers differ here: the API gateway
         * answers tool calls straight away, a server reached directly may not.
         */
        public bool $requiresSession = false,
        /**
         * Bearer token for servers behind authentication. The MCP endpoints of the
         * ki@JLU gateway are key protected, and the key is resolved from the API
         * provider by {@see \App\Services\Mcp\McpServerRegistry} rather than being
         * stored here in plain text.
         */
        public ?string $apiKey = null,
        /**
         * The header the key is sent in, always as 'Bearer <key>'. LiteLLM
         * documents its MCP endpoint with 'x-litellm-api-key' and also accepts
         * 'Authorization', which is the default for any other server.
         */
        public string $apiKeyHeader = 'Authorization',
        /**
         * The upstream server to use when the endpoint is a gateway serving
         * several of them, e.g. 'google_search_http'. Sent as 'x-mcp-servers',
         * so a tool only ever sees the tools of the server it is bound to.
         */
        public string $gatewayServer = '',
        /**
         * Request timeout in seconds. Tool calls run inside a user request, so this
         * has to stay well below the request time limit.
         */
        public int $timeout = 60,
        /**
         * The prefix a gateway puts in front of the tool names of one upstream
         * server, e.g. 'google_search_http-' for 'google_search_http-google_search'.
         * Bindings stay written in the server's own tool names; the prefix is added
         * on a call and stripped from a listing.
         */
        public string $toolPrefix = '',
    ) {}

    public static function fromArray(string $name, array $config): self
    {
        $gatewayServer = trim((string) ($config['gateway_server'] ?? ''));

        // A gateway reports its upstream tools as '<alias>-<tool>', so the
        // prefix follows from the alias unless a gateway names them otherwise.
        $prefix = trim((string) ($config['tool_prefix'] ?? ''));
        if ($prefix === '' && $gatewayServer !== '') {
            $prefix = $gatewayServer.'-';
        }

        return new self(
            name: $name,
            url: (string) ($config['url'] ?? ''),
            requiresSession: (bool) ($config['requires_session'] ?? false),
            apiKey: ($config['api_key'] ?? null) ?: null,
            timeout: (int) ($config['timeout'] ?? 60),
            apiKeyHeader: trim((string) ($config['api_key_header'] ?? '')) ?: 'Authorization',
            gatewayServer: $gatewayServer,
            toolPrefix: $prefix,
        );
    }

    /**
     * Whether a tool name belongs to this server. Only meaningful behind a
     * gateway: without a prefix every tool the endpoint offers is this
     * server's own.
     */
    public function ownsTool(string $tool): bool
    {
        return $this->toolPrefix === '' || str_starts_with($tool, $this->toolPrefix);
    }

    public function isUsable(): bool
    {
        return $this->url !== '';
    }

    /**
     * The name this server's tool is addressed by on its endpoint.
     */
    public function qualifyTool(string $tool): string
    {
        if ($this->toolPrefix === '' || str_starts_with($tool, $this->toolPrefix)) {
            return $tool;
        }

        return $this->toolPrefix.$tool;
    }

    /**
     * The server's own name for a tool the endpoint reported.
     */
    public function stripPrefix(string $tool): string
    {
        if ($this->toolPrefix !== '' && str_starts_with($tool, $this->toolPrefix)) {
            return substr($tool, strlen($this->toolPrefix));
        }

        return $tool;
    }
}
