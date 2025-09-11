// HAWKI WebSocket Debug Utilities
// Copy this script to browser console for advanced WebSocket testing

window.HAWKIWebSocketDebug = {
    
    // Configuration
    config: null,
    socket: null,
    echo: null,
    
    // Initialize debug utilities
    async init() {
        console.log('🔧 Initializing HAWKI WebSocket Debug...');
        
        try {
            const response = await fetch('/debug/websocket/test-connection');
            this.config = await response.json();
            console.log('✅ Configuration loaded:', this.config);
            return true;
        } catch (error) {
            console.error('❌ Failed to load configuration:', error);
            return false;
        }
    },
    
    // Test basic WebSocket connection
    async testBasicConnection() {
        if (!this.config) {
            console.log('⚠️ Please run HAWKIWebSocketDebug.init() first');
            return;
        }
        
        console.log('🔌 Testing basic WebSocket connection...');
        const url = this.config.test_url;
        
        return new Promise((resolve, reject) => {
            const socket = new WebSocket(url);
            const startTime = Date.now();
            
            socket.onopen = (event) => {
                const responseTime = Date.now() - startTime;
                console.log(`✅ WebSocket connected successfully!`);
                console.log(`⏱️ Response time: ${responseTime}ms`);
                console.log(`🌐 URL: ${url}`);
                socket.close();
                resolve({ success: true, responseTime, url });
            };
            
            socket.onerror = (error) => {
                console.error('❌ WebSocket connection failed:', error);
                reject({ success: false, error, url });
            };
            
            socket.onclose = (event) => {
                console.log(`🔌 WebSocket closed. Code: ${event.code}, Reason: ${event.reason}`);
            };
            
            // Timeout after 10 seconds
            setTimeout(() => {
                socket.close();
                reject({ success: false, error: 'Timeout', url });
            }, 10000);
        });
    },
    
    // Test Laravel Echo connection
    testEchoConnection() {
        console.log('📡 Testing Laravel Echo connection...');
        
        if (typeof Echo === 'undefined') {
            console.error('❌ Laravel Echo not found. Make sure it\'s imported.');
            return;
        }
        
        try {
            // Try to connect to a test channel
            const channel = Echo.channel('test-channel');
            
            channel.subscribed(() => {
                console.log('✅ Laravel Echo connected successfully!');
                console.log('📺 Subscribed to test-channel');
            });
            
            channel.error((error) => {
                console.error('❌ Laravel Echo connection error:', error);
            });
            
            // Listen for test events
            channel.listen('.test-event', (data) => {
                console.log('📨 Received test event:', data);
            });
            
            this.echo = channel;
            
        } catch (error) {
            console.error('❌ Laravel Echo test failed:', error);
        }
    },
    
    // Test private channel authentication
    testPrivateChannel(channelName = 'test-private-channel') {
        console.log(`🔐 Testing private channel: ${channelName}`);
        
        if (typeof Echo === 'undefined') {
            console.error('❌ Laravel Echo not found');
            return;
        }
        
        try {
            const channel = Echo.private(channelName);
            
            channel.subscribed(() => {
                console.log(`✅ Successfully subscribed to private channel: ${channelName}`);
            });
            
            channel.error((error) => {
                console.error(`❌ Private channel subscription failed: ${channelName}`, error);
            });
            
        } catch (error) {
            console.error('❌ Private channel test failed:', error);
        }
    },
    
    // Monitor connection status
    monitorConnection() {
        console.log('📊 Starting connection monitoring...');
        
        if (typeof Echo === 'undefined') {
            console.error('❌ Laravel Echo not found');
            return;
        }
        
        const connector = Echo.connector;
        
        if (connector && connector.pusher) {
            const pusher = connector.pusher;
            
            pusher.connection.bind('connected', () => {
                console.log('✅ Pusher/Reverb connected');
            });
            
            pusher.connection.bind('disconnected', () => {
                console.log('🔌 Pusher/Reverb disconnected');
            });
            
            pusher.connection.bind('error', (error) => {
                console.error('❌ Pusher/Reverb error:', error);
            });
            
            pusher.connection.bind('state_change', (states) => {
                console.log(`🔄 Connection state changed: ${states.previous} → ${states.current}`);
            });
            
            console.log('📊 Connection monitoring active. Current state:', pusher.connection.state);
        } else {
            console.error('❌ Pusher/Reverb connector not found');
        }
    },
    
    // Send test broadcast
    async sendTestBroadcast(message = 'Test message') {
        console.log('📤 Sending test broadcast...');
        
        try {
            const response = await fetch('/debug/websocket/send-test', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
                },
                body: JSON.stringify({ message })
            });
            
            if (response.ok) {
                console.log('✅ Test broadcast sent successfully');
            } else {
                console.error('❌ Failed to send test broadcast:', response.statusText);
            }
        } catch (error) {
            console.error('❌ Test broadcast error:', error);
        }
    },
    
    // Get detailed connection info
    getConnectionInfo() {
        console.log('📋 Connection Information:');
        
        if (this.config) {
            console.log('🔧 Configuration:', this.config);
        }
        
        if (typeof Echo !== 'undefined' && Echo.connector) {
            const connector = Echo.connector;
            console.log('📡 Echo Connector:', connector);
            
            if (connector.pusher) {
                const pusher = connector.pusher;
                console.log('🔌 Pusher/Reverb State:', pusher.connection.state);
                console.log('🆔 Socket ID:', pusher.connection.socket_id);
                console.log('⚙️ Options:', pusher.config);
            }
        }
        
        // Check for active channels
        if (typeof Echo !== 'undefined' && Echo.connector && Echo.connector.channels) {
            console.log('📺 Active Channels:', Object.keys(Echo.connector.channels));
        }
    },
    
    // Test room-specific functionality
    testRoomFunctionality(roomSlug = 'test-room') {
        console.log(`🏠 Testing room functionality for: ${roomSlug}`);
        
        if (typeof Echo === 'undefined') {
            console.error('❌ Laravel Echo not found');
            return;
        }
        
        try {
            // Subscribe to room channel
            const roomChannel = Echo.private(`Rooms.${roomSlug}`);
            
            roomChannel.subscribed(() => {
                console.log(`✅ Subscribed to room channel: Rooms.${roomSlug}`);
            });
            
            // Listen for room messages
            roomChannel.listen('RoomMessageEvent', (data) => {
                console.log('📨 Room message received:', data);
            });
            
            // Listen for typing indicators
            roomChannel.listenForWhisper('typing', (data) => {
                console.log('⌨️ Typing indicator:', data);
            });
            
            // Test whisper (typing indicator)
            setTimeout(() => {
                roomChannel.whisper('typing', {
                    user: 'Debug User',
                    typing: true
                });
                console.log('⌨️ Sent typing indicator');
            }, 2000);
            
        } catch (error) {
            console.error('❌ Room functionality test failed:', error);
        }
    },
    
    // Run comprehensive test suite
    async runFullTest() {
        console.log('🧪 Running comprehensive WebSocket test suite...');
        
        try {
            // Initialize
            await this.init();
            
            // Test basic connection
            await this.testBasicConnection();
            
            // Test Echo
            this.testEchoConnection();
            
            // Monitor connection
            this.monitorConnection();
            
            // Get info
            this.getConnectionInfo();
            
            console.log('✅ Test suite completed!');
            
        } catch (error) {
            console.error('❌ Test suite failed:', error);
        }
    },
    
    // Cleanup
    cleanup() {
        console.log('🧹 Cleaning up debug connections...');
        
        if (this.socket) {
            this.socket.close();
            this.socket = null;
        }
        
        if (this.echo) {
            this.echo.stopListening();
            this.echo = null;
        }
        
        console.log('✅ Cleanup completed');
    }
};

// Auto-initialize when script is loaded
console.log('🚀 HAWKI WebSocket Debug Utilities loaded!');
console.log('📖 Available methods:');
console.log('  - HAWKIWebSocketDebug.runFullTest()');
console.log('  - HAWKIWebSocketDebug.testBasicConnection()');
console.log('  - HAWKIWebSocketDebug.testEchoConnection()');
console.log('  - HAWKIWebSocketDebug.monitorConnection()');
console.log('  - HAWKIWebSocketDebug.getConnectionInfo()');
console.log('  - HAWKIWebSocketDebug.testRoomFunctionality("room-slug")');
console.log('💡 Quick start: Run HAWKIWebSocketDebug.runFullTest()');
