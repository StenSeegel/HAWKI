<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\Interfaces\ModelProviderInterface;
use App\Services\AI\Tools\HawkiToolRegistry;
use App\Services\AI\Tools\CodeInterpreterTool;
use App\Services\AI\Tools\WebSearchTool;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\ProviderConfig;
use Tests\TestCase;

class HawkiToolRegistryTest extends TestCase
{
    /**
     * @param  array<string, bool>  $modelTools
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
        $model->method('hasTool')->willReturnCallback(
            fn (string $tool) => ($modelTools[$tool] ?? false) === true
        );

        return $model;
    }

    public function test_all_three_conditions_met_resolves_the_tool(): void
    {
        $tools = app(HawkiToolRegistry::class)->resolveForRequest(
            $this->model(['web_search' => true], ['web_search' => ['override' => true]]),
            ['tools' => ['web_search' => true]]
        );

        $this->assertArrayHasKey('web_search', $tools);
        $this->assertInstanceOf(WebSearchTool::class, $tools['web_search']);
    }

    public function test_no_provider_override_means_the_native_tool_stays_in_charge(): void
    {
        $tools = app(HawkiToolRegistry::class)->resolveForRequest(
            $this->model(['web_search' => true], []),
            ['tools' => ['web_search' => true]]
        );

        $this->assertSame([], $tools);
    }

    public function test_override_explicitly_false_resolves_nothing(): void
    {
        $tools = app(HawkiToolRegistry::class)->resolveForRequest(
            $this->model(['web_search' => true], ['web_search' => ['override' => false]]),
            ['tools' => ['web_search' => true]]
        );

        $this->assertSame([], $tools);
    }

    public function test_model_without_the_tool_resolves_nothing(): void
    {
        $tools = app(HawkiToolRegistry::class)->resolveForRequest(
            $this->model([], ['web_search' => ['override' => true]]),
            ['tools' => ['web_search' => true]]
        );

        $this->assertSame([], $tools);
    }

    public function test_request_that_did_not_ask_for_search_resolves_nothing(): void
    {
        $tools = app(HawkiToolRegistry::class)->resolveForRequest(
            $this->model(['web_search' => true], ['web_search' => ['override' => true]]),
            ['tools' => ['web_search' => false]]
        );

        $this->assertSame([], $tools);
    }

    public function test_request_without_any_tools_key_resolves_nothing(): void
    {
        $tools = app(HawkiToolRegistry::class)->resolveForRequest(
            $this->model(['web_search' => true], ['web_search' => ['override' => true]]),
            []
        );

        $this->assertSame([], $tools);
    }

    public function test_a_tool_without_a_chat_button_needs_no_request_flag(): void
    {
        // The frontend only ever sends web_search and image_generation, so a tool
        // that waited for a flag of its own would never reach a model.
        $tools = app(HawkiToolRegistry::class)->resolveForRequest(
            $this->model(['code_interpreter' => true], ['code_interpreter' => ['override' => true]]),
            ['tools' => ['web_search' => false]]
        );

        $this->assertArrayHasKey('code_interpreter', $tools);
        $this->assertInstanceOf(CodeInterpreterTool::class, $tools['code_interpreter']);
    }

    public function test_an_always_offered_tool_still_needs_the_model_flag_and_the_override(): void
    {
        $registry = app(HawkiToolRegistry::class);

        $this->assertSame([], $registry->resolveForRequest(
            $this->model([], ['code_interpreter' => ['override' => true]]),
            []
        ));

        $this->assertSame([], $registry->resolveForRequest(
            $this->model(['code_interpreter' => true], []),
            []
        ));
    }

    public function test_the_activation_mode_decides_whether_a_flag_is_required(): void
    {
        $registry = app(HawkiToolRegistry::class);

        // As shipped: web search has a button, code execution has none.
        $this->assertTrue($registry->needsUserActivation('web_search'));
        $this->assertFalse($registry->needsUserActivation('code_interpreter'));

        // Editable on the Tools screen, and it takes effect immediately.
        config(['hawki_tools.tools.code_interpreter.activation' => 'toggle']);
        $this->assertTrue($registry->needsUserActivation('code_interpreter'));

        $this->assertSame([], $registry->resolveForRequest(
            $this->model(['code_interpreter' => true], ['code_interpreter' => ['override' => true]]),
            []
        ));
    }

    public function test_a_tool_the_user_switched_on_is_offered_alongside_an_always_offered_one(): void
    {
        $tools = app(HawkiToolRegistry::class)->resolveForRequest(
            $this->model(
                ['web_search' => true, 'code_interpreter' => true],
                ['web_search' => ['override' => true], 'code_interpreter' => ['override' => true]]
            ),
            ['tools' => ['web_search' => true]]
        );

        $this->assertSame(['web_search', 'code_interpreter'], array_keys($tools));
    }

    public function test_definitions_are_openai_function_specs(): void
    {
        $registry = app(HawkiToolRegistry::class);

        $tools = $registry->resolveForRequest(
            $this->model(['web_search' => true], ['web_search' => ['override' => true]]),
            ['tools' => ['web_search' => true]]
        );

        $definitions = $registry->definitionsFor($tools);

        $this->assertCount(1, $definitions);
        $this->assertSame('function', $definitions[0]['type']);
        $this->assertSame('web_search', $definitions[0]['function']['name']);
        $this->assertNotEmpty($definitions[0]['function']['description']);
        $this->assertSame(['query'], $definitions[0]['function']['parameters']['required']);
    }

    public function test_binding_is_read_from_the_provider(): void
    {
        $registry = app(HawkiToolRegistry::class);

        $this->assertSame('websearch-mcp', $registry->bindingFor(
            $this->model(['web_search' => true], ['web_search' => ['override' => true, 'binding' => 'websearch-mcp']]),
            'web_search'
        ));

        $this->assertNull($registry->bindingFor(
            $this->model(['web_search' => true], ['web_search' => ['override' => true]]),
            'web_search'
        ));
    }
}
