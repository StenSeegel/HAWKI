<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * Turns the raw argument string of a tool call into validated arguments.
 *
 * This is not optional hardening: jlu/gemma-4-26b-it intermittently leaks a chat
 * template token into its arguments (e.g. {"query": "current weather<|\"|>"}).
 * The JSON stays valid, so the artifact would travel straight into the tool call
 * unless it is stripped here.
 */
class ToolArgumentSanitizer
{
    /**
     * Chat template control tokens that models sometimes emit inside argument values.
     */
    private const TEMPLATE_TOKEN_PATTERN = '/<\|[^|>]*\|>/u';

    /**
     * @param  string  $raw  The arguments string as sent by the model.
     * @param  array  $schema  The tool's argument JSON schema.
     * @return array{ok: bool, arguments: array, error: ?string}
     */
    public function sanitize(string $raw, array $schema): array
    {
        $decoded = json_decode(trim($raw), true);

        if (! is_array($decoded)) {
            // Some models wrap the JSON in prose or fences; try the first object in it.
            $decoded = $this->recoverJsonObject($raw);
        }

        if (! is_array($decoded)) {
            return [
                'ok' => false,
                'arguments' => [],
                'error' => 'The arguments were not valid JSON. Send a single JSON object matching the tool schema.',
            ];
        }

        $decoded = $this->stripTemplateTokens($decoded);

        return $this->validateAgainstSchema($decoded, $schema);
    }

    /**
     * Remove chat template artifacts from every string in the argument tree.
     */
    private function stripTemplateTokens(mixed $value): mixed
    {
        if (is_string($value)) {
            $cleaned = preg_replace(self::TEMPLATE_TOKEN_PATTERN, '', $value) ?? $value;

            return trim($cleaned);
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->stripTemplateTokens($item), $value);
        }

        return $value;
    }

    /**
     * Pull the first balanced JSON object out of a noisy string.
     */
    private function recoverJsonObject(string $raw): ?array
    {
        $start = strpos($raw, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($raw);

        for ($i = $start; $i < $length; $i++) {
            if ($raw[$i] === '{') {
                $depth++;
            } elseif ($raw[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $candidate = substr($raw, $start, $i - $start + 1);
                    $decoded = json_decode($candidate, true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
        }

        return null;
    }

    /**
     * Check the decoded arguments against the tool schema: required keys present,
     * declared types respected, unknown keys dropped.
     *
     * @return array{ok: bool, arguments: array, error: ?string}
     */
    private function validateAgainstSchema(array $arguments, array $schema): array
    {
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];

        $clean = [];
        foreach ($properties as $name => $definition) {
            if (! array_key_exists($name, $arguments)) {
                continue;
            }

            $value = $arguments[$name];
            $expected = $definition['type'] ?? null;

            if ($expected !== null && ! $this->matchesType($value, $expected)) {
                return [
                    'ok' => false,
                    'arguments' => [],
                    'error' => 'Argument "'.$name.'" must be of type '.$expected.'.',
                ];
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            $clean[$name] = $value;
        }

        foreach ($required as $name) {
            if (! array_key_exists($name, $clean)) {
                return [
                    'ok' => false,
                    'arguments' => [],
                    'error' => 'Argument "'.$name.'" is required and must not be empty.',
                ];
            }
        }

        return ['ok' => true, 'arguments' => $clean, 'error' => null];
    }

    private function matchesType(mixed $value, string $expected): bool
    {
        return match ($expected) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value),
            default => true,
        };
    }
}
