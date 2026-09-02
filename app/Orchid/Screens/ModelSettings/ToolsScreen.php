<?php

declare(strict_types=1);

namespace App\Orchid\Screens\ModelSettings;

use App\Models\AppSetting;
use App\Orchid\Layouts\ModelSettings\AssistantsTabMenu;
use App\Orchid\Layouts\ModelSettings\HawkiToolFieldsLayout;
use App\Orchid\Layouts\ModelSettings\HawkiToolServerFieldsLayout;
use App\Orchid\Layouts\ModelSettings\HawkiToolPromptLayout;
use App\Services\AI\Tools\HawkiToolRegistry;
use App\Services\Mcp\McpClient;
use App\Services\Mcp\McpServerRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\Label;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

/**
 * Configuration of the tools HAWKI runs itself, for providers whose API brings no
 * server side tools of its own.
 *
 * Values are stored as app_settings overrides of the hawki_tools config file, so
 * the tool runtime keeps reading them through config() and the file stays the
 * default for a fresh installation.
 */
class ToolsScreen extends Screen
{
    private const SOURCE = 'hawki_tools';

    /**
     * Keys within a tool that are editable here. Everything else in the config
     * (label, help) describes the admin UI itself and is not model facing.
     */
    private const EDITABLE_TOOL_KEYS = ['activation', 'description', 'awareness'];

    public function query(): iterable
    {
        $tools = [];
        foreach (config('hawki_tools.tools', []) as $key => $tool) {
            $tools[$key] = Arr::only($tool, self::EDITABLE_TOOL_KEYS);
        }

        return [
            'awareness_placement' => config('hawki_tools.awareness_placement', 'user'),
            'tools' => $tools,
            'mcp_servers' => config('hawki_tools.mcp_servers', []),
            'bindings' => config('hawki_tools.bindings', []),
        ];
    }

    public function name(): ?string
    {
        return 'AI Tools & Extensions';
    }

    public function description(): ?string
    {
        return 'Configure the tools HAWKI executes itself for providers without native tools, and the MCP servers behind them.';
    }

    public function permission(): ?iterable
    {
        return [
            'platform.modelsettings.assistants',
        ];
    }

    public function commandBar(): iterable
    {
        return [
            Button::make('Test MCP servers')
                ->icon('bs.wifi')
                ->method('testServers'),

            Button::make('Reset to defaults')
                ->icon('bs.arrow-counterclockwise')
                ->method('resetToDefaults')
                ->confirm('This discards every change made here and restores the values shipped in config/hawki_tools.php.'),

            Button::make('Save')
                ->icon('bs.check-circle')
                ->method('save'),
        ];
    }

    public function layout(): iterable
    {
        return [
            AssistantsTabMenu::class,

            Layout::block(HawkiToolPromptLayout::class)
                ->title('General')
                ->description('Settings shared by every HAWKI tool.'),

            Layout::accordion($this->toolSections()),

            Layout::accordion($this->serverSections()),
        ];
    }

    /**
     * One expandable section per tool, so further tools can be added without
     * the screen turning into one long form.
     *
     * @return array<string, array>
     */
    private function toolSections(): array
    {
        $registry = app(HawkiToolRegistry::class);
        $sections = [];

        foreach (config('hawki_tools.tools', []) as $key => $tool) {
            $label = $tool['label'] ?? $key;
            $implemented = $registry->isImplemented($key);
            $binding = config('hawki_tools.bindings.'.$key, []);
            $server = $binding['server'] ?? null;

            $title = $label.'  ·  '.($implemented ? 'runtime available' : 'no runtime yet')
                .'  ·  '.($server ? 'bound to '.$server : 'not bound to a server');

            $sections[$title] = [
                Layout::rows(
                    array_merge(
                        [
                            Label::make('tool_status_'.$key)
                                ->title('Status')
                                ->value($this->statusText($key, $label, $implemented, $server)),
                        ],
                        (new HawkiToolFieldsLayout($key))->getFields()
                    )
                ),
            ];
        }

        return $sections;
    }

    /**
     * One expandable section per MCP server.
     *
     * @return array<string, array>
     */
    private function serverSections(): array
    {
        $sections = [];

        foreach (config('hawki_tools.mcp_servers', []) as $name => $server) {
            $url = $server['url'] ?? '';
            $keyProvider = $server['api_key_provider'] ?? null;
            $gatewayServer = $server['gateway_server'] ?? null;

            $title = 'MCP server: '.$name.'  ·  '
                .($url !== '' ? $url.($gatewayServer ? ' ('.$gatewayServer.')' : '') : 'no URL configured')
                .'  ·  '.($keyProvider ? 'key of '.$keyProvider : 'no authentication');

            $sections[$title] = [
                Layout::rows((new HawkiToolServerFieldsLayout($name))->getFields()),
            ];
        }

        return $sections;
    }

    /**
     * What an admin needs to know before configuring this tool.
     */
    private function statusText(string $key, string $label, bool $implemented, ?string $server): string
    {
        if (! $implemented) {
            return $label.' can be configured here, but HAWKI has no runtime for it yet, so it is never offered to a model. '
                .'It needs an MCP server that serves it and a tool class registered in HawkiToolRegistry.';
        }

        if (empty($server)) {
            return $label.' has a runtime, but no MCP server is bound to it - bind one below, otherwise a call returns an error to the model.';
        }

        $trigger = app(HawkiToolRegistry::class)->needsUserActivation($key)
            ? 'It is offered to a model only when the user switches it on in the chat.'
            : 'It has no chat button: it is offered on every request of a model that carries it, and the model calls it when the request needs it.';

        return $label.' is ready: enable it per model under Language Models, and per provider under API Management '
            .'(HAWKI Tools) to override the provider\'s own implementation. '.$trigger;
    }

