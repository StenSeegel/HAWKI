<?php

namespace Tests\Unit;

use App\Services\Translation\Providers\DeeplLibraryProvider;
use DeepL\DeepLClient;
use DeepL\GlossaryInfo;
use DeepL\TextResult;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class GlossaryUnitTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_glossary_is_applied_correctly_via_deepl_library_integration()
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
            ->with(Mockery::type('closure'))
            ->andReturnSelf();

        // Create a fake entry object
        $fakeEntry = new \stdClass;
        $fakeEntry->source_language = 'DE';
        $fakeEntry->target_language = 'EN';
        $fakeEntry->source_term = 'Hochschulrechenzentrum';
        $fakeEntry->target_term = 'IT-Service-Centre (HRZ)';

        $mockEntry->shouldReceive('get')
            ->once()
            ->andReturn(new Collection([$fakeEntry]));

        // Mock DeepL Client
        $mockTranslator = Mockery::mock(DeepLClient::class);

        // Mock GlossaryInfo result
        $mockGlossaryInfo = Mockery::mock(GlossaryInfo::class);
        $mockGlossaryInfo->glossaryId = $tempGlossaryId;

        // Expect createGlossary call
        $mockTranslator->shouldReceive('createGlossary')
            ->once()
            // We can match arguments loosely or specifically.
            // Argument 2: source lang (lowercase), 3: target lang (lowercase), 4: entries
            ->with(
                Mockery::type('string'), // name
                'de', // source
                'en', // target
                Mockery::capture($glossaryEntries) // entries (GlossaryEntries object)
            )
            ->andReturn($mockGlossaryInfo);

        // Mock TextResult
        $mockTextResult = Mockery::mock(TextResult::class);
        $mockTextResult->text = $expectedTranslation;
        $mockTextResult->detectedSourceLang = 'DE';

        // Expect translateText call with glossary options
        $mockTranslator->shouldReceive('translateText')
            ->once()
            ->with(
                $input,
                'DE',
                'en-US', // LibraryProvider normalizes EN -> en-US
                ['glossary' => $tempGlossaryId]
            )
            ->andReturn($mockTextResult);

        // Expect deleteGlossary call
        $mockTranslator->shouldReceive('deleteGlossary')
            ->once()
            ->with($tempGlossaryId);

        // 2. Act: Execute the Provider Logic directly
        // Inject the mock translator
        $provider = new DeeplLibraryProvider('fake-api-key', $mockTranslator);

        $result = $provider->translate(
            text: $input,
            sourceLang: 'DE',
            targetLang: 'EN',
            glossaryId: $glossaryId
        );

        // 3. Assert
        $this->assertEquals($expectedTranslation, $result['text']);
        $this->assertEquals('DE', $result['detected_source_language']);

        // Verify glossary entries passed to library were correct
        $entriesArray = $glossaryEntries->getEntries();
        $this->assertArrayHasKey('Hochschulrechenzentrum', $entriesArray);
        $this->assertEquals('IT-Service-Centre (HRZ)', $entriesArray['Hochschulrechenzentrum']);
    }
}
