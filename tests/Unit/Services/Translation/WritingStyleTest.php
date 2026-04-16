<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Translation;

use App\Services\Translation\Providers\AiModelTranslationProvider;
use App\Services\Translation\Providers\DeeplLibraryProvider;
use App\Services\Translation\TextImprovementService;
use DeepL\DeepLClient;
use DeepL\RephraseTextOptions;
use PHPUnit\Framework\TestCase;
use Mockery;
use ReflectionMethod;

class WritingStyleTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test DeepL Translation formality mapping
     */
    public function test_deepl_translation_formality_mapping(): void
    {
        $mockClient = Mockery::mock(DeepLClient::class);
        
        // Test 'formal' -> 'more'
        $mockClient->shouldReceive('translateText')
            ->once()
            ->with('Hello', null, 'DE', Mockery::on(fn($opts) => $opts['formality'] === 'more'))
            ->andReturn(new class { public $text = 'Hallo (formal)'; public $detectedSourceLang = 'en'; });

        $provider = new DeeplLibraryProvider('key', $mockClient);
        $result1 = $provider->translate('Hello', null, 'de', null, 'formal');
        $this->assertEquals('Hallo (formal)', $result1['text']);

        // Test 'informal' -> 'less'
        $mockClient->shouldReceive('translateText')
            ->once()
            ->with('Hello', null, 'DE', Mockery::on(fn($opts) => $opts['formality'] === 'less'))
            ->andReturn(new class { public $text = 'Hallo (informal)'; public $detectedSourceLang = 'en'; });

        $result2 = $provider->translate('Hello', null, 'de', null, 'informal');
        $this->assertEquals('Hallo (informal)', $result2['text']);
    }

    /**
     * Test DeepL Write style and tone mapping
     */
    public function test_deepl_write_style_and_tone_mapping(): void
    {
        $mockClient = Mockery::mock(DeepLClient::class);
        
        $mockClient->shouldReceive('rephraseText')
            ->once()
            ->with('Test text', null, Mockery::on(function ($opts) {
                return $opts[RephraseTextOptions::WRITING_STYLE] === 'business'
                    && $opts[RephraseTextOptions::TONE] === 'confident';
            }))
            ->andReturn(new class { public $text = 'Improved text'; });

        $provider = new DeeplLibraryProvider('key', $mockClient);
        $result = $provider->write('Test text', null, 'business', 'confident');
        $this->assertEquals('Improved text', $result['text']);
    }

    /**
     * Test AI Model Translation prompt for formality
     */
    public function test_ai_model_translation_prompt_contains_formality(): void
    {
        $reflection = new ReflectionMethod(AiModelTranslationProvider::class, 'buildSystemPrompt');
        $reflection->setAccessible(true);
        
        $provider = Mockery::mock(AiModelTranslationProvider::class)->makePartial();

        // Testing 'formal' instruction
        $prompt = $reflection->invoke($provider, 'en', 'de', '', 'formal', null, false);
        $this->assertStringContainsString('formal and polite', $prompt);
        $this->assertStringContainsString('Sie', $prompt);

        // Testing 'informal' instruction
        $prompt = $reflection->invoke($provider, 'en', 'de', '', 'informal', null, false);
        $this->assertStringContainsString('informal and casual', $prompt);
        $this->assertStringContainsString('du', $prompt);
    }

    /**
     * Test TextImprovementService prompt for style, tone and formality
     */
    public function test_text_improvement_prompt_mapping(): void
    {
        $reflection = new ReflectionMethod(TextImprovementService::class, 'getSystemPrompt');
        $reflection->setAccessible(true);
        
        $service = Mockery::mock(TextImprovementService::class)->makePartial();

        // Test combination: academic style, friendly tone, formal address
        $prompt = $reflection->invoke($service, 'default', false, null, 'en', 'academic', 'friendly', 'formal', null, null);
        
        $this->assertStringContainsString('academic, scholarly style', $prompt);
        $this->assertStringContainsString('friendly, warm', $prompt);
        $this->assertStringContainsString('formal (polite form)', $prompt);

        // Test another combination: simple style, diplomatic tone, informal address
        $prompt2 = $reflection->invoke($service, 'default', false, null, 'en', 'simple', 'diplomatic', 'informal', null, null);
        
        $this->assertStringContainsString('simple, clear language', $prompt2);
        $this->assertStringContainsString('diplomatic, tactful', $prompt2);
        $this->assertStringContainsString('informal (familiar form)', $prompt2);
    }
}