    /**
     * Persist the edited values as hawki_tools config overrides.
     */
    public function save(Request $request)
    {
        $validated = $request->validate([
            'awareness_placement' => 'nullable|string|in:user,system',
            'tools' => 'nullable|array',
            'tools.*.activation' => 'nullable|string|in:toggle,always',
            'tools.*.description' => 'nullable|string|max:2000',
            'tools.*.awareness' => 'nullable|string|max:20000',
            'mcp_servers' => 'nullable|array',
            'mcp_servers.*.url' => 'nullable|string|url|max:500',
            'mcp_servers.*.api_key_provider' => 'nullable|string|max:190',
            'mcp_servers.*.api_key_header' => 'nullable|string|max:190',
            'mcp_servers.*.gateway_server' => 'nullable|string|max:190',
            'mcp_servers.*.tool_prefix' => 'nullable|string|max:190',
            'mcp_servers.*.timeout' => 'nullable|integer|min:1|max:300',
            'mcp_servers.*.requires_session' => 'nullable|boolean',
            'bindings' => 'nullable|array',
            'bindings.*.server' => 'nullable|string|max:190',
            'bindings.*.tools' => 'nullable|array',
            'bindings.*.tools.*' => 'nullable|string|max:190',
        ]);

        $written = 0;

        if (! empty($validated['awareness_placement'])) {
            $this->store('awareness_placement', $validated['awareness_placement']);
            $written++;
        }

        foreach (config('hawki_tools.tools', []) as $key => $tool) {
            foreach (self::EDITABLE_TOOL_KEYS as $field) {
                $value = $validated['tools'][$key][$field] ?? null;
                if ($value === null) {
                    continue;
                }
                $this->store('tools.'.$key.'.'.$field, trim($value));
                $written++;
            }
        }

        foreach (array_keys(config('hawki_tools.mcp_servers', [])) as $name) {
            $server = $validated['mcp_servers'][$name] ?? [];

            if (isset($server['url'])) {
                $this->store('mcp_servers.'.$name.'.url', trim($server['url']));
                $written++;
            }
            // Stored even when empty, so clearing them takes effect: an empty
            // provider means no authentication, an empty gateway server means
            // the endpoint serves this server alone.
            foreach (['api_key_provider', 'api_key_header', 'gateway_server', 'tool_prefix'] as $field) {
                $this->store('mcp_servers.'.$name.'.'.$field, trim((string) ($server[$field] ?? '')));
                $written++;
            }
            if (isset($server['timeout'])) {
                $this->store('mcp_servers.'.$name.'.timeout', (int) $server['timeout'], 'integer');
                $written++;
            }
            $this->store(
                'mcp_servers.'.$name.'.requires_session',
                filter_var($server['requires_session'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'boolean'
            );
            $written++;
        }

        foreach (config('hawki_tools.bindings', []) as $toolKey => $binding) {
            $submitted = $validated['bindings'][$toolKey] ?? [];

            if (array_key_exists('server', $submitted)) {
                $this->store('bindings.'.$toolKey.'.server', (string) $submitted['server']);
                $written++;
            }

            foreach (array_keys($binding['tools'] ?? []) as $route) {
                $value = $submitted['tools'][$route] ?? null;
                if ($value === null) {
                    continue;
                }
                $this->store('bindings.'.$toolKey.'.tools.'.$route, trim($value));
                $written++;
            }
        }

        Toast::info('Saved '.$written.' tool setting(s).');

        return redirect()->route('platform.models.tools');
    }

    /**
     * Drop every override so the values from config/hawki_tools.php apply again.
     */
    public function resetToDefaults()
    {
        // Collect the keys before deleting the rows, otherwise there is nothing
        // left to clear the caches of.
        $keys = AppSetting::where('source', self::SOURCE)->pluck('key');

        AppSetting::where('source', self::SOURCE)->delete();

        foreach ($keys as $key) {
            Cache::forget('settings.'.$key);
        }

        Toast::info('Removed '.$keys->count().' override(s). The defaults from config/hawki_tools.php apply again.');

        return redirect()->route('platform.models.tools');
    }

    /**
     * Ask every configured MCP server which tools it offers, so a binding can be
     * checked against reality instead of a guessed tool name.
     */
    public function testServers(McpClient $client, McpServerRegistry $registry)
    {
        $names = $registry->names();

        if ($names === []) {
            Toast::warning('No MCP server is configured.');

            return;
        }

        foreach ($names as $name) {
            $server = $registry->get($name);

            if ($server === null) {
                Toast::error($name.': no usable URL configured.');

                continue;
            }

            try {
                $tools = $client->listTools($server);
                $toolNames = array_column($tools, 'name');

                Toast::success($name.' offers '.count($toolNames).' tool(s): '.implode(', ', $toolNames));
            } catch (\Throwable $e) {
                Toast::error($name.' is not reachable: '.$e->getMessage());
            }
        }
    }

    /**
     * Write a single hawki_tools override.
     *
     * The database key carries the config file name as a prefix, and 'source'
     * pins it, so 'hawki_tools_tools.web_search.awareness' resolves back to
     * config('hawki_tools.tools.web_search.awareness') on boot.
     */
    private function store(string $path, mixed $value, string $type = 'string'): void
    {
        $dbKey = self::SOURCE.'_'.$path;

        AppSetting::updateOrCreate(
            ['key' => $dbKey],
            [
                'value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
                'type' => $type,
                'source' => self::SOURCE,
                'group' => config('settings.group_mapping.'.self::SOURCE, 'tools'),
            ]
        );

        Cache::forget('settings.'.$dbKey);

        // Apply immediately, so a redirect straight back to this screen shows
        // the saved value rather than the one from the config file.
        config([self::SOURCE.'.'.$path => $value]);
    }

}
