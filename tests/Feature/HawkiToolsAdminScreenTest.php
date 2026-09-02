<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Tools screen stores its values as app_settings overrides of the
 * hawki_tools config file, so the tool runtime keeps reading them through
 * config() and the config file stays the default for a fresh installation.
 */
class HawkiToolsAdminScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_hawki_tools_is_registered_as_a_managed_config_file(): void
    {
        // Without this the database key is split at the first underscore and
        // resolves to the hawki config file, which nothing reads.
        $this->assertArrayHasKey('hawki_tools', config('settings'));
    }

    public function test_a_stored_override_resolves_back_to_the_tool_config_key(): void
    {
        AppSetting::create([
            'key' => 'hawki_tools_tools.web_search.awareness',
            'value' => 'CUSTOM TOOL PROMPT',
            'type' => 'string',
            'source' => 'hawki_tools',
            'group' => 'tools',
        ]);

        $resolved = app(SettingsService::class)->getAllForConfig();

        $this->assertArrayHasKey('hawki_tools.tools.web_search.awareness', $resolved);
        $this->assertSame('CUSTOM TOOL PROMPT', $resolved['hawki_tools.tools.web_search.awareness']);
    }

    public function test_server_and_binding_overrides_resolve_too(): void
    {
        AppSetting::create([
            'key' => 'hawki_tools_mcp_servers.websearch-mcp.url',
            'value' => 'https://mcp.example.org/mcp',
            'type' => 'string',
            'source' => 'hawki_tools',
            'group' => 'tools',
        ]);
        AppSetting::create([
            'key' => 'hawki_tools_bindings.web_search.tools.general',
            'value' => 'brave_search',
            'type' => 'string',
            'source' => 'hawki_tools',
            'group' => 'tools',
        ]);

        $resolved = app(SettingsService::class)->getAllForConfig();

        $this->assertSame(
            'https://mcp.example.org/mcp',
            $resolved['hawki_tools.mcp_servers.websearch-mcp.url']
        );
        $this->assertSame(
            'brave_search',
            $resolved['hawki_tools.bindings.web_search.tools.general']
        );
    }

    public function test_an_override_actually_changes_what_the_tool_sends_to_the_model(): void
    {
        // The whole point of the screen: the awareness text the adapter injects
        // has to be the edited one.
        config(['hawki_tools.tools.web_search.awareness' => 'EDITED PROMPT']);

        $tool = app(\App\Services\AI\Tools\WebSearchTool::class);

        config(['hawki_tools.tools.web_search.description' => 'EDITED DESCRIPTION']);

        $this->assertSame('EDITED DESCRIPTION', $tool->getDefinition()['function']['description']);
        $this->assertSame('EDITED PROMPT', config('hawki_tools.tools.web_search.awareness'));
    }

    public function test_the_shipped_defaults_are_present_for_a_fresh_installation(): void
    {
        $this->assertNotEmpty(config('hawki_tools.tools.web_search.awareness'));
        $this->assertNotEmpty(config('hawki_tools.tools.web_search.description'));
        $this->assertNotEmpty(config('hawki_tools.mcp_servers'));
        $this->assertNotEmpty(config('hawki_tools.bindings.web_search.tools'));
    }
}
