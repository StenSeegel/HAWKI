<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AI\AiService;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiResponse;
use App\Services\AI\Value\TokenUsage;
use App\Services\Translation\Providers\AiModelTranslationProvider;
use App\Services\Translation\Providers\DeeplLibraryProvider;
use DeepL\DeepLClient;
use DeepL\TextResult;
use Mockery;
use Tests\TestCase;

class SentenceLevelProcessingTest extends TestCase
{
    public function test_ai_model_provider_handles_array_input(): void
    {
        $expectedOutput = ['Hallo', 'Welt'];
        $model = new AiModel(['id' => 'test-model']);
        $usage = new TokenUsage($model, 10, 10);

        // Mock response for single sentence
        $aiResponse = new AiResponse(
            content: [
                'text' => json_encode([
                    'text' => ['Translated 1', 'Translated 2'],
                    'detected_source_language' => 'EN',
                ]),
            ],
            usage: $usage
        );

        // Create a dummy extension of readonly AiService
        $aiService = new readonly class($aiResponse) extends AiService
        {
            public function __construct(private AiResponse $mockResponse)
            {
            }

            public function sendRequest(array|\App\Services\AI\Value\AiRequest $request): AiResponse
            {
                return $this->mockResponse;
            }
        };

        $provider = new AiModelTranslationProvider($aiService, 'test-model');

        $input = ['Hello', 'World'];
        $result = $provider->translate($input, 'en', 'de');

        $this->assertCount(2, $result['text']);
        $this->assertEquals('Translated 1', $result['text'][0]);
        $this->assertEquals('Translated 2', $result['text'][1]);
        $this->assertEquals('EN', $result['detected_source_language']);
    }

    public function test_deepl_library_provider_handles_array_input(): void
    {
        $mockClient = Mockery::mock(DeepLClient::class);
        $provider = new DeeplLibraryProvider('test-key', $mockClient);

        $input = ['Hello', 'World'];

        $res1 = new TextResult('Hallo', 'EN', 5);
        $res2 = new TextResult('Welt', 'EN', 4);

        $mockClient->shouldReceive('translateText')
            ->once()
            ->with($input, 'en', 'de', Mockery::any())
            ->andReturn([$res1, $res2]);

        $result = $provider->translate($input, 'en', 'de');

        $this->assertEquals(['Hallo', 'Welt'], $result['text']);
        $this->assertEquals('en', $result['detected_source_language']);
    }

    public function test_deepl_library_provider_write_handles_array_input(): void
    {
        $deepLMock = Mockery::mock(DeepLClient::class);
        $input = ['Hello', 'World'];

        // Mock rephraseText return value
        $textResult1 = new \stdClass();
        $textResult1->text = 'Hallo';
        $textResult2 = new \stdClass();
        $textResult2->text = 'Welt';

        $deepLMock->shouldReceive('rephraseText')
            ->once()
            ->with($input, 'de', Mockery::any())
            ->andReturn([$textResult1, $textResult2]);

        $provider = new DeeplLibraryProvider('fake-key', $deepLMock);

        $result = $provider->write($input, 'de');

        $this->assertEquals(['Hallo', 'Welt'], $result['text']);
    }
}
