<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiFormat;
use App\Models\ApiProvider;
use App\Services\Mcp\McpClient;
use App\Services\Mcp\McpServerRegistry;
use App\Services\Mcp\Value\McpServerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MCP servers are reached through the API gateway, whose MCP endpoint is
 * protected by the same key as its chat completions endpoint.
 */
class McpGatewayAuthTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://gateway.test/mcp';

    private function provider(string $key = 'sk-gateway-key'): void
    {
        $format = ApiFormat::first() ?? ApiFormat::create([
            'unique_name' => 'openai-api',
            'display_name' => 'OpenAI',
            'client_adapter' => 'openai',
        ]);

        ApiProvider::create([
            'unique_name' => 'ki-at-jlu',
            'provider_name' => 'ki@JLU',
            'api_format_id' => $format->id,
            'api_key' => $key,
            'base_url' => 'https://gateway.test',
            'is_active' => true,
        ]);
    }

    private function configureServer(array $overrides = []): void
    {
        config(['hawki_tools.mcp_servers' => [
            'websearch-mcp' => array_merge([
                'url' => self::URL,
                'api_key_provider' => 'ki-at-jlu',
                'api_key_header' => 'x-litellm-api-key',
                'gateway_server' => 'google_search_http',
                'requires_session' => false,
                'timeout' => 30,
            ], $overrides),
        ]]);
    }

    public function test_the_key_is_taken_from_the_named_api_provider(): void
    {
        $this->provider();
        $this->configureServer();

        $server = app(McpServerRegistry::class)->get('websearch-mcp');

        // Stored encrypted on the provider, so this also proves it is decrypted.
        $this->assertSame('sk-gateway-key', $server->apiKey);
    }

    public function test_a_call_is_authenticated_in_the_configured_header(): void
    {
        $this->provider();
        $this->configureServer();

        Http::fake([self::URL => Http::response(
            'data: {"result":{"content":[{"type":"text","text":"results"}]}}'."\n", 200
        )]);

        $server = app(McpServerRegistry::class)->get('websearch-mcp');
        app(McpClient::class)->callTool($server, 'google_search', ['query' => 'php']);

        $request = Http::recorded()[0][0];

        // The gateway rejects a bare key: it wants the Bearer prefix inside its
        // own header.
        $this->assertSame('Bearer sk-gateway-key', $request->header('x-litellm-api-key')[0]);
        $this->assertSame([], $request->header('Authorization'));

        // And the endpoint is scoped to the one upstream server this tool uses.
        $this->assertSame('google_search_http', $request->header('x-mcp-servers')[0]);
    }

    public function test_authorization_is_the_default_header(): void
    {
        $this->provider();
        $this->configureServer(['api_key_header' => '']);

        Http::fake([self::URL => Http::response(
            'data: {"result":{"content":[{"type":"text","text":"results"}]}}'."\n", 200
        )]);

        $server = app(McpServerRegistry::class)->get('websearch-mcp');
        app(McpClient::class)->callTool($server, 'google_search', ['query' => 'php']);

        $this->assertSame('Bearer sk-gateway-key', Http::recorded()[0][0]->header('Authorization')[0]);
    }

    public function test_the_tool_prefix_follows_from_the_gateway_server(): void
    {
        $this->configureServer();

        // No prefix configured anywhere: the alias implies it.
        $this->assertSame(
            'google_search_http-',
            app(McpServerRegistry::class)->get('websearch-mcp')->toolPrefix
        );
    }

    public function test_a_listing_ignores_the_tools_of_other_gateway_servers(): void
    {
        $this->configureServer();

        Http::fake([self::URL => Http::response(
            'data: {"result":{"tools":[{"name":"google_search_http-google_search","description":"d"},'
            .'{"name":"kanban-list_boards","description":"d"},'
            .'{"name":"mcp_gVisor-code_exec","description":"d"}]}}'."\n", 200
        )]);

        $tools = app(McpClient::class)->listTools(app(McpServerRegistry::class)->get('websearch-mcp'));

        // Otherwise the admin screen would offer tools this server does not serve.
        $this->assertSame(['google_search'], array_column($tools, 'name'));
    }

    public function test_a_call_uses_the_gateway_prefixed_tool_name(): void
    {
        Http::fake([self::URL => Http::response(
            'data: {"result":{"content":[{"type":"text","text":"results"}]}}'."\n", 200
        )]);

        $server = McpServerConfig::fromArray('websearch-mcp', [
            'url' => self::URL,
            'tool_prefix' => 'google_search_http-',
        ]);

        app(McpClient::class)->callTool($server, 'google_search', ['query' => 'php']);

        // The binding is written in the server's own tool name; the gateway
        // needs it prefixed with the upstream server's alias.
        $this->assertSame(
            'google_search_http-google_search',
            Http::recorded()[0][0]->data()['params']['name']
        );
    }

    public function test_an_already_prefixed_tool_name_is_not_prefixed_twice(): void
    {
        $server = McpServerConfig::fromArray('websearch-mcp', [
            'url' => self::URL,
            'tool_prefix' => 'google_search_http-',
        ]);

        $this->assertSame(
            'google_search_http-google_search',
            $server->qualifyTool('google_search_http-google_search')
        );
    }

    public function test_a_listing_reports_the_servers_own_tool_names(): void
    {
        Http::fake([self::URL => Http::response(
            'data: {"result":{"tools":[{"name":"google_search_http-google_search","description":"d"},'
            .'{"name":"google_search_http-search_uni_giessen","description":"d"}]}}'."\n", 200
        )]);

        $server = McpServerConfig::fromArray('websearch-mcp', [
            'url' => self::URL,
            'tool_prefix' => 'google_search_http-',
        ]);

        $tools = app(McpClient::class)->listTools($server);

        // Otherwise the admin screen would show names that no binding matches.
        $this->assertSame(['google_search', 'search_uni_giessen'], array_column($tools, 'name'));
    }

    public function test_an_explicit_token_wins_over_the_provider(): void
    {
        $this->provider();
        $this->configureServer(['api_key' => 'sk-explicit']);

        $this->assertSame('sk-explicit', app(McpServerRegistry::class)->get('websearch-mcp')->apiKey);
    }

    public function test_a_server_without_a_key_provider_is_called_unauthenticated(): void
    {
        $this->configureServer(['api_key_provider' => '']);

        $this->assertNull(app(McpServerRegistry::class)->get('websearch-mcp')->apiKey);
    }

    public function test_an_unknown_provider_does_not_break_the_request(): void
    {
        $this->configureServer(['api_key_provider' => 'does-not-exist']);

        $server = app(McpServerRegistry::class)->get('websearch-mcp');

        // The call then fails with the server's own 401 instead of an exception
        // in the middle of a chat request.
        $this->assertNotNull($server);
        $this->assertNull($server->apiKey);
    }

    public function test_the_shipped_servers_are_authenticated_through_a_provider(): void
    {
        // A regression guard: calling an MCP server directly bypasses the
        // gateway's access control, so no shipped default may do that.
        foreach (config('hawki_tools.mcp_servers') as $name => $server) {
            $this->assertNotEmpty(
                $server['api_key_provider'] ?? '',
                $name.' must name the API provider its key comes from'
            );
            $this->assertNotEmpty(
                $server['gateway_server'] ?? '',
                $name.' must name the gateway server it is scoped to'
            );
        }
    }
}
