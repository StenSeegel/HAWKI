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
     * Add the tool awareness instruction to the request.
     *
     * Where the instruction sits used to decide how reliably it is followed:
     * jlu/gemma-4-26b-it reached only 4/5 with the instruction in the system
     * prompt, because its old chat template folded a system message into the
     * conversation, and 5/5 in front of the newest user message - which is why
     * the user turn became the default. Since the gateway deployed gemma's new
     * chat template the two placements measure the same (15/15 either way, with
     * and without prior turns), so 'system' is the default again and the user's
     * own message is left alone. See config/hawki_tools.php for the numbers.
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

        $placement = config('hawki_tools.awareness_placement', 'system');

        $messages = $placement === 'system'
            ? $this->placeInSystemPrompt($messages, $instruction)
            : $this->placeInLastUserMessage($messages, $instruction);

        $payload['messages'] = $messages;

        return $request->withPayload($payload);
    }

    /**
     * Prepend the instruction to the newest user message, so it sits right next
     * to the question the model is about to answer.
     *
     * It is only ever added to the payload of this one request; the stored
     * conversation keeps the user's own text.
     */
    private function placeInLastUserMessage(array $messages, string $instruction): array
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') !== 'user') {
                continue;
            }

            $text = (string) ($messages[$i]['content']['text'] ?? '');
            $messages[$i]['content']['text'] = $instruction."\n\n---\n\n".$text;

            return $messages;
        }

        // No user message to attach to (e.g. a system only request): fall back
        // to the system prompt rather than dropping the instruction.
        return $this->placeInSystemPrompt($messages, $instruction);
    }

    private function placeInSystemPrompt(array $messages, string $instruction): array
    {
        if (isset($messages[0]) && ($messages[0]['role'] ?? '') === 'system') {
            $existing = trim((string) ($messages[0]['content']['text'] ?? ''));
            $messages[0]['content']['text'] = $existing === ''
                ? $instruction
                : $existing."\n\n".$instruction;

            return $messages;
        }

        array_unshift($messages, [
            'role' => 'system',
            'content' => ['text' => $instruction],
        ]);

        return $messages;
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

        return $this->registry->resolveForRequest(
            $request->model,
            $request->payload ?? [],
            $request->assistantKey
        );
    }
}
