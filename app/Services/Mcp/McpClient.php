<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Services\Mcp\Exception\McpException;
use App\Services\Mcp\Value\McpServerConfig;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A single JSON-RPC client for the MCP servers HAWKI talks to.
 *
 * This replaces the two diverging implementations that grew in the translation
 * services: one did an 'initialize' handshake and passed an mcp-session-id along,
 * the other posted tool calls straight away. Both response styles are handled here.
 */
class McpClient
{
    private const PROTOCOL_VERSION = '2024-11-05';

    /**
     * Cached session ids per server name, so a single request does not re-handshake
     * for every tool call it makes.
     *
     * @var array<string, string>
     */
    private array $sessions = [];

    /**
     * List the tools a server offers. Used by the admin screen to show what is
     * actually available instead of relying on hardcoded tool names.
     *
     * @return array<int, array{name: string, description: string}>
     *
     * @throws McpException
     */
    public function listTools(McpServerConfig $server): array
    {
        $result = $this->rpc($server, 'tools/list', []);

        $tools = [];
        foreach ($result['tools'] ?? [] as $tool) {
            if (empty($tool['name'])) {
                continue;
            }
            // A gateway endpoint may answer with every server's tools; only the
            // ones of the server this config points at are relevant.
            if (! $server->ownsTool((string) $tool['name'])) {
                continue;
            }
            $tools[] = [
                // Reported under the gateway prefix, but bindings are written in
                // the server's own tool names.
                'name' => $server->stripPrefix((string) $tool['name']),
                'description' => (string) ($tool['description'] ?? ''),
            ];
        }

        return $tools;
    }

    /**
     * Call a tool and return its textual content.
     *
     * @throws McpException
     */
    public function callTool(McpServerConfig $server, string $name, array $arguments): string
    {
        $content = $this->callToolContent($server, $name, $arguments);

        if ($content['text'] === '') {
            throw new McpException('Tool '.$name.' returned no content.');
        }

        return $content['text'];
    }

    /**
     * Call a tool and return everything it answered with: the text parts, and the
     * images it returned as MCP image blocks.
     *
     * A tool whose result IS a picture - image generation - would otherwise lose
     * it: {@see callTool} joins the text parts and an image block carries no text,
     * so the bytes would be dropped and the call would look like it returned
     * nothing but its status line.
     *
     * @return array{text: string, images: array<int, array{data: string, mimeType: string}>}
     *
     * @throws McpException
     */
    public function callToolContent(McpServerConfig $server, string $name, array $arguments): array
    {
        $result = $this->rpc($server, 'tools/call', [
            'name' => $server->qualifyTool($name),
            'arguments' => $arguments,
        ]);

        if (($result['isError'] ?? false) === true) {
            throw new McpException('Tool '.$name.' reported an error: '.$this->extractText($result));
        }

        return [
            'text' => $this->extractText($result),
            'images' => $this->extractImages($result),
        ];
    }

    /**
     * Check whether a server answers at all. Returns the tool count on success.
     *
     * @return array{ok: bool, message: string, tools: int}
     */
    public function ping(McpServerConfig $server): array
    {
        try {
            $tools = $this->listTools($server);

            return [
                'ok' => true,
                'message' => 'Server answered with '.count($tools).' tool(s).',
                'tools' => count($tools),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'tools' => 0];
        }
    }

    /**
     * Send a JSON-RPC request and return its 'result' payload.
     *
     * @throws McpException
     */
    private function rpc(McpServerConfig $server, string $method, array $params): array
    {
        if (! $server->isUsable()) {
            throw new McpException('MCP server "'.$server->name.'" has no URL configured.');
        }

        $headers = ['Accept' => 'application/json, text/event-stream'];

        if ($server->requiresSession) {
            $headers['mcp-session-id'] = $this->resolveSession($server);
        }

        $response = $this->post($server, $headers, [
            'jsonrpc' => '2.0',
            'method' => $method,
            // An empty PHP array encodes to [], and a server that expects params
            // to be an object rejects that with HTTP 400 - which is why tools/call
            // worked while tools/list did not.
            'params' => empty($params) ? new \stdClass() : $params,
            'id' => 1,
        ]);

        if (! $response->successful()) {
            throw new McpException(
                'MCP server "'.$server->name.'" returned HTTP '.$response->status().' for '.$method.'.'
            );
        }

        return $this->parseResult($response->body(), $server, $method);
    }

