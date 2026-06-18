<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Transcription\Transcription;
use App\Models\User;
use App\Services\AI\AiService;
use App\Services\AI\Config\AiConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranscriptionSummarizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_summarize_endpoint_success(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'username' => 'testuser',
            'publicKey' => 'test-public-key',
            'employeetype' => 'staff',
            'auth_type' => 'local',
            'approval' => true,
        ]);

        $transcription = Transcription::create([
            'title' => 'Test Transcription',
            'user_id' => $user->id,
            'slug' => 'test-slug',
            'language' => 'de',
            'model_used' => 'gpt-4o',
            'provider' => 'openai',
        ]);

        // Mock AiService and AiConfigService
        $mockAiService = $this->createMock(AiService::class);
        $mockResponse = new \App\Services\AI\Value\AiResponse(
            content: ['text' => 'This is a test summary']
        );
        $mockAiService->method('sendRequest')->willReturn($mockResponse);

        $mockAiModelObj = $this->createMock(\App\Services\AI\Value\AiModel::class);
        $mockAiModelObj->method('getId')->willReturn('gpt-4o');
        $mockProvider = $this->createMock(\App\Services\AI\Interfaces\ModelProviderInterface::class);
        $mockProviderConfig = new \App\Services\AI\Value\ProviderConfig('openai', [
            'id' => 'openai',
            'active' => true,
            'api_url' => 'https://api.openai.com/v1',
            'api_key' => 'test-key',
            'adapter' => 'OpenAi',
        ]);
        $mockProvider->method('getConfig')->willReturn($mockProviderConfig);
        $mockAiModelObj->method('getProvider')->willReturn($mockProvider);

        $mockAiService->method('getModelOrFail')->willReturn($mockAiModelObj);

        $mockAiConfigService = $this->createMock(AiConfigService::class);
        $mockAiConfigService->method('getDefaultModels')->willReturn(['default_model' => 'gpt-4o']);

        $this->app->instance(AiService::class, $mockAiService);
        $this->app->instance(AiConfigService::class, $mockAiConfigService);

        $response = $this->actingAs($user)
            ->postJson('/req/transcription/summarize', [
                'transcript_text' => 'This is the transcript text.',
                'transcription_slug' => 'test-slug',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'summary' => 'This is a test summary',
        ]);

        $transcription->refresh();
        $this->assertEquals('This is a test summary', $transcription->metadata['summary'] ?? null);
    }
}
