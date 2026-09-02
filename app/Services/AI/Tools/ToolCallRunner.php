<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use Illuminate\Support\Facades\Log;

/**
 * Executes the tool calls a model asked for.
 *
 * Every failure is turned into text the model can read and react to, rather than
 * an exception: a broken tool call should let the model apologise or retry, not
 * abort the user's message.
 */
class ToolCallRunner
{
    public function __construct(
        private readonly ToolArgumentSanitizer $sanitizer
    ) {}

    /**
     * @param  array<string, HawkiToolInterface>  $tools  The tools available in this request.
     * @param  string  $name  The tool the model called.
     * @param  string  $rawArguments  The argument string the model produced.
     * @return string The tool result, or an error message meant for the model.
     */
    public function run(array $tools, string $name, string $rawArguments, ?string $serverBinding = null): string
    {
        $tool = $tools[$name] ?? null;

        if ($tool === null) {
            return 'Error: the tool "'.$name.'" is not available. Available tools: '
                .(empty($tools) ? 'none' : implode(', ', array_keys($tools))).'.';
        }

        $sanitized = $this->sanitizer->sanitize($rawArguments, $tool->getArgumentSchema());

        if (! $sanitized['ok']) {
            Log::info('[ToolCallRunner] Rejected tool arguments', [
                'tool' => $name,
                'error' => $sanitized['error'],
                'raw' => mb_substr($rawArguments, 0, 200),
            ]);

            return 'Error: '.$sanitized['error'];
        }

        try {
            return $tool->execute($sanitized['arguments'], $serverBinding);
        } catch (\Throwable $e) {
            Log::error('[ToolCallRunner] Tool execution failed', [
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);

            return 'Error: the tool "'.$name.'" could not be executed: '.$e->getMessage();
        }
    }
}
