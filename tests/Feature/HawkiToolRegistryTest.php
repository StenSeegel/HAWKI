<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\Interfaces\ModelProviderInterface;
use App\Services\AI\Tools\HawkiToolRegistry;
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
