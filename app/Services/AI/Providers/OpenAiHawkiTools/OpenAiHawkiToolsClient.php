<?php

declare(strict_types=1);

namespace App\Services\AI\Providers\OpenAiHawkiTools;

use App\Services\AI\Providers\OpenAi\OpenAiClient;
use App\Services\AI\Providers\OpenAi\Request\OpenAiModelStatusRequest;
use App\Services\AI\Providers\OpenAi\Request\OpenAiNonStreamingRequest;
use App\Services\AI\Providers\OpenAi\Request\OpenAiStreamingRequest;
use App\Services\AI\Providers\OpenAiHawkiTools\Request\OpenAiHawkiToolsNonStreamingRequest;
use App\Services\AI\Providers\OpenAiHawkiTools\Request\OpenAiHawkiToolsStreamingRequest;
use App\Services\AI\Tools\HawkiToolRegistry;
use App\Services\AI\Tools\ToolCallRunner;
use App\Services\AI\Value\AiModelStatusCollection;
use App\Services\AI\Value\AiRequest;
use App\Services\AI\Value\AiResponse;

/**
 * Client for providers that speak the OpenAI chat completions format but bring no
 * server side tools of their own - the ki@JLU gateway being the reason this exists.
 *
 * It behaves exactly like the plain OpenAI client until a HAWKI tool override is
 * configured for the provider. From then on the tools the request resolved to are
 * offered to the model as OpenAI functions and executed by HAWKI.
 */
class OpenAiHawkiToolsClient extends OpenAiClient
{
    public function __construct(
        private readonly OpenAiHawkiToolsRequestConverter $toolConverter,
        private readonly HawkiToolRegistry $registry,
        private readonly ToolCallRunner $runner,
    ) {
        parent::__construct($toolConverter);
    }

    protected function executeRequest(AiRequest $request): AiResponse
    {
        $tools = $this->toolConverter->resolveTools($request);
        $payload = $this->toolConverter->convertRequestToPayload($request);

        if ($tools === []) {
            return (new OpenAiNonStreamingRequest($payload))->execute($request->model);
        }

        return (new OpenAiHawkiToolsNonStreamingRequest(
            $payload,
            $tools,
            $this->runner,
            $this->resolveBinding($request, $tools)
        ))->execute($request->model);
    }

    protected function executeStreamingRequest(AiRequest $request, callable $onData): void
    {
        $tools = $this->toolConverter->resolveTools($request);
        $payload = $this->toolConverter->convertRequestToPayload($request);

        if ($tools === []) {
            (new OpenAiStreamingRequest($payload, $onData))->execute($request->model);

            return;
        }

        (new OpenAiHawkiToolsStreamingRequest(
            $payload,
            \Closure::fromCallable($onData),
            $tools,
            $this->runner,
            $this->resolveBinding($request, $tools)
        ))->execute($request->model);
    }

    protected function resolveStatusList(AiModelStatusCollection $statusCollection): void
    {
        (new OpenAiModelStatusRequest($this->provider))->execute($statusCollection);
    }

    /**
     * The MCP server binding the provider pinned for the resolved tools, if any.
     *
     * @param  array<string, \App\Services\AI\Tools\HawkiToolInterface>  $tools
     */
    private function resolveBinding(AiRequest $request, array $tools): ?string
    {
        foreach (array_keys($tools) as $key) {
            $binding = $this->registry->bindingFor($request->model, $key);
            if ($binding !== null) {
                return $binding;
            }
        }

        return null;
    }
}
