<?php

declare(strict_types=1);

namespace App\Services\Translation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CodeExecutionService
{
    /**
     * Executes the given Python code via the Code Execution MCP server.
     *
     * @return array{success: bool, output: string, meta: ?array}
     */
    public function executePython(string $code): array
    {
        $mcpUrl = \Illuminate\Support\Facades\Cache::remember('translate_settings_code_execution_mcp_url', now()->addHours(1), function () {
            return \App\Models\TranslateSetting::where('key', 'code_execution_mcp_url')->value('value')
                ?? 'https://ki-mcp01.hrz.uni-giessen.de';
        });

        try {
            // Step 1: Initialize session
            $initResponse = Http::withHeaders([
                'Accept' => 'application/json, text/event-stream',
            ])->post($mcpUrl, [
                'jsonrpc' => '2.0',
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => (object) [],
                    'clientInfo' => [
                        'name' => 'HAWKI-Client',
                        'version' => '1.0.0',
                    ],
                ],
                'id' => 1,
            ]);

            if (! $initResponse->successful()) {
                throw new \Exception('Failed to initialize MCP server. HTTP status: '.$initResponse->status());
            }

            $sessionId = $initResponse->header('mcp-session-id');
            if (empty($sessionId)) {
                throw new \Exception('MCP server did not return an mcp-session-id header.');
            }

            // Step 2: Call code_exec tool
            $execResponse = Http::withHeaders([
                'Accept' => 'application/json, text/event-stream',
                'mcp-session-id' => $sessionId,
            ])->post($mcpUrl, [
                'jsonrpc' => '2.0',
                'method' => 'tools/call',
                'params' => [
                    'name' => 'code_exec',
                    'arguments' => [
                        'code' => $code,
                    ],
                ],
                'id' => 2,
            ]);

            if (! $execResponse->successful()) {
                throw new \Exception('Failed to call code execution tool. HTTP status: '.$execResponse->status());
            }

            // Parse text/event-stream response
            $body = $execResponse->body();
            $lines = explode("\n", $body);
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'data: ')) {
                    $dataText = substr($trimmed, 6);
                    $json = json_decode($dataText, true);

                    if ($json && isset($json['result']['content'][0]['text'])) {
                        $innerText = $json['result']['content'][0]['text'];
                        $isError = $json['result']['isError'] ?? false;

                        $innerJson = json_decode($innerText, true);
                        if (is_array($innerJson)) {
                            $success = ! $isError && ! ($innerJson['meta']['timed_out'] ?? false);

                            return [
                                'success' => $success,
                                'output' => $innerJson['text'] ?? '',
                                'meta' => $innerJson['meta'] ?? null,
                            ];
                        }

                        return [
                            'success' => ! $isError,
                            'output' => $innerText,
                            'meta' => null,
                        ];
                    }

                    if ($json && isset($json['error'])) {
                        throw new \Exception($json['error']['message'] ?? 'Unknown JSON-RPC error');
                    }
                }
            }

            throw new \Exception('Failed to parse execution output from MCP response.');
        } catch (\Exception $e) {
            Log::error('[CodeExecutionService] MCP Execution failed', [
                'error' => $e->getMessage(),
                'code_snippet' => substr($code, 0, 100),
            ]);

            return [
                'success' => false,
                'output' => 'Fehler bei der Ausführung: '.$e->getMessage(),
                'meta' => null,
            ];
        }
    }
}
