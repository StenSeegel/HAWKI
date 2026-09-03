<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\Interfaces\ModelProviderInterface;
use App\Services\AI\Providers\Responses\ResponsesRequestConverter;
use App\Services\AI\Utils\MessageAttachmentFinder;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiRequest;
use App\Services\AI\Value\ProviderConfig;
use Tests\TestCase;

/**
 * The native code interpreter of the Responses API.
 *
 * There is no chat button for it: a model that carries the capability is offered
 * the tool on every request and decides for itself whether to run code.
 */
class ResponsesCodeInterpreterTest extends TestCase
{
    private function convert(array $modelTools, array $hawkiTools = [], array $requestedTools = []): array
    {
        $config = new ProviderConfig('openai', [
            'active' => true,
            'adapter' => 'Responses',
            'hawki_tools' => $hawkiTools,
        ]);

        $provider = $this->createMock(ModelProviderInterface::class);
        $provider->method('getConfig')->willReturn($config);

        $model = $this->createMock(AiModel::class);
        $model->method('getTools')->willReturn($modelTools);
        $model->method('getProvider')->willReturn($provider);
        $model->method('hasTool')->willReturnCallback(
            fn (string $tool) => ($modelTools[$tool] ?? false) === true
        );

        $payload = [
            'model' => 'gpt-5.6-luna',
            'messages' => [
                ['role' => 'user', 'content' => ['text' => 'Berechne exakt 2^256.']],
            ],
            'stream' => false,
        ];

        if ($requestedTools !== []) {
            $payload['tools'] = $requestedTools;
        }

        return (new ResponsesRequestConverter(new MessageAttachmentFinder()))
            ->convertRequestToPayload(new AiRequest(model: $model, payload: $payload));
    }

    private function toolTypes(array $payload): array
    {
        return array_column($payload['tools'] ?? [], 'type');
    }

    public function test_a_capable_model_gets_the_tool_without_any_request_flag(): void
    {
        $payload = $this->convert(['code_interpreter' => true]);

        $this->assertContains('code_interpreter', $this->toolTypes($payload));
    }

    public function test_the_sandbox_is_left_to_the_api(): void
    {
        $payload = $this->convert(['code_interpreter' => true]);

        $tool = collect($payload['tools'])->firstWhere('type', 'code_interpreter');

        $this->assertSame(['type' => 'auto'], $tool['container']);
    }

    public function test_a_model_without_the_capability_gets_nothing(): void
    {
        $payload = $this->convert(['web_search' => true]);

        $this->assertNotContains('code_interpreter', $this->toolTypes($payload));
    }

    public function test_the_provider_override_hands_the_tool_to_hawki_instead(): void
    {
        // Otherwise a provider would offer both its own and HAWKI's implementation.
        $payload = $this->convert(
            ['code_interpreter' => true],
            ['code_interpreter' => ['override' => true]]
        );

        $this->assertNotContains('code_interpreter', $this->toolTypes($payload));
    }

    public function test_an_override_of_another_tool_does_not_disable_it(): void
    {
        $payload = $this->convert(
            ['code_interpreter' => true],
            ['web_search' => ['override' => true]]
        );

        $this->assertContains('code_interpreter', $this->toolTypes($payload));
    }

    public function test_it_coexists_with_the_tools_the_user_switched_on(): void
    {
        $payload = $this->convert(
            ['code_interpreter' => true, 'web_search' => true],
            [],
            ['web_search' => true]
        );

        $types = $this->toolTypes($payload);

        $this->assertContains('web_search', $types);
        $this->assertContains('code_interpreter', $types);
    }
}
