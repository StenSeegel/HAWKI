<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Services\Mcp\Exception\McpException;
use App\Services\Mcp\McpClient;
use App\Services\Mcp\McpServerRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Python execution served by HAWKI through an MCP server, for providers that
 * bring no code interpreter of their own.
 *
 * The server behind it is the same one the translation module has been using
 * through CodeExecutionService; the difference is that the model calls it here
 * instead of a hardcoded flow.
 */
class CodeInterpreterTool implements HawkiToolInterface
{
    public const KEY = 'code_interpreter';

    /**
     * Only stdout comes back, so an output cap keeps a runaway loop from filling
     * the model's context with its own print statements.
     */
    private const MAX_OUTPUT_CHARS = 8000;

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
                    'Execute Python code and return its output.'
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
                'code' => [
                    'type' => 'string',
                    'description' => 'The Python code to run. Print whatever should be returned; only stdout is reported back.',
                ],
            ],
            'required' => ['code'],
        ];
    }

    public function execute(array $arguments, ?string $serverBinding = null): string
    {
        $code = (string) ($arguments['code'] ?? '');
        if (trim($code) === '') {
            throw new McpException('No code was given.');
        }

        $binding = $this->registry->binding(self::KEY, $serverBinding);
        $server = $binding['server'];

        if ($server === null) {
            throw new McpException('The code interpreter is not bound to a reachable MCP server.');
        }

        $mcpTool = $binding['tools']['run'] ?? null;
        if ($mcpTool === null || $mcpTool === '') {
            throw new McpException('The code interpreter binding has no tool for the "run" route.');
        }

        Log::info('[CodeInterpreterTool] Calling MCP tool', [
            'server' => $server->name,
            'tool' => $mcpTool,
            'code_length' => strlen($code),
        ]);

        $output = $this->unwrap($this->client->callTool($server, $mcpTool, ['code' => $code]));

        if (mb_strlen($output) > self::MAX_OUTPUT_CHARS) {
            return mb_substr($output, 0, self::MAX_OUTPUT_CHARS)
                ."\n\n[output truncated after ".self::MAX_OUTPUT_CHARS.' characters]';
        }

        return $output;
    }

    /**
     * The execution server answers with an envelope around the output:
     * {"text": "...", "meta": {"timed_out": false, "stderr_len": 0, ...}}.
     * The model should read the program output, not the envelope, so it is
     * unwrapped here and only the parts it can act on are kept.
     */
    private function unwrap(string $raw): string
    {
        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! array_key_exists('text', $decoded)) {
            // A server that returns plain output needs no unwrapping.
            return $raw;
        }

        $text = trim((string) $decoded['text']);
        $meta = is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [];

        if (($meta['timed_out'] ?? false) === true) {
            return ($text === '' ? '' : $text."\n\n")
                .'[the code was stopped because it ran too long - make it finish faster]';
        }

        if ($text === '') {
            return '[the code produced no output - print what should be returned]';
        }

        return $text;
    }
}