    /**
     * Run the initialize handshake and remember the session id for this instance.
     *
     * @throws McpException
     */
    private function resolveSession(McpServerConfig $server): string
    {
        if (isset($this->sessions[$server->name])) {
            return $this->sessions[$server->name];
        }

        $response = $this->post($server, ['Accept' => 'application/json, text/event-stream'], [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'HAWKI-Client', 'version' => '1.0.0'],
            ],
            'id' => 1,
        ]);

        if (! $response->successful()) {
            throw new McpException(
                'Failed to initialize MCP server "'.$server->name.'": HTTP '.$response->status().'.'
            );
        }

        $sessionId = $response->header('mcp-session-id');
        if (empty($sessionId)) {
            throw new McpException('MCP server "'.$server->name.'" returned no mcp-session-id header.');
        }

        return $this->sessions[$server->name] = $sessionId;
    }

    private function post(McpServerConfig $server, array $headers, array $body): Response
    {
        if ($server->apiKey !== null) {
            $headers[$server->apiKeyHeader] = 'Bearer '.$server->apiKey;
        }

        if ($server->gatewayServer !== '') {
            $headers['x-mcp-servers'] = $server->gatewayServer;
        }

        $request = Http::withHeaders($headers)->timeout($server->timeout);

        try {
            return $request->post($server->url, $body);
        } catch (\Throwable $e) {
            throw new McpException(
                'MCP server "'.$server->name.'" is unreachable: '.$e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * Pull the JSON-RPC result out of a response body.
     *
     * Servers answer either with plain JSON or with a text/event-stream in which the
     * payload sits behind 'data: ' lines, so both shapes are accepted here.
     *
     * @throws McpException
     */
    private function parseResult(string $body, McpServerConfig $server, string $method): array
    {
        foreach ($this->candidatePayloads($body) as $payload) {
            $json = json_decode($payload, true);
            if (! is_array($json)) {
                continue;
            }

            if (isset($json['error'])) {
                throw new McpException(
                    'MCP server "'.$server->name.'" rejected '.$method.': '
                    .($json['error']['message'] ?? 'unknown JSON-RPC error')
                );
            }

            if (array_key_exists('result', $json)) {
                return is_array($json['result']) ? $json['result'] : [];
            }
        }

        Log::warning('[McpClient] Could not parse response', [
            'server' => $server->name,
            'method' => $method,
            'body' => mb_substr($body, 0, 500),
        ]);

        throw new McpException('MCP server "'.$server->name.'" returned an unreadable response for '.$method.'.');
    }

    /**
     * Yield every JSON candidate in the body: the SSE data frames first, then the
     * whole body for servers that answer with plain JSON.
     *
     * @return iterable<string>
     */
    private function candidatePayloads(string $body): iterable
    {
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data:')) {
                yield trim(substr($line, 5));
            }
        }

        yield trim($body);
    }

    /**
     * Join the text parts of a tool result into a single string.
     */
    private function extractText(array $result): string
    {
        $parts = [];

        foreach ($result['content'] ?? [] as $item) {
            if (isset($item['text']) && is_string($item['text'])) {
                $parts[] = $item['text'];
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * The image blocks of a tool result: {"type": "image", "data": "<base64>",
     * "mimeType": "image/png"}. The base64 is returned as it arrived - storing it
     * is the caller's business, and it must not travel on to the model.
     *
     * @return array<int, array{data: string, mimeType: string}>
     */
    private function extractImages(array $result): array
    {
        $images = [];

        foreach ($result['content'] ?? [] as $item) {
            if (($item['type'] ?? '') !== 'image') {
                continue;
            }

            $data = $item['data'] ?? null;
            if (! is_string($data) || $data === '') {
                continue;
            }

            $images[] = [
                'data' => $data,
                'mimeType' => (string) ($item['mimeType'] ?? 'image/png'),
            ];
        }

        return $images;
    }
}
