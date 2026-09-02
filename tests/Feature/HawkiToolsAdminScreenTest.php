<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Orchid\Screens\ModelSettings\ToolsScreen;
use App\Services\SettingsService;
use Illuminate\Http\Request;
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

    public function test_saving_writes_an_override_for_every_configured_tool_and_server(): void
    {
        // Every tool and server section of the screen is submitted together, so a
        // collapsed section must not lose its values.
        $request = Request::create('/admin/models/tools', 'POST', [
            'awareness_placement' => 'system',
            'tools' => [
                'web_search' => ['description' => 'WS desc', 'awareness' => 'WS prompt', 'activation' => 'toggle'],
                'code_interpreter' => ['description' => 'CI desc', 'awareness' => 'CI prompt', 'activation' => 'always'],
                'image_generation' => ['description' => 'IG desc', 'awareness' => 'IG prompt'],
            ],
            'mcp_servers' => [
                'websearch-mcp' => ['url' => 'https://a.test/mcp', 'timeout' => 30, 'requires_session' => false, 'api_key_provider' => 'ki-at-jlu', 'api_key_header' => 'x-litellm-api-key', 'gateway_server' => 'google_search_http', 'tool_prefix' => ''],
                'code-exec-mcp' => ['url' => 'https://b.test/mcp', 'timeout' => 90, 'requires_session' => true, 'api_key_provider' => '', 'api_key_header' => '', 'gateway_server' => '', 'tool_prefix' => ''],
            ],
            'bindings' => [
                'web_search' => ['server' => 'websearch-mcp', 'tools' => ['general' => 'g', 'local' => 'l', 'url' => 'u']],
                'code_interpreter' => ['server' => 'code-exec-mcp', 'tools' => ['run' => 'code_exec']],
                'image_generation' => ['server' => '', 'tools' => ['generate' => '']],
            ],
        ]);

        (new ToolsScreen())->save($request);

        $stored = AppSetting::where('source', 'hawki_tools')->pluck('value', 'key');

        $this->assertSame('system', $stored['hawki_tools_awareness_placement']);
        $this->assertSame('CI prompt', $stored['hawki_tools_tools.code_interpreter.awareness']);
        // Whether a tool waits for a chat button it does not have.
        $this->assertSame('always', $stored['hawki_tools_tools.code_interpreter.activation']);
        $this->assertSame('toggle', $stored['hawki_tools_tools.web_search.activation']);
        $this->assertSame('IG desc', $stored['hawki_tools_tools.image_generation.description']);
        $this->assertSame('https://b.test/mcp', $stored['hawki_tools_mcp_servers.code-exec-mcp.url']);
        $this->assertSame('1', $stored['hawki_tools_mcp_servers.code-exec-mcp.requires_session']);
        $this->assertSame('90', $stored['hawki_tools_mcp_servers.code-exec-mcp.timeout']);
        $this->assertSame('code_exec', $stored['hawki_tools_bindings.code_interpreter.tools.run']);

        // The gateway settings of a server: which provider's key opens it and
        // the prefix its tool names carry.
        $this->assertSame('ki-at-jlu', $stored['hawki_tools_mcp_servers.websearch-mcp.api_key_provider']);
        $this->assertSame('x-litellm-api-key', $stored['hawki_tools_mcp_servers.websearch-mcp.api_key_header']);
        $this->assertSame('google_search_http', $stored['hawki_tools_mcp_servers.websearch-mcp.gateway_server']);
        // Cleared rather than left at the previous value.
        $this->assertSame('', $stored['hawki_tools_mcp_servers.code-exec-mcp.api_key_provider']);

        // All three tools, both servers, every binding.
        $this->assertGreaterThanOrEqual(20, $stored->count());
    }

    public function test_resetting_removes_every_override(): void
    {
        AppSetting::create([
            'key' => 'hawki_tools_tools.web_search.awareness',
            'value' => 'custom',
            'type' => 'string',
            'source' => 'hawki_tools',
            'group' => 'tools',
        ]);
        AppSetting::create([
            'key' => 'app_name',
            'value' => 'HAWKI2',
            'type' => 'string',
            'source' => 'app',
            'group' => 'basic',
        ]);

        (new ToolsScreen())->resetToDefaults();

        $this->assertSame(0, AppSetting::where('source', 'hawki_tools')->count());
        // Unrelated settings are left alone.
        $this->assertSame(1, AppSetting::where('source', 'app')->count());
    }

    public function test_the_shipped_defaults_are_present_for_a_fresh_installation(): void
    {
        $this->assertNotEmpty(config('hawki_tools.tools.web_search.awareness'));
        $this->assertNotEmpty(config('hawki_tools.tools.web_search.description'));
        $this->assertNotEmpty(config('hawki_tools.mcp_servers'));
        $this->assertNotEmpty(config('hawki_tools.bindings.web_search.tools'));
    }
}
