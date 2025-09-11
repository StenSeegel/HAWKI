<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;

class WebSocketDebugController extends Controller
{
    /**
     * Display WebSocket debug information
     */
    public function index()
    {
        $debugInfo = [
            'configuration' => $this->getConfigurationInfo(),
            'reverb_status' => $this->getReverbStatus(),
            'broadcasting_config' => $this->getBroadcastingConfig(),
            'websocket_settings' => $this->getWebSocketSettings(),
            'connection_test' => $this->getConnectionTestInfo(),
        ];

        return view('debug.websocket', compact('debugInfo'));
    }

    /**
     * Get general configuration information
     */
    private function getConfigurationInfo(): array
    {
        return [
            'app_env' => config('app.env'),
            'app_debug' => config('app.debug'),
            'app_url' => config('app.url'),
            'broadcasting_driver' => config('broadcasting.default'),
            'reverb_server_running' => $this->checkReverbServerStatus(),
            'cache_driver' => config('cache.default'),
            'session_driver' => config('session.driver'),
        ];
    }

    /**
     * Get Reverb server status
     */
    private function getReverbStatus(): array
    {
        $reverbConfig = config('reverb');
        
        return [
            'reverb_default' => $reverbConfig['default'] ?? 'Not set',
            'reverb_servers' => $reverbConfig['servers'] ?? [],
            'reverb_apps' => $reverbConfig['apps'] ?? [],
            'reverb_ping_interval' => $reverbConfig['apps']['apps'][0]['ping_interval'] ?? 'Not set',
            'reverb_max_message_size' => $reverbConfig['apps']['apps'][0]['max_message_size'] ?? 'Not set',
            'server_accessible' => $this->testReverbConnection(),
        ];
    }

    /**
     * Get Broadcasting configuration
     */
    private function getBroadcastingConfig(): array
    {
        $broadcastingConfig = config('broadcasting');
        
        return [
            'default_driver' => $broadcastingConfig['default'] ?? 'Not set',
            'reverb_connection' => $broadcastingConfig['connections']['reverb'] ?? [],
            'pusher_fallback' => isset($broadcastingConfig['connections']['pusher']),
            'echo_config' => $this->getEchoConfig(),
        ];
    }

    /**
     * Get WebSocket settings from database
     */
    private function getWebSocketSettings(): array
    {
        $settings = AppSetting::where('group', 'websockets')->get();
        $settingsArray = [];
        
        foreach ($settings as $setting) {
            $settingsArray[$setting->key] = [
                'value' => $setting->value,
                'type' => $setting->type,
                'description' => $setting->description,
            ];
        }
        
        return $settingsArray;
    }

    /**
     * Get connection test information
     */
    private function getConnectionTestInfo(): array
    {
        $host = config('broadcasting.connections.reverb.options.host', 'localhost');
        $port = config('broadcasting.connections.reverb.options.port', '8080');
        $scheme = config('broadcasting.connections.reverb.options.scheme', 'http');
        
        return [
            'websocket_url' => "{$scheme}://{$host}:{$port}",
            'app_key' => config('broadcasting.connections.reverb.key'),
            'app_id' => config('broadcasting.connections.reverb.app_id'),
            'laravel_echo_ready' => $this->checkLaravelEchoSetup(),
            'js_dependencies' => $this->checkJsDependencies(),
        ];
    }

    /**
     * Check if Reverb server is running
     */
    private function checkReverbServerStatus(): bool
    {
        $host = config('reverb.servers.reverb.hostname', 'localhost');
        $port = config('reverb.servers.reverb.port', '8080');
        
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);
        if ($connection) {
            fclose($connection);
            return true;
        }
        
