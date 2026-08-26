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

    /*
     * ICE servers handed to the BROWSER's RTCPeerConnection.
     *
     * Required on networks where the browser has no ICE candidate that can
     * reach the HAWKI host. Observed on the JLU VPN: Chrome gathers host
     * candidates only on interfaces with no route to the internal server and
     * never on the VPN tunnel, so every candidate pair fails. A TURN relay
     * fixes it because the browser reaches TURN over ordinary TCP routing
     * rather than via ICE interface enumeration.
     *
     * TURN credentials are necessarily visible to the client (the browser must
     * authenticate), so these are not secrets in the usual sense — but they
     * still come from env so they differ per environment. For production,
     * prefer coturn's time-limited REST credentials over static ones.
     */
    'turn_urls' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TURN_URLS', ''))
    ))),
    'turn_username' => env('TURN_USERNAME', ''),
    'turn_password' => env('TURN_PASSWORD', ''),
];
