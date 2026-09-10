<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\AiService;
use App\Services\AI\Interfaces\ModelProviderInterface;
use App\Services\AI\Providers\OpenAiHawkiTools\OpenAiHawkiToolsRequestConverter;
use App\Services\AI\Providers\Responses\ResponsesRequestConverter;
use App\Services\AI\Tools\HawkiToolRegistry;
use App\Services\AI\Utils\MessageAttachmentFinder;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiRequest;
use App\Services\AI\Value\ProviderConfig;
use Tests\TestCase;

/**
 * HAWKI's own utility assistants must reach a provider without tools.
 *
 * The regression: the tool resolution knew nothing about a request being a
 * title, an improvement or a summary, so a tool whose activation is 'always'
 * (the code interpreter) was attached to them like to a chat turn. The
 * awareness prompt that comes with it is prepended to the newest user message
 * by default - the very text the title model is asked to summarise, with 10
 * tokens to answer in - so the "title" became a fragment of the tool prompt.
 * On a native Responses provider no text leaks, but a title request could
 * still trigger a real sandbox run billed as usage type 'title'.
 */
class UtilityRequestWithoutToolsTest extends TestCase
{
    private const TITLE_PROMPT = 'Du bist ein Assistent, der einem Nachrichtentext einen Titel zuweist.';

    private const USER_TEXT = 'Fasse diese Datei zusammen';

    /**
     * @param  array<string, bool>  $modelTools
     * @param  array<string, array>  $hawkiTools
     */
    private function model(array $modelTools, array $hawkiTools): AiModel
    {
        $config = new ProviderConfig('ki-at-jlu', [
            'active' => true,
            'adapter' => 'OpenAiHawkiTools',
            'hawki_tools' => $hawkiTools,
        ]);

        $provider = $this->createMock(ModelProviderInterface::class);
        $provider->method('getConfig')->willReturn($config);

        $model = $this->createMock(AiModel::class);
        $model->method('getProvider')->willReturn($provider);
        $model->method('getTools')->willReturn($modelTools);
        $model->method('hasTool')->willReturnCallback(
            fn (string $tool) => ($modelTools[$tool] ?? false) === true
        );

        return $model;
    }

    /**
     * A utility request as the frontend and the server-side jobs send it: a
     * system prompt, the text to work on, and no tools key at all.
     */
    private function utilityRequest(AiModel $model, ?string $assistantKey): AiRequest
    {
        return new AiRequest(
            model: $model,
            payload: [
                'model' => 'jlu/gemma-4-26b-it',
                'stream' => false,
                'max_tokens' => 10,
                'messages' => [
                    ['role' => 'system', 'content' => ['text' => self::TITLE_PROMPT]],
                    ['role' => 'user', 'content' => ['text' => self::USER_TEXT]],
                ],
            ],
            assistantKey: $assistantKey
        );
    }

    private function hawkiToolsConverter(): OpenAiHawkiToolsRequestConverter
    {
        return new OpenAiHawkiToolsRequestConverter(
            new MessageAttachmentFinder(),
            app(HawkiToolRegistry::class)
        );
    }

    private function messageTexts(array $payload): string
    {
        return json_encode($payload['messages'] ?? []);
    }

    public static function assistantKeys(): array
    {
        return [
            'title' => ['title_generator'],
            'improver' => ['prompt_improver'],
            'summarizer' => ['summarizer'],
        ];
    }

    public static function placements(): array
    {
        return [
            'awareness in the user turn' => ['user'],
            'awareness in the system prompt' => ['system'],
        ];
    }

    /**
     * @dataProvider assistantKeys
     */
    public function test_a_utility_request_carries_no_hawki_tools(string $assistantKey): void
    {
        $model = $this->model(
            ['code_interpreter' => true, 'stream' => true],
            ['code_interpreter' => ['override' => true]]
        );

        $payload = $this->hawkiToolsConverter()->convertRequestToPayload(
            $this->utilityRequest($model, $assistantKey)
        );

        $this->assertArrayNotHasKey('tools', $payload);
        $this->assertArrayNotHasKey('tool_choice', $payload);
    }

    /**
     * The placement only decided how visible the leak was: in the system prompt
     * the instruction is merged into the Name_Prompt instead of standing in
     * front of the user's text. Neither may happen.
     *
     * @dataProvider placements
     */
    public function test_no_awareness_text_leaks_in_either_placement(string $placement): void
    {
        config(['hawki_tools.awareness_placement' => $placement]);

        $model = $this->model(
            ['code_interpreter' => true, 'stream' => true],
            ['code_interpreter' => ['override' => true]]
        );

        $payload = $this->hawkiToolsConverter()->convertRequestToPayload(
            $this->utilityRequest($model, 'title_generator')
        );

        $sent = $this->messageTexts($payload);
        $awareness = (string) config('hawki_tools.tools.code_interpreter.awareness');

        $this->assertNotSame('', $awareness, 'The tool ships an awareness prompt, otherwise this proves nothing.');
        $this->assertStringNotContainsString('code_interpreter', $sent);
        $this->assertStringNotContainsString(mb_substr($awareness, 0, 40), $sent);

        // What the title model is supposed to see, and nothing else.
        $this->assertStringContainsString(self::TITLE_PROMPT, $sent);
        $this->assertStringContainsString(self::USER_TEXT, $sent);
    }