        return false;
    }

    /**
     * Test Reverb connection
     */
    private function testReverbConnection(): array
    {
        $host = config('reverb.servers.reverb.hostname', 'localhost');
        $port = config('reverb.servers.reverb.port', '8080');
        $scheme = config('broadcasting.connections.reverb.options.scheme', 'http');
        
        $url = "{$scheme}://{$host}:{$port}/app/" . config('broadcasting.connections.reverb.app_id', 'hawki');
        
        $result = [
            'url' => $url,
            'accessible' => false,
            'response_time' => null,
            'error' => null,
        ];
        
        $start = microtime(true);
        
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'method' => 'GET',
                ]
            ]);
            
            $response = @file_get_contents($url, false, $context);
            $result['accessible'] = $response !== false;
            $result['response_time'] = round((microtime(true) - $start) * 1000, 2) . 'ms';
            
            if ($response !== false) {
                $result['response_preview'] = substr($response, 0, 200);
            }
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }

    /**
     * Get Echo configuration for frontend
     */
    private function getEchoConfig(): array
    {
        return [
            'broadcaster' => 'reverb',
            'key' => config('broadcasting.connections.reverb.key'),
            'wsHost' => config('broadcasting.connections.reverb.options.host'),
            'wsPort' => config('broadcasting.connections.reverb.options.port'),
            'wssPort' => config('broadcasting.connections.reverb.options.port'),
            'forceTLS' => config('broadcasting.connections.reverb.options.scheme') === 'https',
            'enabledTransports' => ['ws', 'wss'],
        ];
    }

    /**
     * Check Laravel Echo setup in frontend
     */
    private function checkLaravelEchoSetup(): array
    {
        $jsFiles = [
            'public/js_v2.0.1_f1/groupchat_functions.js',
            'public/js_v2.0.1_f1/ai_chat_functions.js',
            'resources/js/bootstrap.js',
        ];
        
        $result = [
            'echo_import_found' => false,
            'reverb_config_found' => false,
            'files_checked' => [],
        ];
        
        foreach ($jsFiles as $file) {
            $filePath = base_path($file);
            if (file_exists($filePath)) {
                $content = file_get_contents($filePath);
                $result['files_checked'][] = $file;
                
                if (strpos($content, 'laravel-echo') !== false || strpos($content, 'Echo') !== false) {
                    $result['echo_import_found'] = true;
                }
                
                if (strpos($content, 'reverb') !== false || strpos($content, 'broadcaster') !== false) {
                    $result['reverb_config_found'] = true;
                }
            }
        }
        
        return $result;
    }

    /**
     * Check JavaScript dependencies
     */
    private function checkJsDependencies(): array
    {
        $packageJsonPath = base_path('package.json');
        $dependencies = [];
        
        if (file_exists($packageJsonPath)) {
            $packageJson = json_decode(file_get_contents($packageJsonPath), true);
            $allDeps = array_merge(
                $packageJson['dependencies'] ?? [],
                $packageJson['devDependencies'] ?? []
            );
            
            $dependencies = [
                'laravel-echo' => $allDeps['laravel-echo'] ?? 'Not installed',
                'pusher-js' => $allDeps['pusher-js'] ?? 'Not installed',
                'vite' => $allDeps['vite'] ?? 'Not installed',
                'axios' => $allDeps['axios'] ?? 'Not installed',
            ];
        }
        
        return $dependencies;
    }

    /**
     * Clear all caches and reload configuration
     */
    public function clearCaches()
    {
        try {
            Artisan::call('config:clear');
            Artisan::call('cache:clear');
            Artisan::call('view:clear');
            Cache::flush();
            
            // Reload configuration
            \App\Providers\ConfigServiceProvider::clearConfigCache();
            
            return response()->json([
                'success' => true,
                'message' => 'All caches cleared and configuration reloaded successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error clearing caches: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test WebSocket connection from browser
     */
    public function testConnection()
    {
        $config = [
            'broadcaster' => 'reverb',
            'key' => config('broadcasting.connections.reverb.key'),
            'wsHost' => config('broadcasting.connections.reverb.options.host'),
            'wsPort' => config('broadcasting.connections.reverb.options.port'),
            'wssPort' => config('broadcasting.connections.reverb.options.port'),
            'forceTLS' => config('broadcasting.connections.reverb.options.scheme') === 'https',
        ];
        
        return response()->json([
            'config' => $config,
            'test_url' => $config['forceTLS'] ? 
                "wss://{$config['wsHost']}:{$config['wssPort']}/app/{$config['key']}" :
                "ws://{$config['wsHost']}:{$config['wsPort']}/app/{$config['key']}",
            'timestamp' => now()->toISOString(),
        ]);
    }
}
