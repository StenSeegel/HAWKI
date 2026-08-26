<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\Config\AiConfigService;
use App\Services\Transcription\Providers\CustomSpeachesProvider;
use App\Services\Transcription\TranscriptionSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CustomSpeachesBatchProviderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function makeSettingsService(array $settings): TranscriptionSettingsService
    {
        $service = $this->createMock(TranscriptionSettingsService::class);
        $service->method('get')->willReturnCallback(
            fn (string $key, $default = null) => $settings[$key] ?? $default
        );

        return $service;
    }

    protected function fakeAiConfigService(array $providers): void
    {
        $mock = $this->createMock(AiConfigService::class);
        $mock->method('getProviders')->willReturn($providers);
        $this->app->instance(AiConfigService::class, $mock);
    }

    public function test_batch_api_provider_reference_resolves_credentials_from_api_providers(): void
    {
        $this->fakeAiConfigService([
            'ki-at-jlu' => [
                'active' => true,
                'name' => 'ki@JLU',
                'api_key' => 'gateway-secret',
                'api_url' => 'https://gateway.example/v1/chat/completions',
                'models' => [
                    ['id' => 'jlu/whisper-1', 'label' => 'jlu/whisper-1', 'active' => true],
                ],
            ],
        ]);

        $provider = new CustomSpeachesProvider($this->makeSettingsService([
            'batch_api_provider' => 'ki-at-jlu',
            'model' => 'jlu/whisper-1',
            // Legacy strings present but must lose against the reference:
            'base_url' => 'http://old-worker/v1',
            'api_key' => 'old-key',
        ]));

        $this->assertSame('https://gateway.example/v1', $provider->getConfiguration()['provider']['base_url']);
    }

    public function test_batch_api_provider_appends_v1_to_bare_host(): void
    {
        $this->fakeAiConfigService([
            'ki-at-jlu' => [
                'active' => true,
                'api_key' => 'gateway-secret',
                'base_url' => 'https://gateway.example',
                'models' => [
                    ['id' => 'jlu/whisper-1', 'label' => 'jlu/whisper-1', 'active' => true],
                ],
            ],
        ]);

        $provider = new CustomSpeachesProvider($this->makeSettingsService([
            'batch_api_provider' => 'ki-at-jlu',
            'model' => 'jlu/whisper-1',
        ]));

        $this->assertSame('https://gateway.example/v1', $provider->getConfiguration()["provider"]["base_url"]);
    }

    public function test_batch_api_provider_rejects_model_not_in_ai_models(): void
    {
        $this->fakeAiConfigService([
            'ki-at-jlu' => [
                'active' => true,
                'api_key' => 'gateway-secret',
                'base_url' => 'https://gateway.example',
                'models' => [
                    ['id' => 'jlu/whisper-1', 'label' => 'jlu/whisper-1', 'active' => true],
                ],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nicht in ai_models/');

        new CustomSpeachesProvider($this->makeSettingsService([
            'batch_api_provider' => 'ki-at-jlu',
            'model' => 'some/unknown-model',
        ]));
    }

    public function test_batch_api_provider_rejects_unknown_provider(): void
    {
        $this->fakeAiConfigService([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nicht konfiguriert oder inaktiv/');

        new CustomSpeachesProvider($this->makeSettingsService([
            'batch_api_provider' => 'does-not-exist',
            'model' => 'jlu/whisper-1',
        ]));
    }

    public function test_empty_reference_keeps_legacy_string_settings(): void
    {
        // No AiConfigService interaction expected — legacy path.
        $provider = new CustomSpeachesProvider($this->makeSettingsService([
            'batch_api_provider' => '',
            'base_url' => 'http://worker/v1',
            'api_key' => 'legacy-key',
            'model' => 'Systran/faster-whisper-large-v3',
        ]));

        $this->assertSame('http://worker/v1', $provider->getConfiguration()["provider"]["base_url"]);
    }
}
