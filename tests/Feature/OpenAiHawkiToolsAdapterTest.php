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

    private function seedProvider(?array $hawkiTools, bool $modelSupportsSearch = true): void
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
            'settings' => ['tools' => ['stream' => true, 'web_search' => $modelSupportsSearch]],
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

    public function test_the_rest_of_the_payload_is_unchanged_by_the_adapter(): void
    {
        $this->seedProvider(['web_search' => ['override' => true]]);

        $model = app(AiService::class)->getModelOrFail(self::MODEL_ID);
        $payload = app(OpenAiHawkiToolsRequestConverter::class)->convertRequestToPayload(
            new AiRequest(model: $model, payload: $this->payload(true))
        );

        $this->assertSame(self::MODEL_ID, $payload['model']);
        $this->assertCount(1, $payload['messages']);
        $this->assertSame('user', $payload['messages'][0]['role']);
    }
}
