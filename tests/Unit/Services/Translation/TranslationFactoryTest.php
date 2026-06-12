<?php

namespace Tests\Unit\Services\Translation;

use App\Models\ApiProvider;
use App\Services\Translation\Providers\AiModelTranslationProvider;
use App\Services\Translation\Providers\DeeplLibraryProvider;
use App\Services\Translation\TranslationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class TranslationFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_deepl_provider_by_default()
    {
        ApiProvider::create([
            'unique_name' => 'deepl',
            'api_key' => 'test-key',
            'base_url' => 'https://api.deepl.com',
            'is_active' => true,
        ]);

        Config::set('translation.driver', 'deepl');
        Config::set('translation.default', 'deepl');

        $provider = TranslationFactory::create();

        $this->assertInstanceOf(DeeplLibraryProvider::class, $provider);
    }

    public function test_creates_ai_provider_when_configured()
    {
        Config::set('translation.driver', 'ai');
        Config::set('translation.ai_model', 'test-model');
        Config::set('model_providers.default_models.default_model', 'fallback-model');

        $this->instance(\App\Services\AI\AiService::class, $this->createMock(\App\Services\AI\AiService::class));

        $provider = TranslationFactory::create();

        $this->assertInstanceOf(AiModelTranslationProvider::class, $provider);
    }
}
