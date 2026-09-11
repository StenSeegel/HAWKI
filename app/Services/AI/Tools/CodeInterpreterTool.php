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
        private readonly SandboxImages $images,
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
                    /*
                     * Measured against the gVisor sandbox behind code-exec-mcp:
                     * the working directory is read-only, only /tmp can be
                     * written, plt.show() produces nothing, and a printed base64
                     * data URI is the one way an image gets out.
                     *
                     * This belongs here rather than in the awareness prompt: the
                     * prompt is editable in the admin UI and an installation that
                     * has customised it would never learn the convention, and
                     * without it a model reaches for savefig('plot.png') and the
                     * run fails on a read-only directory.
                     */
                    'description' => 'The Python code to run. Print whatever should be returned; only stdout is reported back. '
                        .'Write temporary files to /tmp, which is writable; the working directory is not. '
                        .'Images and plots are supported: keep the figure in memory and print it as a data URI, e.g. '
                        .'buf = io.BytesIO(); fig.savefig(buf, format="png"); '
                        .'print("data:image/png;base64," + base64.b64encode(buf.getvalue()).decode()) - '
                        .'that renders as a picture in the chat. Use it instead of plt.show() or savefig() to a filename. '
                        .'Vector graphics work the same way: print the SVG markup itself, or a data:image/svg+xml;base64 URI.',
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

        $output = $this->unwrap($this->client->callTool($server, $mcpTool, ['code' => $code]), $code);

        /*
         * A plot comes back as base64 written into the printed output. It has to
         * come out here, before the cap below: a PNG runs to tens of thousands of
         * characters, so truncation would both corrupt the image and spend the
         * model's context on base64. The image is stored and the request that
         * called this tool picks it up from SandboxImages.
         */
        $output = $this->images->extractFromText($output);

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
    private function unwrap(string $raw, string $code = ''): string
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
            return $this->emptyOutputHint($code);
        }

        return $text;
    }

    /**
     * What to tell the model when its code printed nothing.
     *
     * Worth tailoring, because the generic "print what should be returned" sent
     * models down the wrong path: qwen3-coder-next answered it by retrying the
     * same bare expression, and a model that had just called plt.show() got no
     * hint that an image needs the data URI route at all - it simply retried and
     * burned its rounds. Naming the actual way out turns a wasted round into a
     * useful one.
     */
    private function emptyOutputHint(string $code): string
    {
        if (preg_match('/\b(?:plt|pyplot|matplotlib|savefig|imshow)\b/i', $code) === 1) {
            return '[the code produced no output - a figure is only returned if you print it as a data URI. '
                .'Keep it in memory and print it: buf = io.BytesIO(); fig.savefig(buf, format="png"); '
                .'print("data:image/png;base64," + base64.b64encode(buf.getvalue()).decode()). '
                .'plt.show() and savefig() to a filename return the figure to nobody.]';
        }

        return '[the code produced no output - only what you print comes back, so wrap the result in print(), '
            .'e.g. print(result). A bare expression on the last line returns nothing.]';
    }
}
