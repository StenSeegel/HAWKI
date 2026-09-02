<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Services\AI\Value\AiModel;
use Illuminate\Contracts\Container\Container;

/**
 * Decides which HAWKI tools apply to a request, and hands out their definitions.
 *
 * A tool only runs when all three of these agree:
 *   1. the model carries the tool     (ai_models.settings.tools.<key>)
 *   2. the request asked for it       (payload tools.<key>, set by the frontend)
 *   3. the provider overrides it      (api_providers.additional_settings.hawki_tools)
 *
 * Rule 3 is what keeps providers with a native implementation untouched: with the
 * override off, the request converter injects the provider's own tool as before.
 */
class HawkiToolRegistry
{
    /**
     * Tool key => implementation class.
     */
    private const IMPLEMENTATIONS = [
        WebSearchTool::KEY => WebSearchTool::class,
    ];

    public function __construct(
        private readonly Container $container
    ) {}

    /**
     * The tools that should be offered to the model for this request.
     *
     * @return array<string, HawkiToolInterface>
     */
    public function resolveForRequest(AiModel $model, array $rawPayload): array
    {
        $requestedTools = is_array($rawPayload['tools'] ?? null) ? $rawPayload['tools'] : [];
        $providerConfig = $model->getProvider()->getConfig();

        $resolved = [];

        foreach (array_keys(config('hawki_tools.tools', [])) as $key) {
            if (! $model->hasTool($key)) {
                continue;
            }

            if (($requestedTools[$key] ?? false) !== true) {
                continue;
            }

            if (! $providerConfig->isHawkiToolOverridden($key)) {
                continue;
            }

            $tool = $this->make($key);
            if ($tool !== null) {
                $resolved[$key] = $tool;
            }
        }

        return $resolved;
    }

    /**
     * The OpenAI tool definitions for the given tools.
     *
     * @param  array<string, HawkiToolInterface>  $tools
     * @return array<int, array>
     */
    public function definitionsFor(array $tools): array
    {
        return array_values(array_map(
            fn (HawkiToolInterface $tool) => $tool->getDefinition(),
            $tools
        ));
    }

    /**
     * The server binding a provider pinned for the given tool, if any.
     */
    public function bindingFor(AiModel $model, string $key): ?string
    {
        return $model->getProvider()->getConfig()->getHawkiToolBinding($key);
    }

    private function make(string $key): ?HawkiToolInterface
    {
        $class = self::IMPLEMENTATIONS[$key] ?? null;
        if ($class === null) {
            return null;
        }

        $tool = $this->container->get($class);

        return $tool instanceof HawkiToolInterface ? $tool : null;
    }
}
