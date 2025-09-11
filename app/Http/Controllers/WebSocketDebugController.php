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
            'config_validation' => $this->validateConfiguration(),
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
        $wsScheme = $scheme === 'https' ? 'wss' : 'ws';
        
        return [
            'websocket_url' => "{$wsScheme}://{$host}:{$port}",
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
        // Use the actual broadcasting configuration values (not server config)
        $host = config('broadcasting.connections.reverb.options.host', 'localhost');
        $port = config('broadcasting.connections.reverb.options.port', '8080');
        $scheme = config('broadcasting.connections.reverb.options.scheme', 'http');
        $appId = config('broadcasting.connections.reverb.app_id', 'hawki');
        
        // Test both HTTP REST endpoint and WebSocket endpoint
        $httpUrl = "{$scheme}://{$host}:{$port}/app/{$appId}";
        $wsScheme = $scheme === 'https' ? 'wss' : 'ws';
        $wsUrl = "{$wsScheme}://{$host}:{$port}/app/{$appId}";
        
        $result = [
            'http_test' => $this->testHttpEndpoint($httpUrl),
            'websocket_test' => $this->testWebSocketEndpoint($wsUrl),
            'primary_url' => $wsUrl, // WebSocket is the primary functionality
        ];
        
        // Determine overall accessibility
        $result['accessible'] = $result['http_test']['accessible'] || $result['websocket_test']['accessible'];
        $result['url'] = $result['websocket_test']['accessible'] ? $wsUrl : $httpUrl;
        $result['response_time'] = $result['websocket_test']['accessible'] ? 
            $result['websocket_test']['response_time'] : $result['http_test']['response_time'];
        $result['error'] = $result['accessible'] ? null : 
            ($result['websocket_test']['error'] ?: $result['http_test']['error']);
        $result['http_code'] = $result['http_test']['http_code'] ?? null;
        
        return $result;
    }

    /**
     * Test HTTP/HTTPS endpoint (for REST API)
     */
    private function testHttpEndpoint(string $url): array
    {
        $result = [
            'url' => $url,
            'accessible' => false,
            'response_time' => null,
            'error' => null,
            'http_code' => null,
        ];
        
        $start = microtime(true);
        
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => 'HAWKI-WebSocket-Debug/1.0',
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            $result['response_time'] = round((microtime(true) - $start) * 1000, 2) . 'ms';
            $result['http_code'] = $httpCode;
            
            // Consider 200-299 and some 400s as accessible
            if ($response !== false && ($httpCode >= 200 && $httpCode < 500)) {
                $result['accessible'] = true;
                if ($response !== false && strlen($response) > 0) {
                    $result['response_preview'] = substr($response, 0, 200);
                }
            } else {
                if ($curlError) {
                    $result['error'] = $curlError;
                } else if ($httpCode) {
                    $result['error'] = "HTTP {$httpCode}";
                }
            }
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            $result['response_time'] = round((microtime(true) - $start) * 1000, 2) . 'ms';
        }
        
        return $result;
    }

    /**
     * Test WebSocket endpoint (primary Reverb functionality)
     */
    private function testWebSocketEndpoint(string $wsUrl): array
    {
        $result = [
            'url' => $wsUrl,
            'accessible' => false,
            'response_time' => null,
            'error' => null,
            'test_method' => 'socket_connection',
        ];
        
        $start = microtime(true);
        
        try {
            // Parse WebSocket URL
            $urlParts = parse_url($wsUrl);
            $host = $urlParts['host'] ?? 'localhost';
            $port = $urlParts['port'] ?? 8080;
            $isSecure = ($urlParts['scheme'] ?? 'ws') === 'wss';
            
            // Test socket connection (basic connectivity test)
            $errno = 0;
            $errstr = '';
            $timeout = 3;
            
            if ($isSecure) {
                // For WSS, try to create SSL context
                $context = stream_context_create([
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ]
                ]);
                $socket = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
            } else {
                // For WS, regular TCP connection
                $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
            }
            
            $result['response_time'] = round((microtime(true) - $start) * 1000, 2) . 'ms';
            
            if ($socket) {
                $result['accessible'] = true;
                $result['test_method'] = $isSecure ? 'ssl_socket' : 'tcp_socket';
                fclose($socket);
            } else {
                $result['error'] = $errstr ?: "Connection failed (errno: {$errno})";
            }
            
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            $result['response_time'] = round((microtime(true) - $start) * 1000, 2) . 'ms';
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
            'host' => config('broadcasting.connections.reverb.options.host', request()->getHost()),
            'port' => config('broadcasting.connections.reverb.options.port', '8080'),
            'scheme' => config('broadcasting.connections.reverb.options.scheme', request()->isSecure() ? 'https' : 'http'),
            'app_id' => config('broadcasting.connections.reverb.app_id', 'hawki'),
            'is_dynamic' => true, // Indicates runtime configuration
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
     * Validate WebSocket configuration for common errors
     */
    private function validateConfiguration(): array
    {
        $errors = [];
        $warnings = [];
        $info = [];

        // 1. Check if Broadcasting is properly configured
        $broadcastingDefault = config('broadcasting.default');
        if ($broadcastingDefault !== 'reverb') {
            $errors[] = "Broadcasting default driver is '{$broadcastingDefault}', should be 'reverb'";
        }

        // 2. Check Reverb connection configuration
        $reverbConnection = config('broadcasting.connections.reverb');
        if (empty($reverbConnection)) {
            $errors[] = "Reverb broadcasting connection is not configured";
        } else {
            // Check required reverb connection fields
            $requiredFields = ['driver', 'key', 'secret', 'app_id'];
            foreach ($requiredFields as $field) {
                if (empty($reverbConnection[$field])) {
                    $errors[] = "Reverb connection missing required field: {$field}";
                }
            }

            // Check reverb options
            $options = $reverbConnection['options'] ?? [];
            if (empty($options['host'])) {
                $errors[] = "Reverb connection missing host option";
            }
            if (empty($options['port'])) {
                $errors[] = "Reverb connection missing port option";
            }
            if (empty($options['scheme'])) {
                $warnings[] = "Reverb connection missing scheme option, defaulting to http";
            }
        }

        // 3. Check Reverb server configuration
        $reverbServers = config('reverb.servers.reverb');
        if (empty($reverbServers)) {
            $errors[] = "Reverb server configuration is missing";
        } else {
            if (empty($reverbServers['host'])) {
                $errors[] = "Reverb server missing host configuration";
            }
            if (empty($reverbServers['port'])) {
                $errors[] = "Reverb server missing port configuration";
            }
            if (empty($reverbServers['hostname'])) {
                $warnings[] = "Reverb server missing hostname configuration";
            }
        }

        // 4. Check Reverb apps configuration
        $reverbApps = config('reverb.apps.apps');
        if (empty($reverbApps) || !isset($reverbApps[0])) {
            $errors[] = "Reverb apps configuration is missing or empty";
        } else {
            $app = $reverbApps[0];
            $requiredAppFields = ['key', 'secret', 'app_id'];
            foreach ($requiredAppFields as $field) {
                if (empty($app[$field])) {
                    $errors[] = "Reverb app missing required field: {$field}";
                }
            }

            // Check app options
            if (empty($app['options']['host'])) {
                $errors[] = "Reverb app missing host option";
            }
            if (empty($app['options']['port'])) {
                $errors[] = "Reverb app missing port option";
            }
        }

        // 5. Check VITE configuration consistency
        $viteSettings = AppSetting::where('key', 'LIKE', 'vite_reverb_%')->get();
        if ($viteSettings->isEmpty()) {
            $warnings[] = "VITE Reverb configuration not found in database settings";
        } else {
            // Check if VITE values match Reverb values
            $reverbKey = $reverbConnection['key'] ?? '';
            $viteKey = $viteSettings->where('key', 'vite_reverb_app_key')->first()?->value ?? '';
            if ($reverbKey !== $viteKey) {
                $warnings[] = "VITE app key ('{$viteKey}') doesn't match Reverb app key ('{$reverbKey}')";
            }

            $reverbHost = $reverbConnection['options']['host'] ?? '';
            $viteHost = $viteSettings->where('key', 'vite_reverb_host')->first()?->value ?? '';
            if ($reverbHost !== $viteHost) {
                $warnings[] = "VITE host ('{$viteHost}') doesn't match Reverb host ('{$reverbHost}')";
            }

            $reverbPort = (string)($reverbConnection['options']['port'] ?? '');
            $vitePort = (string)($viteSettings->where('key', 'vite_reverb_port')->first()?->value ?? '');
            if ($reverbPort !== '' && $vitePort !== '' && $reverbPort !== $vitePort) {
                $warnings[] = "VITE port ('{$vitePort}') doesn't match Reverb port ('{$reverbPort}')";
            }

            $reverbScheme = $reverbConnection['options']['scheme'] ?? '';
            $viteScheme = $viteSettings->where('key', 'vite_reverb_scheme')->first()?->value ?? '';
            if ($reverbScheme !== $viteScheme) {
                $warnings[] = "VITE scheme ('{$viteScheme}') doesn't match Reverb scheme ('{$reverbScheme}')";
            }
        }

        // 6. Check if Reverb server is running
        $serverTest = $this->testReverbConnection();
        if (!$serverTest['accessible']) {
            $errors[] = "Reverb server is not accessible at tested URLs";
            $info[] = "Start Reverb server with: php artisan reverb:start";
            if (!empty($serverTest['tested_urls'])) {
                $info[] = "Tested URLs: " . implode(', ', array_column($serverTest['tested_urls'], 'url'));
            }
        } else {
            $info[] = "Reverb server is running and accessible at: " . $serverTest['url'];
        }

        // 7. Check URL and scheme consistency
        $appUrl = config('app.url');
        $parsedUrl = parse_url($appUrl);
        $appScheme = $parsedUrl['scheme'] ?? 'http';
        $reverbScheme = $reverbConnection['options']['scheme'] ?? 'http';
        
        if ($appScheme === 'https' && $reverbScheme !== 'https') {
            $warnings[] = "App URL uses HTTPS but Reverb is configured for HTTP - this may cause Mixed Content errors";
        }

        // 8. Check environment variables
        $envVars = ['REVERB_APP_KEY', 'REVERB_APP_SECRET', 'REVERB_APP_ID', 'REVERB_HOST', 'REVERB_PORT'];
        $missingEnvVars = [];
        foreach ($envVars as $var) {
            if (empty(env($var))) {
                $missingEnvVars[] = $var;
            }
        }
        if (!empty($missingEnvVars)) {
            $warnings[] = "Missing environment variables: " . implode(', ', $missingEnvVars);
        }

        // 9. Check ports
        $reverbPort = $reverbConnection['options']['port'] ?? '8080';
        if ($reverbScheme === 'https' && $reverbPort === '8080') {
            $warnings[] = "Using port 8080 with HTTPS scheme - consider using port 443 for production";
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'info' => $info,
            'status' => empty($errors) ? (empty($warnings) ? 'perfect' : 'good') : 'problematic',
            'total_issues' => count($errors) + count($warnings),
        ];
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
            'app_id' => config('broadcasting.connections.reverb.app_id'),
            'wsHost' => config('broadcasting.connections.reverb.options.host'),
            'wsPort' => config('broadcasting.connections.reverb.options.port'),
            'wssPort' => config('broadcasting.connections.reverb.options.port'),
            'forceTLS' => config('broadcasting.connections.reverb.options.scheme') === 'https',
        ];
        
        return response()->json([
            'config' => $config,
            'test_url' => $config['forceTLS'] ? 
                "wss://{$config['wsHost']}:{$config['wssPort']}/app/{$config['app_id']}" :
                "ws://{$config['wsHost']}:{$config['wsPort']}/app/{$config['app_id']}",
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * API endpoint for configuration validation
     */
    public function validateConfigurationEndpoint()
    {
        $validation = $this->validateConfiguration();
        
        return response()->json([
            'validation' => $validation,
            'timestamp' => now()->toISOString(),
            'recommendations' => $this->getConfigurationRecommendations($validation),
        ]);
    }

    /**
     * Get configuration recommendations based on validation results
     */
    private function getConfigurationRecommendations(array $validation): array
    {
        $recommendations = [];

        if (in_array('perfect', [$validation['status']])) {
            $recommendations[] = "✅ Configuration is perfect! No issues found.";
            return $recommendations;
        }

        // Recommendations for errors
        foreach ($validation['errors'] as $error) {
            if (str_contains($error, 'Broadcasting default driver')) {
                $recommendations[] = "🔧 Set BROADCAST_DRIVER=reverb in your .env file";
            }
            if (str_contains($error, 'Reverb server is not running')) {
                $recommendations[] = "🚀 Start Reverb server: php artisan reverb:start";
            }
            if (str_contains($error, 'missing required field')) {
                $recommendations[] = "⚙️ Configure missing Reverb settings in the admin panel";
            }
        }

        // Recommendations for warnings
        foreach ($validation['warnings'] as $warning) {
            if (str_contains($warning, 'VITE') && str_contains($warning, "doesn't match")) {
                $recommendations[] = "🔄 Use the preset buttons in admin panel to sync VITE and Reverb settings";
            }
            if (str_contains($warning, 'HTTPS but Reverb is configured for HTTP')) {
                $recommendations[] = "🔒 Use HTTPS preset in admin panel for SSL websites";
            }
            if (str_contains($warning, 'Missing environment variables')) {
                $recommendations[] = "📝 Add missing environment variables to your .env file";
            }
            if (str_contains($warning, 'port 8080 with HTTPS')) {
                $recommendations[] = "🌐 Consider using port 443 for production HTTPS";
            }
        }

        if (empty($recommendations)) {
            $recommendations[] = "📋 Review the validation details above and fix any highlighted issues";
        }

        return $recommendations;
    }
}
