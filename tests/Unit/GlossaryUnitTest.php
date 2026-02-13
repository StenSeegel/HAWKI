<?php

namespace Tests\Unit;

use App\Services\Translation\Providers\DeeplTranslationProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class GlossaryUnitTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_glossary_is_applied_correctly_via_deepl_api_integration()
    {
        // 1. Arrange: Setup Data and Mocks
        $glossaryId = 123;
        $tempGlossaryId = 'temp_glossary_uuid';
        $input = 'Ich arbeite im Hochschulrechenzentrum der JLU Gießen.';
        $expectedTranslation = 'I work at the IT-Service-Centre (HRZ) of JLU Giessen.';

        // Mock the Eloquent Model: TranslateGlossaryEntry
        $mockEntry = Mockery::mock('alias:App\Models\TranslateGlossaryEntry');
        $mockEntry->shouldReceive('where')
            ->once()
            ->with('glossary_id', $glossaryId)
            ->andReturnSelf();

        $mockEntry->shouldReceive('where')
            ->once()
            ->with('source_language', 'DE')
            ->andReturnSelf();

        $mockEntry->shouldReceive('where')
            ->once()
            ->with('target_language', 'EN')
            ->andReturnSelf();

        // Create a fake entry object
        $fakeEntry = new \stdClass;
        $fakeEntry->source_term = 'Hochschulrechenzentrum';
        $fakeEntry->target_term = 'IT-Service-Centre (HRZ)';

        $mockEntry->shouldReceive('get')
            ->once()
            ->andReturn(new Collection([$fakeEntry]));

        // Mock DeepL API Responses using Http:fake
        Http::fake([
            // Create Glossary
            'api-free.deepl.com/v2/glossaries' => function ($request) use ($tempGlossaryId) {
                if (! str_contains($request['entries'], "Hochschulrechenzentrum\tIT-Service-Centre (HRZ)")) {
                    return Http::response(['message' => 'Invalid entries'], 400);
                }

                return Http::response(['glossary_id' => $tempGlossaryId, 'ready' => true], 201);
            },

            // Translate
            'api-free.deepl.com/v2/translate' => function ($request) use ($tempGlossaryId, $expectedTranslation) {
                if ($request['glossary_id'] !== $tempGlossaryId) {
                    return Http::response(['message' => 'Wrong glossary ID'], 400);
                }

                return Http::response([
                    'translations' => [
                        [
                            'detected_source_language' => 'DE',
                            'text' => $expectedTranslation,
                        ],
                    ],
                ], 200);
            },

            // Delete Glossary
            "api-free.deepl.com/v2/glossaries/{$tempGlossaryId}" => Http::response(null, 204),
        ]);

        // 2. Act: Execute the Provider Logic directly
        // We inject a fake API key so validation passes
        $provider = new DeeplTranslationProvider('fake-api-key');

        $result = $provider->translate(
            text: $input,
            sourceLang: 'DE',
            targetLang: 'EN',
            glossaryId: $glossaryId
        );

        // 3. Assert
        $this->assertEquals($expectedTranslation, $result['text']);

        Http::assertSent(function ($request) {
            return $request->url() == 'https://api-free.deepl.com/v2/glossaries' && $request->method() == 'POST';
        });

        Http::assertSent(function ($request) use ($tempGlossaryId) {
            return $request->url() == 'https://api-free.deepl.com/v2/translate' &&
                   $request['glossary_id'] == $tempGlossaryId;
        });

        Http::assertSent(function ($request) use ($tempGlossaryId) {
            return str_contains($request->url(), "/glossaries/{$tempGlossaryId}") && $request->method() == 'DELETE';
        });
    }
}
