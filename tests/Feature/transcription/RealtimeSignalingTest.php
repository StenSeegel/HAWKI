<?php

declare(strict_types=1);

namespace Tests\Feature\Transcription;

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
}
