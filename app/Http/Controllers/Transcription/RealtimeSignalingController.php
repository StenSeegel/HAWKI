<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transcription;

use App\Http\Controllers\Controller;
use App\Services\AI\Config\AiConfigService;
use App\Services\Transcription\TranscriptionSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RealtimeSignalingController extends Controller
{
    public function __construct(
        private readonly AiConfigService $aiConfigService,
        private readonly TranscriptionSettingsService $transcriptionSettingsService
    ) {}

    /**
     * Mint an ephemeral API key so the browser can POST its SDP offer directly
     * to OpenAI's WebRTC endpoint without exposing the main API key.
     */
    public function createSession(Request $request): JsonResponse
    {
        try {
            $providers = $this->aiConfigService->getProviders();
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error getting providers: ' . $e->getMessage()], 500);
        }

        $openAiProvider = $providers['openai'] ?? $providers['openAi'] ?? null;

        if (! $openAiProvider) {
            return response()->json(['error' => 'OpenAI provider not configured.'], 500);
        }

        $apiKey = $openAiProvider['api_key'] ?? null;
        $model  = $openAiProvider['realtime_model'] ?? $openAiProvider['model'] ?? 'gpt-4o-realtime-preview';

        // Strip any trailing path segments to reach the API base (e.g. /v1)
        $baseUrl = rtrim(preg_replace('#/chat/completions$#', '', rtrim($openAiProvider['api_url'], '/')), '/');

        $sessionEndpoint = $baseUrl . '/realtime/client_secrets';

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type'  => 'application/json',
        ])->post($sessionEndpoint, [
            'session' => [
                'type'  => 'transcription',
                'audio' => [
                    'input' => [
                        'transcription' => [
                            'model' => 'gpt-realtime-whisper',
                        ],
                    ],
                ],
            ],
        ]);

        if ($response->failed()) {
            Log::error('Realtime session creation failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return response()->json([
                'error' => 'Failed to create session: ' . ($response->json('error.message') ?? $response->body()),
            ], $response->status());
        }

        $data = $response->json();

        // Normalize: always return { value: "ek-..." } so the JS has a stable contract
        $ephemeralKey = $data['client_secret']['value'] ?? $data['value'] ?? null;

        if (! $ephemeralKey) {
            Log::error('Realtime session: could not extract ephemeral key', ['response' => $data]);
            return response()->json(['error' => 'Could not extract ephemeral key from OpenAI response.'], 500);
        }

        return response()->json(['value' => $ephemeralKey]);
    }

    /**
     * Handle the WebRTC signaling (SDP exchange) for real-time transcription.
     */
    public function handleSignaling(Request $request): JsonResponse
    {
        $request->validate([
            'sdp' => 'required|string',
        ]);

        try {
            $providers = $this->aiConfigService->getProviders();
        } catch (\Exception $e) {
            Log::error('Error getting providers', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'DEBUG: Error getting providers: ' . $e->getMessage()], 500);
        }

        $openAiProvider = $providers['openai'] ?? $providers['openAi'] ?? null;

        if (! $openAiProvider) {
            Log::error('OpenAI provider not found in config', ['available_providers' => array_keys($providers)]);
            return response()->json(['error' => 'DEBUG: OpenAI provider not configured.'], 500);
        }

        $apiKey = $openAiProvider['api_key'] ?? null;
        $baseUrl = rtrim($openAiProvider['api_url'], '/');

        // Construct the Realtime WebRTC calls endpoint per OpenAI docs
        $realtimeUrl = preg_replace('#/chat/completions$#', '', $baseUrl);
        $realtimeUrl = rtrim($realtimeUrl, '/') . '/realtime/calls';

        $model = $openAiProvider['realtime_model'] ?? $openAiProvider['model'] ?? 'gpt-4o-realtime-preview';

        $sdp = $request->input('sdp');
        if (!$sdp) {
            Log::error('SDP is missing or empty', ['input' => $request->all()]);
            return response()->json(['error' => 'SDP is missing or empty.'], 400);
        }

        // Session configuration sent as a multipart field alongside the SDP
        $sessionConfig = json_encode([
            'type'  => 'transcription',
            'model' => $model,
        ]);

        try {
            Log::debug('Real-time Signaling Request', [
                'url'         => $realtimeUrl,
                'model'       => $model,
                'sdp_length'  => strlen($sdp),
                'sdp_preview' => substr($sdp, 0, 100),
            ]);

            // Per OpenAI docs: POST multipart/form-data with "sdp" and "session" parts
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
            ])
                ->attach('sdp', $sdp, null, ['Content-Type' => 'application/sdp'])
                ->attach('session', $sessionConfig, null, ['Content-Type' => 'application/json'])
                ->post($realtimeUrl);

            Log::debug('Real-time Signaling Response', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 500),
            ]);

            if ($response->failed()) {
                Log::error('Real-time Signaling Failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return response()->json([
                    'error'   => 'OpenAI Realtime API Error: ' . ($response->json('error.message') ?? 'OpenAI returned status '.$response->status()),
                    'details' => $response->json('error.message') ?? 'OpenAI returned status '.$response->status(),
                    'body'    => $response->body(),
                ], $response->status());
            }

            // OpenAI returns the SDP answer as plain text
            return response()->json([
                'sdp' => $response->body(),
            ]);

        } catch (\Exception $e) {
            Log::error('Real-time Signaling Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Internal Server Error during signaling.'], 500);
        }
    }

    /**
     * Relay the WebRTC SDP offer to the realtime-bridge sidecar, which
     * bridges the browser's WebRTC connection to the vLLM realtime
     * WebSocket behind the LiteLLM gateway (word-level streaming STT).
     *
     * The gateway credentials travel per request from here to the bridge
     * (X-Gateway-* headers), so the database stays the single source of
     * truth and neither the bridge nor the browser ever stores a key.
     */
    public function createOnPremSignaling(Request $request): JsonResponse
    {
        $request->validate([
            'sdp' => 'required|string',
        ]);

        $providerKey = (string) $this->transcriptionSettingsService->get('onprem_api_provider', 'ki-at-jlu');
        $model = (string) $this->transcriptionSettingsService->get('onprem_realtime_model', 'voxtral-mini-realtime');

        try {
            $providers = $this->aiConfigService->getProviders();
        } catch (\Exception $e) {
            Log::error('On-prem realtime signaling: error getting providers', ['message' => $e->getMessage()]);

            return response()->json(['error' => 'Error getting providers: '.$e->getMessage()], 500);
        }

        $gatewayProvider = $providers[$providerKey] ?? null;

        if (! $gatewayProvider) {
            return response()->json(['error' => "Realtime gateway provider '{$providerKey}' not configured."], 500);
        }

        // api_url carries an endpoint path (e.g. /v1/chat/completions);
        // reduce it to scheme+host — the bridge appends /v1/realtime itself.
        $gatewayBase = rtrim($gatewayProvider['api_url'] ?? '', '/');
        $gatewayBase = preg_replace('#/chat/completions$#', '', $gatewayBase);
        $gatewayBase = preg_replace('#/v1$#', '', rtrim($gatewayBase, '/'));

        $bridgeUrl = rtrim((string) config('realtime_bridge.url'), '/').'/realtime';
        $bridgeKey = (string) config('realtime_bridge.api_key');

        $headers = [
            'X-Gateway-Base' => $gatewayBase,
            'X-Gateway-Key' => (string) ($gatewayProvider['api_key'] ?? ''),
            'X-Model' => $model,
        ];
        if ($bridgeKey !== '') {
            $headers['Authorization'] = 'Bearer '.$bridgeKey;
        }

        try {
            $response = Http::withHeaders($headers)
                ->withBody($request->input('sdp'), 'application/sdp')
                ->timeout(15)
                ->post($bridgeUrl);

            if ($response->failed()) {
                Log::error('Realtime bridge signaling failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);

                return response()->json([
                    'error' => 'Realtime bridge error (status: '.$response->status().')',
                ], 502);
            }

            return response()->json([
                'sdp' => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('Realtime bridge signaling exception', ['message' => $e->getMessage()]);

            return response()->json(['error' => 'Internal Server Error during bridge signaling.'], 500);
        }
    }

    /**
     * Which realtime provider the chat voice input should use.
     *
     * Admin-toggleable via the `chat_realtime_provider` row in
     * transcription_settings ('onprem' = on-prem realtime bridge (vLLM
     * behind the LiteLLM gateway), 'openai' = direct OpenAI Realtime).
     * Defaults to 'onprem' so no audio leaves the premises unless
     * explicitly configured otherwise.
     */
    public function getRealtimeConfig(): JsonResponse
    {
        $provider = (string) $this->transcriptionSettingsService->get('chat_realtime_provider', 'onprem');

        if (! in_array($provider, ['onprem', 'openai'], true)) {
            $provider = 'onprem';
        }

        return response()->json(['provider' => $provider]);
    }
}
