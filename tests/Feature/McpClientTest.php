<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Mcp\Exception\McpException;
use App\Services\Mcp\McpClient;
use App\Services\Mcp\Value\McpServerConfig;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class McpClientTest extends TestCase
{
    private const URL = 'https://mcp.test/mcp';

    private function server(bool $requiresSession = false): McpServerConfig
    {
        return new McpServerConfig(
            name: 'test-mcp',
            url: self::URL,
            requiresSession: $requiresSession,
            timeout: 5,
        );
    }

    private function sse(string $json): string
    {
        return "event: message\ndata: ".$json."\n\n";
    }

    public function test_reads_a_tool_result_from_an_event_stream(): void
    {
        Http::fake([
            self::URL => Http::response(
                $this->sse('{"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text","text":"Search results here."}]}}'),
                200,
                ['Content-Type' => 'text/event-stream']
            ),
        ]);

        $result = app(McpClient::class)->callTool($this->server(), 'google_search', ['query' => 'php']);

        $this->assertSame('Search results here.', $result);
    }

    public function test_reads_a_tool_result_from_plain_json(): void
    {
        Http::fake([
            self::URL => Http::response(
                ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => 'Plain JSON result.']]]],
                200
            ),
        ]);

        $result = app(McpClient::class)->callTool($this->server(), 'google_search', ['query' => 'php']);

        $this->assertSame('Plain JSON result.', $result);
    }

    public function test_joins_multiple_text_parts(): void
    {
        Http::fake([
            self::URL => Http::response($this->sse(
                '{"result":{"content":[{"type":"text","text":"first"},{"type":"text","text":"second"}]}}'
            ), 200),
        ]);

        $result = app(McpClient::class)->callTool($this->server(), 'google_search', ['query' => 'php']);

        $this->assertSame("first\nsecond", $result);
    }

    public function test_a_json_rpc_error_becomes_an_exception(): void
    {
        Http::fake([
            self::URL => Http::response($this->sse(
                '{"jsonrpc":"2.0","id":1,"error":{"code":-32602,"message":"Unknown tool"}}'
            ), 200),
        ]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessageMatches('/Unknown tool/');

        app(McpClient::class)->callTool($this->server(), 'nope', []);
    }

    public function test_a_tool_flagged_as_error_becomes_an_exception(): void
    {
        Http::fake([
            self::URL => Http::response($this->sse(
                '{"result":{"isError":true,"content":[{"type":"text","text":"quota exceeded"}]}}'
            ), 200),
        ]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessageMatches('/quota exceeded/');

        app(McpClient::class)->callTool($this->server(), 'google_search', ['query' => 'php']);
    }

    public function test_an_http_error_becomes_an_exception(): void
    {
        Http::fake([self::URL => Http::response('gateway down', 502)]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessageMatches('/HTTP 502/');

        app(McpClient::class)->callTool($this->server(), 'google_search', ['query' => 'php']);
    }

    public function test_an_unreadable_body_becomes_an_exception(): void
    {
        Http::fake([self::URL => Http::response('<html>not json</html>', 200)]);

        $this->expectException(McpException::class);

        app(McpClient::class)->callTool($this->server(), 'google_search', ['query' => 'php']);
    }

    public function test_session_servers_handshake_first_and_reuse_the_session(): void
    {
        Http::fakeSequence(self::URL)
            ->push($this->sse('{"result":{"protocolVersion":"2024-11-05"}}'), 200, ['mcp-session-id' => 'sess-42'])
            ->push($this->sse('{"result":{"content":[{"type":"text","text":"one"}]}}'), 200)
            ->push($this->sse('{"result":{"content":[{"type":"text","text":"two"}]}}'), 200);

        $client = app(McpClient::class);
        $server = $this->server(requiresSession: true);

        $this->assertSame('one', $client->callTool($server, 'code_exec', ['code' => '1']));
        $this->assertSame('two', $client->callTool($server, 'code_exec', ['code' => '2']));

        $requests = Http::recorded();

        // initialize + two tool calls, so the handshake happened exactly once.
        $this->assertCount(3, $requests);
        $this->assertSame('initialize', $requests[0][0]->data()['method']);
        $this->assertSame('sess-42', $requests[1][0]->header('mcp-session-id')[0]);
        $this->assertSame('sess-42', $requests[2][0]->header('mcp-session-id')[0]);
    }

    public function test_session_server_without_a_session_header_fails_loudly(): void
    {
        Http::fake([self::URL => Http::response($this->sse('{"result":{}}'), 200)]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessageMatches('/mcp-session-id/');

        app(McpClient::class)->callTool($this->server(requiresSession: true), 'code_exec', ['code' => '1']);
    }

    public function test_lists_tools(): void
    {
        Http::fake([
            self::URL => Http::response($this->sse(
                '{"result":{"tools":[{"name":"google_search","description":"Search"},{"name":"search_uni_giessen"}]}}'
            ), 200),
        ]);

        $tools = app(McpClient::class)->listTools($this->server());

        $this->assertSame(
            [
                ['name' => 'google_search', 'description' => 'Search'],
                ['name' => 'search_uni_giessen', 'description' => ''],
            ],
            $tools
        );
    }

    public function test_empty_params_are_sent_as_an_object_not_an_array(): void
    {
        // A server that validates params as an object answers HTTP 400 when it
        // receives [], which is what an empty PHP array encodes to.
        Http::fake([
            self::URL => Http::response($this->sse('{"result":{"tools":[]}}'), 200),
        ]);

        app(McpClient::class)->listTools($this->server());

        $body = Http::recorded()[0][0]->body();

        $this->assertStringContainsString('"params":{}', $body);
        $this->assertStringNotContainsString('"params":[]', $body);
    }

    public function test_ping_reports_failure_without_throwing(): void
    {
        Http::fake([self::URL => Http::response('nope', 500)]);

        $result = app(McpClient::class)->ping($this->server());

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['tools']);
    }

    public function test_a_server_without_a_url_is_rejected(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionMessageMatches('/no URL configured/');

        app(McpClient::class)->callTool(
            new McpServerConfig(name: 'broken', url: ''),
            'google_search',
            ['query' => 'php']
        );
    }
}
