<?php

namespace App\Orchid\Screens\Settings;

use App\Models\AppSetting;
use App\Services\SettingsService;
use App\Orchid\Traits\OrchidSettingsManagementTrait;
use App\Orchid\Layouts\System\ReverbClientLayout;
use App\Orchid\Layouts\System\ReverbServerLayout;
use App\Orchid\Layouts\System\ReverbAppLayout;
use App\Orchid\Layouts\System\BroadcastingLayout;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Screen;

use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class WebSocketSettingsScreen extends Screen
{
    use OrchidSettingsManagementTrait;

    /**
     * @var SettingsService
     */
    private $settingsService;

    /**
     * Construct the screen
     */
    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Query data.
     *
     * @return array
     */
    public function query(): iterable
    {
        $settings = AppSetting::where('group', 'websockets')->get();
        $settingsData = [];

        // Convert database settings to flat input format for form fields
        foreach ($settings as $setting) {
            $flatKey = $this->convertDbKeyToFlatInputName($setting->key);
            // Remove the 'settings.' prefix from the flat key for the array key
            $arrayKey = str_replace('settings.', '', $flatKey);
            $settingsData[$arrayKey] = $setting->value;
        }

        return [
            'settings' => $settingsData,
        ];
    }

    /**
     * Display header name.
     *
     * @return string|null
     */
    public function name(): ?string
    {
        return 'WebSocket Settings';
    }

    /**
     * Display header description.
     *
     * @return string|null
     */
    public function description(): ?string
    {
        return 'Configure Laravel Reverb WebSocket server settings for real-time communication.';
    }

    /**
     * Button commands.
     *
     * @return \Orchid\Screen\Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Button::make('HTTPS Defaults')
                ->icon('bs.shield-lock')
                ->method('setHttpsDefaults')
                ->confirm('This will set all WebSocket settings to secure HTTPS defaults. Continue?')
                ->canSee($this->hasPermission()),

            Button::make('HTTP Defaults')
                ->icon('bs.globe')
                ->method('setHttpDefaults')
                ->confirm('This will set all WebSocket settings to HTTP defaults (insecure). Continue?')
                ->canSee($this->hasPermission()),

            Button::make('Save')
                ->icon('bs.check-circle')
                ->method('saveSettings')
                ->canSee($this->hasPermission()),
        ];
    }

    /**
     * Views.
     *
     * @return \Orchid\Screen\Layout[]|string[]
     */
    public function layout(): iterable
    {
        return [
            Layout::block([
                BroadcastingLayout::class,
            ])
                ->title('Broadcasting Configuration')
                ->description('Settings for Laravel Broadcasting and real-time event handling.'),

            Layout::block([
                ReverbServerLayout::class,
            ])
                ->title('Server Configuration')
                ->description('Settings for the Reverb WebSocket server instance.'),

            Layout::block([
                ReverbClientLayout::class,
            ])
                ->title('Client Configuration')
                ->description('Settings for WebSocket client connections from the frontend.'),

            Layout::block([
                ReverbAppLayout::class,
            ])
                ->title('Application Configuration')
                ->description('Settings for WebSocket application credentials and limits.'),
        ];
    }

    /**
     * Check if user has permission to modify settings
     *
     * @return bool
     */
    protected function hasPermission(): bool
    {
        return auth()->user()->hasAccess('platform.systems.settings');
    }

    /**
     * The permission required to access this screen.
     *
     * @return iterable|null
     */
    public function permission(): ?iterable
    {
        return ['platform.systems.settings'];
    }

    /**
     * Set HTTPS default values for WebSocket configuration
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function setHttpsDefaults()
    {
        if (!$this->hasPermission()) {
            Toast::error('You do not have permission to modify settings.');
            return redirect()->back();
        }

        $httpsDefaults = [
            // Broadcasting Configuration
            'broadcasting_default' => 'reverb',
            'broadcasting_connections.reverb.driver' => 'reverb',
            'broadcasting_connections.reverb.key' => 'hawki-app-key',
            'broadcasting_connections.reverb.secret' => 'hawki-app-secret',
            'broadcasting_connections.reverb.app_id' => 'hawki',
            'broadcasting_connections.reverb.options.host' => 'hawki.test',
            'broadcasting_connections.reverb.options.port' => '443',
            'broadcasting_connections.reverb.options.scheme' => 'https',

            // Server Configuration
            'reverb_default' => 'reverb',
            'reverb_servers.reverb.host' => '0.0.0.0',
            'reverb_servers.reverb.hostname' => 'hawki.test',
            'reverb_servers.reverb.port' => '8080',
            'reverb_servers.reverb.max_request_size' => '10000',

            // Client Configuration
            'reverb_apps.apps.0.options.host' => 'hawki.test',
            'reverb_apps.apps.0.options.port' => '443',
            'reverb_apps.apps.0.options.scheme' => 'https',

            // App Configuration
            'reverb_apps.provider' => 'config',
            'reverb_apps.apps.0.key' => 'hawki-app-key',
            'reverb_apps.apps.0.secret' => 'hawki-app-secret',
            'reverb_apps.apps.0.app_id' => 'hawki',
            'reverb_apps.apps.0.allowed_origins' => '["*"]',
            'reverb_apps.apps.0.ping_interval' => '60',
            'reverb_apps.apps.0.max_message_size' => '250000',
        ];

        return $this->applyDefaults($httpsDefaults, 'HTTPS');
    }

    /**
     * Set HTTP default values for WebSocket configuration
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function setHttpDefaults()
    {
        if (!$this->hasPermission()) {
            Toast::error('You do not have permission to modify settings.');
            return redirect()->back();
        }

        $httpDefaults = [
            // Broadcasting Configuration
            'broadcasting_default' => 'reverb',
            'broadcasting_connections.reverb.driver' => 'reverb',
            'broadcasting_connections.reverb.key' => 'hawki-app-key',
            'broadcasting_connections.reverb.secret' => 'hawki-app-secret',
            'broadcasting_connections.reverb.app_id' => 'hawki',
            'broadcasting_connections.reverb.options.host' => 'localhost',
            'broadcasting_connections.reverb.options.port' => '8080',
            'broadcasting_connections.reverb.options.scheme' => 'http',

            // Server Configuration
            'reverb_default' => 'reverb',
            'reverb_servers.reverb.host' => '0.0.0.0',
            'reverb_servers.reverb.hostname' => 'localhost',
            'reverb_servers.reverb.port' => '8080',
            'reverb_servers.reverb.max_request_size' => '10000',

            // Client Configuration
            'reverb_apps.apps.0.options.host' => 'localhost',
            'reverb_apps.apps.0.options.port' => '8080',
            'reverb_apps.apps.0.options.scheme' => 'http',

            // App Configuration
            'reverb_apps.provider' => 'config',
            'reverb_apps.apps.0.key' => 'hawki-app-key',
            'reverb_apps.apps.0.secret' => 'hawki-app-secret',
            'reverb_apps.apps.0.app_id' => 'hawki',
            'reverb_apps.apps.0.allowed_origins' => '["*"]',
            'reverb_apps.apps.0.ping_interval' => '60',
            'reverb_apps.apps.0.max_message_size' => '250000',
        ];

        return $this->applyDefaults($httpDefaults, 'HTTP');
    }

    /**
     * Apply default values to WebSocket settings
     *
     * @param array $defaults
     * @param string $type
     * @return \Illuminate\Http\RedirectResponse
     */
    private function applyDefaults(array $defaults, string $type)
    {
        $count = 0;
        
        foreach ($defaults as $key => $value) {
            $setting = AppSetting::where('key', $key)->first();
            
            if ($setting) {
                $normalizedNewValue = $this->normalizeValueForComparison($value, $setting->type);
                $normalizedExistingValue = $this->normalizeValueForComparison($setting->value, $setting->type);
                
                if ($normalizedExistingValue !== $normalizedNewValue) {
                    $formattedValue = $this->formatValueForDatabaseStorage($normalizedNewValue, $setting->type);
                    $setting->value = $formattedValue;
                    $setting->save();
                    
                    Cache::forget('app_settings_' . $setting->key);
                    $count++;
                }
            }
        }
        
        if ($count > 0) {
            try {
                \Artisan::call('config:clear');
                \App\Providers\ConfigServiceProvider::clearConfigCache();
                
                Toast::success("{$type} defaults applied successfully. {$count} settings updated and configuration cache cleared.");
            } catch (\Exception $e) {
                Toast::warning("{$type} defaults applied, but clearing cache failed: " . $e->getMessage());
            }
        } else {
            Toast::info("No changes needed. All settings already match {$type} defaults.");
        }
        
        return redirect()->back();
    }
}
