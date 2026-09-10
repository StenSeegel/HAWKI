<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiRequest;
use Illuminate\Contracts\Container\Container;

/**
 * Decides which HAWKI tools apply to a request, and hands out their definitions.
 *
 * A tool only runs when all of these agree:
 *   1. the model carries the tool     (ai_models.settings.tools.<key>)
 *   2. the request asked for it       (payload tools.<key>, set by the frontend)
 *   3. the provider overrides it      (api_providers.additional_settings.hawki_tools)
 *
 * And before all three: the request has to be a chat turn. HAWKI's own utility
 * assistants (title, prompt improver, summarizer) never get tools.
 *
 * Rule 2 applies only to the tools the chat UI has a button for. A tool whose
 * activation is 'always' has no button and is offered on every request of a model
 * that carries it - the model alone decides whether the request needs it.
 *
 * Rule 3 is what keeps providers with a native implementation untouched: with the
 * override off, the request converter injects the provider's own tool as before.
 */
class HawkiToolRegistry
{
    /**
     * Tool key => implementation class.
     *
     * A tool that is defined in config/hawki_tools.php but missing here can be
     * configured in the admin UI and never runs - the runtime is what decides,
     * so the Tools screen reads this list to say so.
     */
    private const IMPLEMENTATIONS = [
        WebSearchTool::KEY => WebSearchTool::class,
        CodeInterpreterTool::KEY => CodeInterpreterTool::class,
        ImageGenerationTool::KEY => ImageGenerationTool::class,
    ];

    public function __construct(
        private readonly Container $container
    ) {}

    /**
     * The tools that should be offered to the model for this request.
     *
     * @param  string|null  $assistantKey  The utility assistant this request
     *                                     serves, if any (see {@see AiRequest::isUtility()}).
     * @return array<string, HawkiToolInterface>
     */
    public function resolveForRequest(AiModel $model, array $rawPayload, ?string $assistantKey = null): array
    {
        /*
         * Rule 0: a request HAWKI drives itself gets no tools at all.
         *
         * Naming a chat, improving a prompt and summarising a chatlog are all
         * answered from the text in the request. A tool whose activation is
         * 'always' would otherwise be attached here as it is to a chat turn,
         * and its awareness prompt - prepended to the user message by default -
         * became the "title" the model had 10 tokens to produce.
         */
        if ($assistantKey !== null) {
            return [];
        }

        $requestedTools = is_array($rawPayload['tools'] ?? null) ? $rawPayload['tools'] : [];
        $providerConfig = $model->getProvider()->getConfig();

        $resolved = [];

        foreach (array_keys(config('hawki_tools.tools', [])) as $key) {
            if (! $model->hasTool($this->modelFlag($key))) {
                continue;
            }

            if ($this->needsUserActivation($key) && ($requestedTools[$key] ?? false) !== true) {
                continue;
            }

            if (! $providerConfig->isHawkiToolOverridden($key)) {
                continue;
            }

            $tool = $this->make($key);
            if ($tool !== null) {
                /*
                 * What the image tool needs but the model cannot send: the format
                 * picked with the size buttons, and the attached image an edit
                 * works on. Both travel in the payload, not in the tool arguments.
                 */
                if ($tool instanceof ImageGenerationTool) {
                    $tool->configureForRequest($rawPayload);
                }

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

    /**
     * The model capability a tool needs, which is the tool key unless the model
     * settings knew the capability under another name first ('image_gen').
     */
    public function modelFlag(string $key): string
    {
        $flag = config('hawki_tools.tools.'.$key.'.model_flag');

        return is_string($flag) && $flag !== '' ? $flag : $key;
    }

    /**
     * Whether the user has to switch this tool on for a request.
     *
     * Tools with a button in the chat UI do; a tool without one would never be
     * reachable if it waited for a flag the frontend never sends.
     */
    public function needsUserActivation(string $key): bool
    {
        return config('hawki_tools.tools.'.$key.'.activation', 'toggle') !== 'always';
    }

    /**
     * Whether a runtime exists for the given tool. A configured tool without one
     * is never offered to a model, however it is set up.
     */
    public function isImplemented(string $key): bool
    {
        return array_key_exists($key, self::IMPLEMENTATIONS);
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
