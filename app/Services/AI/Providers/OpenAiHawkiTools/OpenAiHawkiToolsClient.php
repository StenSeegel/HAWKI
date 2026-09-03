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
            $this->resolveBindings($request, $tools)
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
            $this->resolveBindings($request, $tools)
        ))->execute($request->model);
    }

    protected function resolveStatusList(AiModelStatusCollection $statusCollection): void
    {
        (new OpenAiModelStatusRequest($this->provider))->execute($statusCollection);
    }

    /**
     * The MCP server the provider pinned for each resolved tool, keyed by tool.
     *
     * One entry per tool, deliberately. This used to return the first binding it
     * found and hand that single server to every tool of the request, which
     * misrouted a call as soon as two tools were active: with web search pinned to
     * websearch-mcp and the code interpreter pinned to nothing, switching web
     * search on sent the code interpreter's code_exec call to the search server,
     * where it came back as "Tool 'code_exec' not found". A tool with no pinned
     * server gets null and falls back to the server named in its own binding.
     *
     * @param  array<string, \App\Services\AI\Tools\HawkiToolInterface>  $tools
     * @return array<string, string|null>
     */
    private function resolveBindings(AiRequest $request, array $tools): array
    {
        $bindings = [];

        foreach (array_keys($tools) as $key) {
            $bindings[$key] = $this->registry->bindingFor($request->model, $key);
        }

        return $bindings;
    }
}
