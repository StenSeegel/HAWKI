<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiModel as AiModelRecord;
use App\Models\ApiFormat;
use App\Models\ApiProvider;
use App\Services\AI\AiService;
use App\Services\AI\Config\AiConfigService;
use App\Services\AI\Providers\OpenAiHawkiTools\OpenAiHawkiToolsClient;
use App\Services\AI\Providers\OpenAiHawkiTools\OpenAiHawkiToolsRequestConverter;
use App\Services\AI\Utils\ModelAwareClient;
use App\Services\AI\Value\AiRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenAiHawkiToolsAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL_ID = 'jlu/gemma-4-26b-it';

    protected function setUp(): void
    {
        parent::setUp();

        config(['hawki.ai_config_system' => true]);
    }

    private function seedProvider(?array $hawkiTools, bool $modelSupportsSearch = true, bool $modelSupportsCode = false): void
    {
        $format = ApiFormat::create([
            'unique_name' => 'openai-api-hawki-tools',
            'display_name' => 'OpenAI API + HAWKI Tools',
            'client_adapter' => 'openaihawkitools',
            'metadata' => [],
        ]);

        $format->endpoints()->create([
            'name' => 'chat.create',
            'path' => '/chat/completions',
            'method' => 'POST',
            'is_active' => true,
        ]);
        $format->endpoints()->create([
            'name' => 'models.list',
            'path' => '/models',
            'method' => 'GET',
            'is_active' => true,
        ]);

        $provider = ApiProvider::create([
            'unique_name' => 'ki-at-jlu',
            'provider_name' => 'ki@JLU',
            'api_format_id' => $format->id,
            'api_key' => 'sk-test',
            'base_url' => 'https://gateway.test/v1',
            'is_active' => true,
            'display_order' => 1,
            'additional_settings' => $hawkiTools === null ? null : ['hawki_tools' => $hawkiTools],
        ]);

        AiModelRecord::create([
            'provider_id' => $provider->id,
            'model_id' => self::MODEL_ID,
            'label' => 'Gemma 4 26B',
            'is_active' => true,
            'is_visible' => true,
            'display_order' => 1,
            'settings' => ['tools' => [
                'stream' => true,
                'web_search' => $modelSupportsSearch,
                'code_interpreter' => $modelSupportsCode,
            ]],
            'information' => ['input' => ['text'], 'output' => ['text']],
        ]);

        app(AiConfigService::class)->clearCache();
    }

    private function payload(bool $webSearch): array
    {
        return [
            'model' => self::MODEL_ID,
            'messages' => [
                ['role' => 'user', 'content' => ['text' => 'What is the weather in Giessen?']],
            ],
            'tools' => ['web_search' => $webSearch],
            'stream' => false,
        ];
    }

    public function test_the_adapter_resolves_to_the_hawki_tools_client(): void
    {
        $this->seedProvider(['web_search' => ['override' => true]]);

        $model = app(AiService::class)->getModelOrFail(self::MODEL_ID);
        $client = $model->getClient();

        $this->assertInstanceOf(ModelAwareClient::class, $client);
        $this->assertInstanceOf(OpenAiHawkiToolsClient::class, $client->getConcreteClient());
    }

    /**
     * Each tool is given the server ITS provider entry pins, and null when it pins
     * none - not the first binding found for the request.
     *
     * The regression this pins down: with web search pinned to websearch-mcp and
     * the code interpreter pinned to nothing (exactly how the ki@JLU provider is
     * configured), one binding for the whole request sent the code interpreter's
     * code_exec call to the search server. It answered "Tool 'code_exec' not
     * found", so a chat with web search switched on could not run code at all -
     * no result, no plot - while the same chat with search off worked.
     */
    public function test_each_tool_gets_its_own_pinned_server(): void
    {
        $this->seedProvider(
            [
                'web_search' => ['override' => true, 'binding' => 'websearch-mcp'],
                'code_interpreter' => ['override' => true],
            ],
            modelSupportsCode: true
        );

        $model = app(AiService::class)->getModelOrFail(self::MODEL_ID);

        $request = new AiRequest(model: $model, payload: [
            'model' => self::MODEL_ID,
            'messages' => [['role' => 'user', 'content' => ['text' => 'Plot sin(x) and check the weather.']]],
            // Web search is a toggle, so this is the chat button being on.
            'tools' => ['web_search' => true],
            'stream' => false,
        ]);

        $client = app(\App\Services\AI\Providers\OpenAiHawkiTools\OpenAiHawkiToolsClient::class);
        $tools = app(OpenAiHawkiToolsRequestConverter::class)->resolveTools($request);

        $this->assertArrayHasKey('web_search', $tools);
        $this->assertArrayHasKey('code_interpreter', $tools, 'The code interpreter is always on for a capable model.');

        $resolve = new \ReflectionMethod($client, 'resolveBindings');
        $resolve->setAccessible(true);
        $bindings = $resolve->invoke($client, $request, $tools);

        $this->assertSame('websearch-mcp', $bindings['web_search']);
        $this->assertNull(
            $bindings['code_interpreter'],
            'The code interpreter must fall back to its own binding, not inherit the search server.'
        );
    }

    public function test_the_function_definition_is_added_when_the_override_is_on(): void
    {
        $this->seedProvider(['web_search' => ['override' => true]]);

        $model = app(AiService::class)->getModelOrFail(self::MODEL_ID);
        $payload = app(OpenAiHawkiToolsRequestConverter::class)->convertRequestToPayload(
            new AiRequest(model: $model, payload: $this->payload(true))
        );

        $this->assertSame('auto', $payload['tool_choice']);
        $this->assertCount(1, $payload['tools']);
        $this->assertSame('function', $payload['tools'][0]['type']);
        $this->assertSame('web_search', $payload['tools'][0]['function']['name']);
        $this->assertSame(
            ['query'],
            $payload['tools'][0]['function']['parameters']['required']
        );
    }

    public function test_no_function_definition_without_the_override(): void
    {
        $this->seedProvider(null);

        $model = app(AiService::class)->getModelOrFail(self::MODEL_ID);
        $payload = app(OpenAiHawkiToolsRequestConverter::class)->convertRequestToPayload(
            new AiRequest(model: $model, payload: $this->payload(true))
        );

        $this->assertArrayNotHasKey('tools', $payload);
        $this->assertArrayNotHasKey('tool_choice', $payload);
    }

    public function test_no_function_definition_when_the_request_did_not_ask_for_search(): void
    {
        $this->seedProvider(['web_search' => ['override' => true]]);

        $model = app(AiService::class)->getModelOrFail(self::MODEL_ID);
        $payload = app(OpenAiHawkiToolsRequestConverter::class)->convertRequestToPayload(
            new AiRequest(model: $model, payload: $this->payload(false))
        );

        $this->assertArrayNotHasKey('tools', $payload);
    }

    public function test_no_function_definition_when_the_model_does_not_carry_the_tool(): void
    {
        $this->seedProvider(['web_search' => ['override' => true]], modelSupportsSearch: false);

        $model = app(AiService::class)->getModelOrFail(self::MODEL_ID);
        $payload = app(OpenAiHawkiToolsRequestConverter::class)->convertRequestToPayload(
            new AiRequest(model: $model, payload: $this->payload(true))
        );

        $this->assertArrayNotHasKey('tools', $payload);
    }

    public function test_tool_awareness_is_placed_in_front_of_the_newest_user_message(): void
    {
        // Without this the models keep to their training and answer that they have
        // no internet access, instead of calling the tool that is attached. The
        // user turn is the default placement because every model followed it there.
        $this->seedProvider(['web_search' => ['override' => true]]);

        $model = app(AiService::class)->getModelOrFail(self::MODEL_ID);
        $payload = app(OpenAiHawkiToolsRequestConverter::class)->convertRequestToPayload(
            new AiRequest(model: $model, payload: $this->payload(true))
        );

        $user = $payload['messages'][0]['content'][0]['text'];

        $this->assertSame('user', $payload['messages'][0]['role']);
        $this->assertStringContainsString('web_search', $user);
        $this->assertStringContainsString('cannot search the web', $user);

        // The user's own question survives, after the instruction.
        $this->assertStringContainsString('What is the weather in Giessen?', $user);
        $this->assertStringEndsWith('What is the weather in Giessen?', $user);
    }

    public function test_only_the_newest_user_message_carries_the_instruction(): void
    {
        $this->seedProvider(['web_search' => ['override' => true]]);

        $request = $this->payload(true);
        array_unshift($request['messages'],
            ['role' => 'user', 'content' => ['text' => 'Earlier question']],
            ['role' => 'assistant', 'content' => ['text' => 'Earlier answer']],
        );

        $model = app(AiService::class)->getModelOrFail(self::MODEL_ID);
        $payload = app(OpenAiHawkiToolsRequestConverter::class)->convertRequestToPayload(
            new AiRequest(model: $model, payload: $request)
        );

        $this->assertSame('Earlier question', $payload['messages'][0]['content'][0]['text']);
        $this->assertStringContainsString('web_search', $payload['messages'][2]['content'][0]['text']);
    }

    public function test_the_system_placement_is_still_available(): void
    {
        config(['hawki_tools.awareness_placement' => 'system']);
        $this->seedProvider(['web_search' => ['override' => true]]);

        $request = $this->payload(true);
        array_unshift($request['messages'], [
            'role' => 'system',
            'content' => ['text' => 'You are a helpful assistant for university staff.'],
        ]);

        $model = app(AiService::class)->getModelOrFail(self::MODEL_ID);
        $payload = app(OpenAiHawkiToolsRequestConverter::class)->convertRequestToPayload(
            new AiRequest(model: $model, payload: $request)
        );

        $system = $payload['messages'][0]['content'][0]['text'];

        $this->assertCount(2, $payload['messages']);
        $this->assertStringContainsString('helpful assistant for university staff', $system);
        $this->assertStringContainsString('web_search', $system);
    }

    public function test_no_awareness_is_injected_when_no_tool_applies(): void
    {
        $this->seedProvider(null);

        $model = app(AiService::class)->getModelOrFail(self::MODEL_ID);
        $payload = app(OpenAiHawkiToolsRequestConverter::class)->convertRequestToPayload(
            new AiRequest(model: $model, payload: $this->payload(true))
        );

        $this->assertSame('user', $payload['messages'][0]['role']);
        $this->assertCount(1, $payload['messages']);
    }

    public function test_the_rest_of_the_payload_is_unchanged_by_the_adapter(): void
    {
        $this->seedProvider(['web_search' => ['override' => true]]);

        $model = app(AiService::class)->getModelOrFail(self::MODEL_ID);
        $payload = app(OpenAiHawkiToolsRequestConverter::class)->convertRequestToPayload(
            new AiRequest(model: $model, payload: $this->payload(true))
        );

        $this->assertSame(self::MODEL_ID, $payload['model']);

        // Only the instruction is added; no extra message appears.
        $this->assertCount(1, $payload['messages']);
        $this->assertSame('user', $payload['messages'][0]['role']);
        $this->assertStringEndsWith(
            'What is the weather in Giessen?',
            $payload['messages'][0]['content'][0]['text']
        );
    }
}
