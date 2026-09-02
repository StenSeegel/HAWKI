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
        $tools = $this->resolveTools($request);

        if ($tools === []) {
            return parent::convertRequestToPayload($request);
        }

        // Attaching the functions is not enough: these models are trained to say
        // they cannot access the internet, and with tool_choice 'auto' they answer
        // from memory - sometimes denying the capability outright - unless the
        // system prompt tells them the tool is there.
        $payload = parent::convertRequestToPayload($this->withToolAwareness($request, $tools));

        $payload['tools'] = array_merge(
            $payload['tools'] ?? [],
            $this->registry->definitionsFor($tools)
        );

        // The model decides whether a tool is needed for the answer.
        $payload['tool_choice'] = 'auto';

        return $payload;
    }

    /**
     * Add the tool awareness instruction to the system prompt of the request.
     *
     * @param  array<string, HawkiToolInterface>  $tools
     */
    private function withToolAwareness(AiRequest $request, array $tools): AiRequest
    {
        $instruction = $this->buildAwarenessInstruction($tools);
        if ($instruction === '') {
            return $request;
        }

        $payload = $request->payload ?? [];
        $messages = $payload['messages'] ?? [];

        if (isset($messages[0]) && ($messages[0]['role'] ?? '') === 'system') {
            $existing = trim((string) ($messages[0]['content']['text'] ?? ''));
            $messages[0]['content']['text'] = $existing === ''
                ? $instruction
                : $existing."\n\n".$instruction;
        } else {
            array_unshift($messages, [
                'role' => 'system',
                'content' => ['text' => $instruction],
            ]);
        }

        $payload['messages'] = $messages;

        return new AiRequest(model: $request->model, payload: $payload);
    }

    /**
     * The instruction describing the attached tools to the model.
     *
     * @param  array<string, HawkiToolInterface>  $tools
     */
    private function buildAwarenessInstruction(array $tools): string
    {
        $lines = [];

        foreach (array_keys($tools) as $key) {
            $instruction = trim((string) config('hawki_tools.tools.'.$key.'.awareness', ''));
            if ($instruction !== '') {
                $lines[] = $instruction;
            }
        }

        return implode("\n\n", $lines);
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
