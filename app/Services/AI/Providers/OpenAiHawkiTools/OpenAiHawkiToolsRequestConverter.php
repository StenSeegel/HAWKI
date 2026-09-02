<?php

declare(strict_types=1);

namespace App\Services\AI\Providers\OpenAiHawkiTools;

use App\Services\AI\Providers\OpenAi\OpenAiRequestConverter;
use App\Services\AI\Tools\HawkiToolInterface;
use App\Services\AI\Tools\HawkiToolRegistry;
use App\Services\AI\Utils\MessageAttachmentFinder;
use App\Services\AI\Value\AiRequest;
use Illuminate\Container\Attributes\Singleton;

/**
 * The OpenAI chat completions converter plus HAWKI's own tools.
 *
 * Everything about the payload stays as it is for the plain OpenAI adapter; the
 * only addition is the function definitions of the HAWKI tools this request
 * resolved to, offered to the model as regular OpenAI function calling.
 */
#[Singleton]
readonly class OpenAiHawkiToolsRequestConverter extends OpenAiRequestConverter
{
    public function __construct(
        MessageAttachmentFinder $attachmentFinder,
        private HawkiToolRegistry $registry
    ) {
        parent::__construct($attachmentFinder);
    }

    public function convertRequestToPayload(AiRequest $request): array
    {
        $payload = parent::convertRequestToPayload($request);

        $tools = $this->resolveTools($request);
        if ($tools === []) {
            return $payload;
        }

        $payload['tools'] = array_merge(
            $payload['tools'] ?? [],
            $this->registry->definitionsFor($tools)
        );

        // The model decides whether a tool is needed for the answer.
        $payload['tool_choice'] = 'auto';

        return $payload;
    }

    /**
     * The HAWKI tools that apply to this request.
     *
     * @return array<string, HawkiToolInterface>
     */
    public function resolveTools(AiRequest $request): array
    {
        if ($request->model === null) {
            return [];
        }

        return $this->registry->resolveForRequest($request->model, $request->payload ?? []);
    }
}
