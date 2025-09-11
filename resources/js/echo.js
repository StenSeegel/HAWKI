import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Helper function to read meta tags
const getMeta = (name, fallback = null) => {
    const element = document.querySelector(`meta[name="${name}"]`);
    return element ? element.content : fallback;
};

// Read Reverb configuration from meta tags (runtime)
const reverbKey = getMeta('reverb-key');
const reverbHost = getMeta('reverb-host', location.hostname);
const reverbPort = parseInt(getMeta('reverb-port', location.protocol === 'https:' ? '443' : '80'), 10);
const reverbScheme = getMeta('reverb-scheme', location.protocol === 'https:' ? 'https' : 'http');

console.log('Echo.js wird dynamisch geladen...', {
    wsHost: reverbHost,
    wsPort: reverbPort,
    forceTLS: reverbScheme === 'https',
    scheme: reverbScheme,
    key: reverbKey ? reverbKey.substring(0, 10) + '...' : 'not found'
});

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: reverbKey,
    wsHost: reverbHost,
    wsPort: reverbScheme === 'http' ? reverbPort : null,
    wssPort: reverbScheme === 'https' ? reverbPort : null,
    forceTLS: reverbScheme === 'https',
    enabledTransports: ['ws', 'wss'],
    cluster: false,
    encrypted: reverbScheme === 'https',
    disableStats: true
});
