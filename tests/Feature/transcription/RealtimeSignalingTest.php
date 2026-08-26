<?php

declare(strict_types=1);

namespace Tests\Feature\Transcription;

use App\Models\Transcription\TranscriptionSetting;
use App\Models\User;
use App\Services\AI\Config\AiConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RealtimeSignalingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that signaling requires authentication.
     */
    public function test_signaling_requires_authentication(): void
    {
        $response = $this->postJson('/req/transcription/realtime/signaling', [
            'sdp' => 'v=0\r\no=-...',
        ]);

        $response->assertUnauthorized();
    }

    /**
     * Test that signaling returns an SDP answer when successful.
     */
    public function test_signaling_returns_sdp_answer(): void
    {
        $user = User::factory()->create();

        // Seed required DB state for AiConfigService
        $format = \App\Models\ApiFormat::create([
            'unique_name' => 'openai-api',
            'display_name' => 'OpenAI API',
            'client_adapter' => 'openai',
        ]);

        $provider = \App\Models\ApiProvider::create([
            'unique_name' => 'openai',
            'provider_name' => 'OpenAI',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'test-key',
            'api_format_id' => $format->id,
            'is_active' => true,
        ]);

        \App\Models\ApiFormatEndpoint::create([
            'api_format_id' => $format->id,
            'name' => 'chat.create',
            'path' => '/chat/completions',
            'method' => 'POST',
            'is_active' => true,
        ]);

        // Force database config mode
        config(['hawki.ai_config_system' => 'database']);
        app(AiConfigService::class)->clearCache();

        // Mock the OpenAI response
        Http::fake([
            'api.openai.com/v1/realtime*' => Http::response('v=0\r\no=remote...', 200, ['Content-Type' => 'application/sdp']),
        ]);

        $response = $this->actingAs($user)
            ->postJson('/req/transcription/realtime/signaling', [
                'sdp' => 'v=0\r\no=local...',
            ]);

        if ($response->status() === 500) {
            dd($response->json());
        }

        $response->assertOk();
        $response->assertJson([
            'sdp' => 'v=0\r\no=remote...',
        ]);
    }

    /**
     * Test that on-prem (realtime bridge) signaling requires authentication.
     */
    public function test_onprem_signaling_requires_authentication(): void
    {
        $response = $this->postJson('/req/transcription/realtime/onprem/signaling', [
            'sdp' => 'v=0\r\no=-...',
        ]);

        $response->assertUnauthorized();
    }

    /**
     * Test that on-prem signaling relays the SDP offer to the realtime
     * bridge with the gateway credentials as headers, and returns the
     * bridge's SDP answer.
     */
    public function test_onprem_signaling_relays_offer_to_bridge(): void
    {
        $user = User::factory()->create();

        $format = \App\Models\ApiFormat::create([
            'unique_name' => 'openai-api',
            'display_name' => 'OpenAI API',
            'client_adapter' => 'openai',
        ]);

        \App\Models\ApiProvider::create([
            'unique_name' => 'ki-at-jlu',
            'provider_name' => 'ki@JLU',
            'base_url' => 'https://gw.test/v1',
            'api_key' => 'gw-key',
            'api_format_id' => $format->id,
            'is_active' => true,
        ]);

        \App\Models\ApiFormatEndpoint::create([
            'api_format_id' => $format->id,
            'name' => 'chat.create',
            'path' => '/chat/completions',
            'method' => 'POST',
            'is_active' => true,
        ]);

        config(['hawki.ai_config_system' => 'database']);
        app(AiConfigService::class)->clearCache();
        config(['realtime_bridge.url' => 'http://bridge.test:8089']);

        Http::fake([
            'bridge.test:8089/realtime' => Http::response('v=0\r\no=bridge-answer...', 200, ['Content-Type' => 'application/sdp']),
        ]);

        $response = $this->actingAs($user)
            ->postJson('/req/transcription/realtime/onprem/signaling', [
                'sdp' => 'v=0\r\no=local...',
            ]);

        $response->assertOk();
        $response->assertJson([
            'sdp' => 'v=0\r\no=bridge-answer...',
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://bridge.test:8089/realtime'
                // api_url (https://gw.test/v1/chat/completions) must be
                // reduced to scheme+host — the bridge appends /v1/realtime.
                && $request->hasHeader('X-Gateway-Base', 'https://gw.test')
                && $request->hasHeader('X-Gateway-Key', 'gw-key')
                && $request->hasHeader('X-Model', 'voxtral-mini-realtime')
                && $request->body() === 'v=0\r\no=local...';
        });
    }

    /**
     * Test that on-prem signaling fails clearly when the configured gateway
     * provider doesn't exist.
     */
    public function test_onprem_signaling_fails_when_provider_missing(): void
    {
        $user = User::factory()->create();

        config(['hawki.ai_config_system' => 'database']);
        app(AiConfigService::class)->clearCache();

        $response = $this->actingAs($user)
            ->postJson('/req/transcription/realtime/onprem/signaling', [
                'sdp' => 'v=0\r\no=local...',
            ]);

        $response->assertServerError();
        $response->assertJson([
            'error' => "Realtime gateway provider 'ki-at-jlu' not configured.",
        ]);
    }

    /**
     * Test that the realtime config endpoint passes the onprem provider
     * through to the frontend.
     */
    public function test_realtime_config_returns_onprem_provider(): void
    {
        $user = User::factory()->create();

        TranscriptionSetting::updateOrCreate(
            ['key' => 'chat_realtime_provider'],
            ['value' => 'onprem', 'type' => 'string', 'is_private' => false]
        );

        $response = $this->actingAs($user)->getJson('/req/transcription/realtime/config');

        $response->assertOk();
        $response->assertJson(['provider' => 'onprem']);
    }

    /**
     * Test that the realtime config endpoint defaults to the onprem
     * provider when nothing is configured (privacy-safe default: no audio
     * leaves the premises unless explicitly toggled to OpenAI).
     */
    public function test_realtime_config_defaults_to_onprem(): void
    {
        $user = User::factory()->create();

        TranscriptionSetting::where('key', 'chat_realtime_provider')->delete();

        $response = $this->actingAs($user)->getJson('/req/transcription/realtime/config');

        $response->assertOk();
        $response->assertJson(['provider' => 'onprem']);
    }

    /**
     * Test that a leftover 'local' value (the retired Speaches realtime
     * provider) is clamped to onprem instead of reaching the frontend.
     */
    public function test_realtime_config_clamps_retired_local_provider(): void
    {
        $user = User::factory()->create();

        TranscriptionSetting::updateOrCreate(
            ['key' => 'chat_realtime_provider'],
            ['value' => 'local', 'type' => 'string', 'is_private' => false]
        );

        $response = $this->actingAs($user)->getJson('/req/transcription/realtime/config');

        $response->assertOk();
        $response->assertJson(['provider' => 'onprem']);
    }

    /**
     * Test that the realtime config endpoint honors the
     * chat_realtime_provider transcription setting.
     */
    public function test_realtime_config_returns_configured_provider(): void
    {
        $user = User::factory()->create();

        TranscriptionSetting::updateOrCreate(
            ['key' => 'chat_realtime_provider'],
            ['value' => 'openai', 'type' => 'string', 'is_private' => false]
        );

        $response = $this->actingAs($user)->getJson('/req/transcription/realtime/config');

        $response->assertOk();
        $response->assertJson(['provider' => 'openai']);
    }

    /**
     * Test that an invalid configured value falls back to onprem rather
     * than being passed through to the frontend.
     */
    public function test_realtime_config_rejects_unknown_provider_values(): void
    {
        $user = User::factory()->create();

        TranscriptionSetting::updateOrCreate(
            ['key' => 'chat_realtime_provider'],
            ['value' => 'some-junk-value', 'type' => 'string', 'is_private' => false]
        );

        $response = $this->actingAs($user)->getJson('/req/transcription/realtime/config');

        $response->assertOk();
        $response->assertJson(['provider' => 'onprem']);
    }
}