    public function test_a_chat_request_to_the_same_provider_still_gets_its_tools(): void
    {
        $model = $this->model(
            ['code_interpreter' => true, 'stream' => true],
            ['code_interpreter' => ['override' => true]]
        );

        $payload = $this->hawkiToolsConverter()->convertRequestToPayload(
            $this->utilityRequest($model, null)
        );

        $this->assertSame('auto', $payload['tool_choice']);
        $this->assertSame(
            ['code_interpreter'],
            array_column(array_column($payload['tools'], 'function'), 'name')
        );
        $this->assertStringContainsString('code_interpreter', $this->messageTexts($payload));
    }

    /**
     * @param  array<string, bool>  $modelTools
     */
    private function responsesPayload(array $modelTools, ?string $assistantKey): array
    {
        $config = new ProviderConfig('openai', [
            'active' => true,
            'adapter' => 'Responses',
            'hawki_tools' => [],
        ]);

        $provider = $this->createMock(ModelProviderInterface::class);
        $provider->method('getConfig')->willReturn($config);

        $model = $this->createMock(AiModel::class);
        $model->method('getProvider')->willReturn($provider);
        $model->method('getTools')->willReturn($modelTools);
        $model->method('hasTool')->willReturnCallback(
            fn (string $tool) => ($modelTools[$tool] ?? false) === true
        );

        return (new ResponsesRequestConverter(new MessageAttachmentFinder()))
            ->convertRequestToPayload($this->utilityRequest($model, $assistantKey));
    }

    /**
     * @dataProvider assistantKeys
     */
    public function test_a_utility_request_carries_no_native_responses_tool(string $assistantKey): void
    {
        $payload = $this->responsesPayload(['code_interpreter' => true], $assistantKey);

        $this->assertArrayNotHasKey('tools', $payload);
        $this->assertArrayNotHasKey('include', $payload);
    }

    public function test_a_chat_request_to_a_responses_provider_still_gets_the_native_tool(): void
    {
        $payload = $this->responsesPayload(['code_interpreter' => true], null);

        $this->assertContains('code_interpreter', array_column($payload['tools'], 'type'));
        $this->assertContains('code_interpreter_call.outputs', $payload['include']);
    }

    /**
     * The callers that drive a utility assistant server-side build a raw array,
     * so the key travels in the payload. AiService is what lifts it onto the
     * request object - and takes it out of the payload, so it can never be part
     * of an upstream body.
     */
    public function test_ai_service_lifts_the_assistant_key_off_the_payload(): void
    {
        [$request, $payload] = $this->resolve([
            'model' => 'jlu/gemma-4-26b-it',
            'assistantKey' => 'title_generator',
            'messages' => [],
        ]);

        $this->assertSame('title_generator', $request->assistantKey);
        $this->assertTrue($request->isUtility());
        $this->assertArrayNotHasKey('assistantKey', $payload);
    }

    public function test_an_unknown_assistant_key_is_dropped_rather_than_carried(): void
    {
        [$request, $payload] = $this->resolve([
            'model' => 'jlu/gemma-4-26b-it',
            'assistantKey' => 'something_else',
            'messages' => [],
        ]);

        $this->assertNull($request->assistantKey);
        $this->assertFalse($request->isUtility());
        $this->assertArrayNotHasKey('assistantKey', $payload);
    }

    public function test_a_chat_payload_stays_a_chat_request(): void
    {
        [$request, $payload] = $this->resolve([
            'model' => 'jlu/gemma-4-26b-it',
            'messages' => [],
        ]);

        $this->assertNull($request->assistantKey);
        $this->assertFalse($request->isUtility());
        $this->assertSame(['model' => 'jlu/gemma-4-26b-it', 'messages' => []], $payload);
    }

    /**
     * Run a raw payload through AiService's request resolution.
     *
     * The model lookup is stubbed out, so this stays a test of the payload
     * handling and needs no seeded provider.
     *
     * @return array{0: AiRequest, 1: array}
     */
    private function resolve(array $payload): array
    {
        $service = $this->getMockBuilder(AiService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getModelOrFail'])
            ->getMock();

        $service->method('getModelOrFail')->willReturn($this->model([], []));

        $method = new \ReflectionMethod(AiService::class, 'resolveRequestAndModel');
        $method->setAccessible(true);

        /** @var AiRequest $request */
        [$request] = $method->invoke($service, $payload);

        return [$request, $request->payload ?? []];
    }
}
