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
         * header on every following request. Servers differ here: the web search
         * server answers tool calls straight away, the code execution server does not.
         */
        public bool $requiresSession = false,
        /**
         * Optional bearer token for servers that are not open to the app network.
         */
        public ?string $apiKey = null,
        /**
         * Request timeout in seconds. Tool calls run inside a user request, so this
         * has to stay well below the request time limit.
         */
        public int $timeout = 60,
    ) {}

    public static function fromArray(string $name, array $config): self
    {
        return new self(
            name: $name,
            url: (string) ($config['url'] ?? ''),
            requiresSession: (bool) ($config['requires_session'] ?? false),
            apiKey: ($config['api_key'] ?? null) ?: null,
            timeout: (int) ($config['timeout'] ?? 60),
        );
    }

    public function isUsable(): bool
    {
        return $this->url !== '';
    }
}
