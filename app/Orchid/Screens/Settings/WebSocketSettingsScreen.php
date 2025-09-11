<?php

namespace App\Orchid\Screens\Settings;

use App\Models\AppSetting;
use App\Services\SettingsService;
use App\Orchid\Traits\OrchidSettingsManagementTrait;
use App\Orchid\Layouts\System\ReverbClientLayout;
use App\Orchid\Layouts\System\ReverbServerLayout;
use App\Orchid\Layouts\System\ReverbAppLayout;
use App\Orchid\Layouts\System\BroadcastingLayout;
use App\Orchid\Layouts\System\ViteLayout;

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

            Button::make('Herd Defaults')
                ->icon('bs.pc-display')
                ->method('setHerdDefaults')
                ->confirm('This will set all WebSocket settings optimized for Laravel Herd local development. Continue?')
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

            Layout::block([
                ViteLayout::class,
            ])
                ->title('Frontend Configuration')
                ->description('Settings for frontend JavaScript WebSocket client configuration.'),
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
     * Uses APP_URL to determine the correct host and reads environment variables
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function setHttpsDefaults()
    {
        if (!$this->hasPermission()) {
            Toast::error('You do not have permission to modify settings.');
            return redirect()->back();
        }

        // Parse APP_URL to get the correct host
        $appUrl = config('app.url');
        $parsedUrl = parse_url($appUrl);
        $appHost = $parsedUrl['host'] ?? 'localhost';
        $appScheme = $parsedUrl['scheme'] ?? 'https';
        
        // Use environment variables or intelligent defaults
        $reverbAppKey = env('REVERB_APP_KEY', 'hawki-app-key');
        $reverbAppSecret = env('REVERB_APP_SECRET', 'hawki-app-secret');
        $reverbAppId = env('REVERB_APP_ID', 'hawki');
        $reverbHost = env('REVERB_HOST', $appHost);
        $reverbPort = env('REVERB_PORT', '8080');
        $reverbServerHost = env('REVERB_SERVER_HOST', '0.0.0.0');
        $reverbServerPort = env('REVERB_SERVER_PORT', '8080');

        $httpsDefaults = [
            // Broadcasting Configuration
            'broadcasting_default' => 'reverb',
            'broadcasting_connections.reverb.driver' => 'reverb',
            'broadcasting_connections.reverb.key' => $reverbAppKey,
            'broadcasting_connections.reverb.secret' => $reverbAppSecret,
            'broadcasting_connections.reverb.app_id' => $reverbAppId,
            'broadcasting_connections.reverb.options.host' => $reverbHost,
            'broadcasting_connections.reverb.options.port' => '443',
            'broadcasting_connections.reverb.options.scheme' => 'https',

            // Server Configuration
            'reverb_default' => 'reverb',
            'reverb_servers.reverb.host' => $reverbServerHost,
            'reverb_servers.reverb.hostname' => $reverbHost,
            'reverb_servers.reverb.port' => $reverbServerPort,
            'reverb_servers.reverb.max_request_size' => '10000',

            // Client Configuration
            'reverb_apps.apps.0.options.host' => $reverbHost,
            'reverb_apps.apps.0.options.port' => '443',
            'reverb_apps.apps.0.options.scheme' => 'https',

            // App Configuration
            'reverb_apps.provider' => 'config',
            'reverb_apps.apps.0.key' => $reverbAppKey,
            'reverb_apps.apps.0.secret' => $reverbAppSecret,
            'reverb_apps.apps.0.app_id' => $reverbAppId,
            'reverb_apps.apps.0.allowed_origins' => '["*"]',
            'reverb_apps.apps.0.ping_interval' => '60',
            'reverb_apps.apps.0.max_message_size' => '250000',

            // VITE Frontend Configuration - Critical for client-side connection
            'vite_reverb_app_key' => $reverbAppKey,
            'vite_reverb_host' => $reverbHost,
            'vite_reverb_port' => '443',
            'vite_reverb_scheme' => 'https',
            'vite_reverb_app_cluster' => env('VITE_REVERB_APP_CLUSTER', 'production'),
        ];

        return $this->applyDefaults($httpsDefaults, 'HTTPS');
    }

    /**
     * Set HTTP default values for WebSocket configuration
     * Uses APP_URL to determine the correct host and reads environment variables
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function setHttpDefaults()
    {
        if (!$this->hasPermission()) {
            Toast::error('You do not have permission to modify settings.');
            return redirect()->back();
        }

        // Parse APP_URL to get the correct host
        $appUrl = config('app.url');
        $parsedUrl = parse_url($appUrl);
        $appHost = $parsedUrl['host'] ?? 'localhost';
        
        // Use environment variables or intelligent defaults
        $reverbAppKey = env('REVERB_APP_KEY', 'hawki-app-key');
        $reverbAppSecret = env('REVERB_APP_SECRET', 'hawki-app-secret');
        $reverbAppId = env('REVERB_APP_ID', 'hawki');
        $reverbHost = env('REVERB_HOST', $appHost);
        $reverbPort = env('REVERB_PORT', '8080');
        $reverbServerHost = env('REVERB_SERVER_HOST', '0.0.0.0');
        $reverbServerPort = env('REVERB_SERVER_PORT', '8080');

        $httpDefaults = [
            // Broadcasting Configuration
            'broadcasting_default' => 'reverb',
            'broadcasting_connections.reverb.driver' => 'reverb',
            'broadcasting_connections.reverb.key' => $reverbAppKey,
            'broadcasting_connections.reverb.secret' => $reverbAppSecret,
            'broadcasting_connections.reverb.app_id' => $reverbAppId,
            'broadcasting_connections.reverb.options.host' => $reverbHost,
            'broadcasting_connections.reverb.options.port' => $reverbPort,
            'broadcasting_connections.reverb.options.scheme' => 'http',

            // Server Configuration
            'reverb_default' => 'reverb',
            'reverb_servers.reverb.host' => $reverbServerHost,
            'reverb_servers.reverb.hostname' => $reverbHost,
            'reverb_servers.reverb.port' => $reverbServerPort,
            'reverb_servers.reverb.max_request_size' => '10000',

            // Client Configuration
            'reverb_apps.apps.0.options.host' => $reverbHost,
            'reverb_apps.apps.0.options.port' => $reverbPort,
            'reverb_apps.apps.0.options.scheme' => 'http',

            // App Configuration
            'reverb_apps.provider' => 'config',
            'reverb_apps.apps.0.key' => $reverbAppKey,
            'reverb_apps.apps.0.secret' => $reverbAppSecret,
            'reverb_apps.apps.0.app_id' => $reverbAppId,
            'reverb_apps.apps.0.allowed_origins' => '["*"]',
            'reverb_apps.apps.0.ping_interval' => '60',
            'reverb_apps.apps.0.max_message_size' => '250000',

            // VITE Frontend Configuration - Critical for client-side connection
            'vite_reverb_app_key' => $reverbAppKey,
            'vite_reverb_host' => $reverbHost,
            'vite_reverb_port' => $reverbPort,
            'vite_reverb_scheme' => 'http',
            'vite_reverb_app_cluster' => env('VITE_REVERB_APP_CLUSTER', 'development'),
        ];

        return $this->applyDefaults($httpDefaults, 'HTTP');
    }

    /**
     * Set Laravel Herd optimized default values for WebSocket configuration
     * Specifically designed for local development with Herd
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function setHerdDefaults()
    {
        if (!$this->hasPermission()) {
            Toast::error('You do not have permission to modify settings.');
            return redirect()->back();
        }

        // Parse APP_URL to get the correct host
        $appUrl = config('app.url');
        $parsedUrl = parse_url($appUrl);
        $appHost = $parsedUrl['host'] ?? 'localhost';
        $appScheme = $parsedUrl['scheme'] ?? 'https';
        
        // Use environment variables or Herd-optimized defaults
        $reverbAppKey = env('REVERB_APP_KEY', 'laravel-herd');
        $reverbAppSecret = env('REVERB_APP_SECRET', 'secret');
        $reverbAppId = env('REVERB_APP_ID', '1001');

        $herdDefaults = [
            // Broadcasting Configuration - Herd optimized
            'broadcasting_default' => 'reverb',
            'broadcasting_connections.reverb.driver' => 'reverb',
            'broadcasting_connections.reverb.key' => $reverbAppKey,
            'broadcasting_connections.reverb.secret' => $reverbAppSecret,
            'broadcasting_connections.reverb.app_id' => $reverbAppId,
            'broadcasting_connections.reverb.options.host' => $appHost,
            'broadcasting_connections.reverb.options.port' => $appScheme === 'https' ? '8080' : '8080',
            'broadcasting_connections.reverb.options.scheme' => $appScheme,

            // Server Configuration - Herd compatible
            'reverb_default' => 'reverb',
            'reverb_servers.reverb.host' => '127.0.0.1',
            'reverb_servers.reverb.hostname' => $appHost,
            'reverb_servers.reverb.port' => '8080',
            'reverb_servers.reverb.max_request_size' => '10000',

            // Client Configuration - Herd frontend
            'reverb_apps.apps.0.options.host' => $appHost,
            'reverb_apps.apps.0.options.port' => '8080',
            'reverb_apps.apps.0.options.scheme' => $appScheme,

            // App Configuration - Herd values
            'reverb_apps.provider' => 'config',
            'reverb_apps.apps.0.key' => $reverbAppKey,
            'reverb_apps.apps.0.secret' => $reverbAppSecret,
            'reverb_apps.apps.0.app_id' => $reverbAppId,
            'reverb_apps.apps.0.allowed_origins' => '["*"]',
            'reverb_apps.apps.0.ping_interval' => '60',
            'reverb_apps.apps.0.max_message_size' => '250000',

            // VITE Frontend Configuration - Critical for client-side connection
            'vite_reverb_app_key' => $reverbAppKey,
            'vite_reverb_host' => $appHost,
            'vite_reverb_port' => '8080',
            'vite_reverb_scheme' => $appScheme,
            'vite_reverb_app_cluster' => env('VITE_REVERB_APP_CLUSTER', 'herd'),
        ];

        return $this->applyDefaults($herdDefaults, 'Laravel Herd');
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
