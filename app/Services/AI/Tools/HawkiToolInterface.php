<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * A tool HAWKI runs on its own, for providers whose API brings no native
 * implementation of it.
 */
interface HawkiToolInterface
{
    /**
     * The tool key as used in the model settings, the provider overrides and the
     * frontend tool flags, e.g. 'web_search'.
     */
    public function getKey(): string;

    /**
     * The function definition handed to the model, in OpenAI tool format.
     *
     * @return array{type: string, function: array{name: string, description: string, parameters: array}}
     */
    public function getDefinition(): array;

    /**
     * The JSON schema of the arguments, used to validate what the model sent
     * before the call is dispatched.
     */
    public function getArgumentSchema(): array;

    /**
     * Run the tool and return the result as text for the model.
     *
     * @param  array  $arguments  The validated arguments.
     *
     * @throws \Throwable When the tool cannot produce a result.
     */
    public function execute(array $arguments, ?string $serverBinding = null): string;
}
