<!DOCTYPE html>
<html class="lightMode">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    
    <title>WebSocket Debug - {{ config('app.name') }}</title>
    
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <!-- Removed problematic CSS files that might interfere with scrolling -->
    <!-- <link rel="stylesheet" href="{{ asset('css_v2.0.1_f1/style.css') }}"> -->
    <!-- <link rel="stylesheet" href="{{ asset('css_v2.0.1_f1/home-style.css') }}"> -->
    
    <!-- Bootstrap for better styling -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <!-- Vite Assets for Laravel Echo -->
    @vite('resources/js/app.js')
    
    <style>
        /* Ensure body and html can scroll properly */
        html, body {
            height: auto !important;
            min-height: 100vh;
            overflow-x: auto !important;
            overflow-y: auto !important;
            scroll-behavior: smooth;
        }
        
        /* Container improvements */
        .container-fluid {
            min-height: 100vh;
            overflow: visible !important;
        }
        
        /* Card and content styling */
        .badge { font-size: 0.75em; }
        .table-responsive { 
            max-height: 400px; 
            overflow-y: auto; 
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
        }
        code { 
            background-color: #f8f9fa; 
            padding: 2px 4px; 
            border-radius: 3px; 
            word-break: break-all;
        }
        .status-card { border-left: 4px solid #28a745; }
        .status-card.warning { border-left-color: #ffc107; }
        .status-card.danger { border-left-color: #dc3545; }
        pre { 
            max-height: 200px; 
            overflow-y: auto; 
            font-size: 0.85em; 
            white-space: pre-wrap;
            word-break: break-word;
        }
        
        /* Responsive improvements */
        @media (max-width: 768px) {
            .table-responsive {
                font-size: 0.875rem;
            }
            
            .btn {
                margin-bottom: 0.5rem;
            }
        }
        
        /* Fix potential height conflicts */
        .card {
            height: auto !important;
        }
        
        .h-100 {
            height: auto !important;
            min-height: 300px;
        }
    </style>
</head>
<body>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0">WebSocket Debug Information</h1>
                <div>
                    <button class="btn btn-outline-primary me-2" onclick="clearCaches()">
                        <i class="fas fa-trash"></i> Clear Caches
                    </button>
                    <button class="btn btn-primary me-2" onclick="testConnection()">
                        <i class="fas fa-plug"></i> Test Connection
                    </button>
                    <button class="btn btn-outline-success me-2" onclick="if(typeof testEchoConnection !== 'undefined') testEchoConnection(); else alert('Laravel Echo not loaded yet');">
                        <i class="fas fa-broadcast-tower"></i> Test Echo
                    </button>
                    <button class="btn btn-secondary" onclick="location.reload()">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Overview -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card status-card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-info-circle"></i> System Status</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="text-center">
                                @php
                                    $serverRunning = $debugInfo['configuration']['reverb_server_running'] ?? false;
                                @endphp
                                <div class="badge {{ $serverRunning ? 'bg-success' : 'bg-danger' }} fs-6 p-2">
                                    {{ $serverRunning ? 'ONLINE' : 'OFFLINE' }}
                                </div>
                                <p class="mt-2 mb-0 small">Reverb Server</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                @php
                                    $broadcastingDriver = $debugInfo['configuration']['broadcasting_driver'] ?? 'NONE';
                                @endphp
                                <div class="badge {{ $broadcastingDriver === 'reverb' ? 'bg-success' : 'bg-warning' }} fs-6 p-2">
                                    {{ strtoupper($broadcastingDriver) }}
                                </div>
                                <p class="mt-2 mb-0 small">Broadcasting Driver</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                @php
                                    $echoReady = $debugInfo['connection_test']['laravel_echo_ready']['echo_import_found'] ?? false;
                                @endphp
                                <div class="badge {{ $echoReady ? 'bg-success' : 'bg-warning' }} fs-6 p-2">
                                    {{ $echoReady ? 'READY' : 'MISSING' }}
                                </div>
                                <p class="mt-2 mb-0 small">Laravel Echo</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                @php
                                    $appEnv = $debugInfo['configuration']['app_env'] ?? 'unknown';
                                @endphp
                                <div class="badge {{ $appEnv === 'production' ? 'bg-success' : 'bg-info' }} fs-6 p-2">
                                    {{ strtoupper($appEnv) }}
                                </div>
                                <p class="mt-2 mb-0 small">Environment</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Configuration Details -->
        <div class="col-md-6 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-cog"></i> Configuration</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        @foreach(($debugInfo['configuration'] ?? []) as $key => $value)
                        <tr>
                            <td class="fw-bold">{{ str_replace('_', ' ', ucfirst($key)) }}</td>
                            <td>
                                @if(is_bool($value))
                                    <span class="badge {{ $value ? 'bg-success' : 'bg-danger' }}">
                                        {{ $value ? 'YES' : 'NO' }}
                                    </span>
                                @elseif(is_array($value))
                                    <code>{{ json_encode($value) }}</code>
                                @else
                                    <code>{{ $value }}</code>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </table>
                </div>
            </div>
        </div>

        <!-- Reverb Server Status -->
        <div class="col-md-6 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-server"></i> Reverb Server</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <strong>Server Connection Test:</strong>
                        <div class="mt-2">
                            @php
                                $serverAccessible = $debugInfo['reverb_status']['server_accessible'] ?? [];
                            @endphp
                            <p class="mb-1"><strong>URL:</strong> <code>{{ $serverAccessible['url'] ?? 'N/A' }}</code></p>
                            <p class="mb-1">
                                <strong>Status:</strong> 
                                <span class="badge {{ ($serverAccessible['accessible'] ?? false) ? 'bg-success' : 'bg-danger' }}">
                                    {{ ($serverAccessible['accessible'] ?? false) ? 'ACCESSIBLE' : 'NOT ACCESSIBLE' }}
                                </span>
                            </p>
                            @if(isset($serverAccessible['response_time']))
                            <p class="mb-1"><strong>Response Time:</strong> {{ $serverAccessible['response_time'] }}</p>
                            @endif
                            @if(isset($serverAccessible['error']))
                            <p class="mb-1 text-danger"><strong>Error:</strong> {{ $serverAccessible['error'] }}</p>
                            @endif
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <strong>Server Configuration:</strong>
                        <table class="table table-sm mt-2">
                            @foreach(($debugInfo['reverb_status']['reverb_servers']['reverb'] ?? []) as $key => $value)
                            <tr>
                                <td>{{ ucfirst(str_replace('_', ' ', $key)) }}</td>
                                <td><code>{{ is_array($value) ? json_encode($value) : $value }}</code></td>
                            </tr>
                            @endforeach
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Broadcasting Configuration -->
        <div class="col-md-6 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-broadcast-tower"></i> Broadcasting</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <strong>Driver:</strong> <code>{{ $debugInfo['broadcasting_config']['default_driver'] ?? 'N/A' }}</code>
                    </div>
                    
                    @if(isset($debugInfo['broadcasting_config']['reverb_connection']))
                    <div class="mb-3">
                        <strong>Reverb Connection:</strong>
                        <table class="table table-sm mt-2">
                            @foreach($debugInfo['broadcasting_config']['reverb_connection'] as $key => $value)
                            @if($key !== 'options')
                            <tr>
                                <td>{{ ucfirst($key) }}</td>
                                <td><code>{{ is_array($value) ? json_encode($value) : $value }}</code></td>
                            </tr>
                            @endif
                            @endforeach
                        </table>
                    </div>
                    
                    @if(isset($debugInfo['broadcasting_config']['reverb_connection']['options']))
                    <div class="mb-3">
                        <strong>Connection Options:</strong>
                        <table class="table table-sm mt-2">
                            @foreach($debugInfo['broadcasting_config']['reverb_connection']['options'] as $key => $value)
                            <tr>
                                <td>{{ ucfirst($key) }}</td>
                                <td><code>{{ is_array($value) ? json_encode($value) : $value }}</code></td>
                            </tr>
                            @endforeach
                        </table>
                    </div>
                    @endif
                    @endif
                    
                    <div class="mb-3">
                        <strong>Pusher Fallback:</strong>
                        <span class="badge {{ ($debugInfo['broadcasting_config']['pusher_fallback'] ?? false) ? 'bg-warning' : 'bg-success' }}">
                            {{ ($debugInfo['broadcasting_config']['pusher_fallback'] ?? false) ? 'AVAILABLE' : 'NOT CONFIGURED' }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Connection Test -->
        <div class="col-md-6 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-plug"></i> Connection Test</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <strong>WebSocket URL:</strong><br>
                        <code>{{ $debugInfo['connection_test']['websocket_url'] ?? 'N/A' }}</code>
                    </div>
                    
                    <div class="mb-3">
                        <strong>App Configuration:</strong>
                        <table class="table table-sm mt-2">
                            <tr>
                                <td>App Key</td>
                                <td><code>{{ $debugInfo['connection_test']['app_key'] ?? 'N/A' }}</code></td>
                            </tr>
                            <tr>
                                <td>App ID</td>
                                <td><code>{{ $debugInfo['connection_test']['app_id'] ?? 'N/A' }}</code></td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="mb-3">
                        <strong>Frontend Dependencies:</strong>
                        <table class="table table-sm mt-2">
                            @foreach(($debugInfo['connection_test']['js_dependencies'] ?? []) as $dep => $version)
                            <tr>
                                <td>{{ $dep }}</td>
                                <td>
                                    @if($version === 'Not installed')
                                        <span class="badge bg-danger">{{ $version }}</span>
                                    @else
                                        <code>{{ $version }}</code>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </table>
                    </div>
                    
                    <button class="btn btn-primary btn-sm" onclick="testWebSocketConnection()">
                        <i class="fas fa-play"></i> Test WebSocket Connection
                    </button>
                    <div id="websocket-test-result" class="mt-2"></div>
                </div>
            </div>
        </div>

        <!-- Database Settings -->
        <div class="col-12 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-database"></i> Database WebSocket Settings ({{ count($debugInfo['websocket_settings'] ?? []) }} total)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-sm">
                            <thead>
                                <tr>
                                    <th>Key</th>
                                    <th>Value</th>
                                    <th>Type</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(($debugInfo['websocket_settings'] ?? []) as $key => $setting)
                                <tr>
                                    <td><code>{{ $key }}</code></td>
                                    <td>
                                        @php
                                            $value = is_array($setting) && isset($setting['value']) ? $setting['value'] : $setting;
                                            $displayValue = is_array($value) ? json_encode($value) : $value;
                                        @endphp
                                        @if(strlen($displayValue) > 50)
                                            <span class="d-inline-block text-truncate" style="max-width: 200px;" 
                                                  title="{{ $displayValue }}">{{ $displayValue }}</span>
                                        @else
                                            <code>{{ $displayValue }}</code>
                                        @endif
                                    </td>
                                    <td>
                                        @php
                                            $type = (is_array($setting) && isset($setting['type'])) ? $setting['type'] : 'mixed';
                                        @endphp
                                        <span class="badge bg-secondary">{{ $type }}</span>
                                    </td>
                                    <td class="small">
                                        @php
                                            $description = (is_array($setting) && isset($setting['description'])) ? $setting['description'] : '-';
                                        @endphp
                                        {{ $description }}
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted">No WebSocket settings found in database</td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Clear caches function
async function clearCaches() {
    try {
        const response = await fetch('/debug/websocket/clear-caches', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            }
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('✅ ' + result.message);
            location.reload();
        } else {
            alert('❌ ' + result.message);
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
    }
}

// Test connection function
async function testConnection() {
    try {
        const response = await fetch('/debug/websocket/test-connection', {
            headers: {
                'Accept': 'application/json',
            }
        });
        
        const result = await response.json();
        
        const info = `WebSocket Configuration Test:
        
🔧 Configuration:
- Broadcaster: ${result.config.broadcaster}
- Key: ${result.config.key}
- Host: ${result.config.wsHost}
- Port: ${result.config.wsPort}
- Force TLS: ${result.config.forceTLS}

🌐 Test URL: ${result.test_url}
⏰ Timestamp: ${result.timestamp}

Try connecting using the browser console:
1. Open Developer Tools (F12)
2. Go to Console tab
3. Run: testWebSocketConnection()`;
        
        alert(info);
    } catch (error) {
        alert('❌ Error testing connection: ' + error.message);
    }
}

// WebSocket connection test in browser
function testWebSocketConnection() {
    const resultDiv = document.getElementById('websocket-test-result');
    resultDiv.innerHTML = '<div class="alert alert-info">Testing WebSocket connection...</div>';
    
    fetch('/debug/websocket/test-connection')
        .then(response => response.json())
        .then(data => {
            const testUrl = data.test_url;
            const socket = new WebSocket(testUrl);
            
            const startTime = Date.now();
            let connected = false;
            
            socket.onopen = function(event) {
                connected = true;
                const responseTime = Date.now() - startTime;
                resultDiv.innerHTML = `
                    <div class="alert alert-success">
                        <strong>✅ WebSocket Connection Successful!</strong><br>
                        <small>Response time: ${responseTime}ms</small><br>
                        <small>URL: ${testUrl}</small>
                    </div>
                `;
                socket.close();
            };
            
            socket.onerror = function(error) {
                resultDiv.innerHTML = `
                    <div class="alert alert-danger">
                        <strong>❌ WebSocket Connection Failed!</strong><br>
                        <small>URL: ${testUrl}</small><br>
                        <small>Error: ${error.message || 'Connection failed'}</small>
                    </div>
                `;
            };
            
            socket.onclose = function(event) {
                if (!connected) {
                    resultDiv.innerHTML = `
                        <div class="alert alert-warning">
                            <strong>⚠️ WebSocket Connection Closed</strong><br>
                            <small>Code: ${event.code}</small><br>
                            <small>Reason: ${event.reason || 'Unknown'}</small><br>
                            <small>URL: ${testUrl}</small>
                        </div>
                    `;
                }
            };
            
            // Timeout after 5 seconds
            setTimeout(() => {
                if (!connected) {
                    socket.close();
                    resultDiv.innerHTML = `
                        <div class="alert alert-danger">
                            <strong>⏱️ WebSocket Connection Timeout</strong><br>
                            <small>Connection timed out after 5 seconds</small><br>
                            <small>URL: ${testUrl}</small>
                        </div>
                    `;
                }
            }, 5000);
        })
        .catch(error => {
            resultDiv.innerHTML = `
                <div class="alert alert-danger">
                    <strong>❌ Test Setup Failed!</strong><br>
                    <small>Error: ${error.message}</small>
                </div>
            `;
        });
}

// Load debug utilities
const script = document.createElement('script');
script.src = '{{ asset("js_v2.0.1_f1/websocket_debug.js") }}';
document.head.appendChild(script);

// Wait for Laravel Echo to be available
function waitForEcho(callback, maxAttempts = 50) {
    let attempts = 0;
    const interval = setInterval(() => {
        attempts++;
        if (typeof window.Echo !== 'undefined') {
            clearInterval(interval);
            console.log('✅ Laravel Echo is now available!');
            callback();
        } else if (attempts >= maxAttempts) {
            clearInterval(interval);
            console.warn('⚠️ Laravel Echo not available after', maxAttempts, 'attempts');
            console.log('Debug: Available globals:', Object.keys(window).filter(k => k.includes('Echo') || k.includes('Pusher')));
        }
    }, 100);
}

// Test Echo functionality when it becomes available
waitForEcho(() => {
    console.log('🚀 Laravel Echo loaded successfully!');
    console.log('📊 Echo Configuration:', {
        connector: window.Echo.connector,
        options: window.Echo.options
    });
    
    // Add Echo test button functionality
    window.testEchoConnection = function() {
        console.log('🧪 Testing Laravel Echo connection...');
        
        try {
            // Test basic channel subscription
            const testChannel = window.Echo.channel('debug-test-channel');
            
            testChannel.subscribed(() => {
                console.log('✅ Successfully subscribed to debug test channel');
                alert('✅ Laravel Echo connection successful!');
            });
            
            testChannel.error((error) => {
                console.error('❌ Laravel Echo connection error:', error);
                alert('❌ Laravel Echo connection failed: ' + JSON.stringify(error));
            });
            
            // Cleanup after 5 seconds
            setTimeout(() => {
                testChannel.stopListening();
                console.log('🧹 Test channel cleaned up');
            }, 5000);
            
        } catch (error) {
            console.error('❌ Laravel Echo test failed:', error);
            alert('❌ Laravel Echo test failed: ' + error.message);
        }
    };
});

// Auto-refresh status every 30 seconds
setInterval(() => {
    // Only refresh if the page is visible
    if (!document.hidden) {
        fetch(window.location.href, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.text())
        .then(html => {
            // Update status badges only
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newBadges = doc.querySelectorAll('.badge');
            const currentBadges = document.querySelectorAll('.badge');
            
            newBadges.forEach((newBadge, index) => {
                if (currentBadges[index]) {
                    currentBadges[index].className = newBadge.className;
                    currentBadges[index].textContent = newBadge.textContent;
                }
            });
        })
        .catch(error => console.log('Auto-refresh failed:', error));
    }
}, 30000);
</script>
</body>
</html>
