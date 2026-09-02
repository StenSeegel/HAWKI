<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiFormat;
use App\Models\ApiProvider;
use App\Services\AI\Config\AiConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HawkiToolsProviderConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Provider settings only come from the database in this mode.
        config(['hawki.ai_config_system' => true]);
        app(AiConfigService::class)->clearCache();
    }

    private function createProvider(string $uniqueName, ?array $additionalSettings): ApiProvider
    {
        $format = ApiFormat::create([
            'unique_name' => 'openai-api-'.$uniqueName,
            'display_name' => 'OpenAI API '.$uniqueName,
            'client_adapter' => 'openai',
            'metadata' => [],
        ]);

        return ApiProvider::create([
            'unique_name' => $uniqueName,
            'provider_name' => $uniqueName,
            'api_format_id' => $format->id,
            'api_key' => 'sk-test',
            'base_url' => 'https://example.test/v1',
            'is_active' => true,
            'display_order' => 1,
            'additional_settings' => $additionalSettings,
        ]);
    }

    public function test_hawki_tools_reach_the_provider_config(): void
    {
        $this->createProvider('ki-at-jlu-test', [
            'hawki_tools' => [
                'web_search' => ['override' => true, 'binding' => 'websearch-mcp'],
            ],
        ]);

        $providers = app(AiConfigService::class)->getProviders();

        $this->assertArrayHasKey('ki-at-jlu-test', $providers);
        $this->assertSame(
            ['web_search' => ['override' => true, 'binding' => 'websearch-mcp']],
            $providers['ki-at-jlu-test']['hawki_tools']
        );
    }

    public function test_provider_without_additional_settings_gets_an_empty_tool_list(): void
    {
        $this->createProvider('plain-provider', null);

        $providers = app(AiConfigService::class)->getProviders();

        $this->assertArrayHasKey('plain-provider', $providers);
        $this->assertSame([], $providers['plain-provider']['hawki_tools']);
        $this->assertSame([], $providers['plain-provider']['additional_settings']);
    }

    public function test_unrelated_additional_settings_are_preserved_without_inventing_tools(): void
    {
        $this->createProvider('other-settings', ['some_flag' => true]);

        $providers = app(AiConfigService::class)->getProviders();

        $this->assertSame(['some_flag' => true], $providers['other-settings']['additional_settings']);
        $this->assertSame([], $providers['other-settings']['hawki_tools']);
    }
}
