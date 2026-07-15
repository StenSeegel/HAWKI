<?php

/*
 * HAWKI Realtime Bridge (WebRTC -> vLLM realtime WebSocket sidecar).
 *
 * The bridge runs as the `realtime-bridge` service in the _docker stack
 * (host networking, default port 8089). Laravel relays browser SDP offers
 * to it and passes the gateway credentials per request, so the bridge
 * itself holds no secrets.
 */
return [
    // Signaling endpoint of the bridge as reachable FROM the app container.
    'url' => env('REALTIME_BRIDGE_URL', 'http://host.docker.internal:8089'),

    // Optional shared secret fencing the bridge's signaling endpoint
    // (BRIDGE_API_KEY on the bridge side). Empty disables the check.
    'api_key' => env('REALTIME_BRIDGE_API_KEY', ''),
];
