<?php

namespace Tests\Unit;

use App\Services\Translation\Providers\DeeplLibraryProvider;
use DeepL\DeepLClient;
use DeepL\GlossaryInfo;
use DeepL\TextResult;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class GlossarySmartSplitTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_glossary_smart_split_is_applied_in_deepl_provider()
    {
        $glossaryId = 123;
        $tempGlossaryId = 'temp_glossary_uuid';

        // Input contains only the acronym
        $input = 'Ich arbeite im HRZ der JLU.';
        $expectedTranslation = 'I work at the IT Service-Centre (HRZ) of JLU.';

        // Mock the Eloquent Model: TranslateGlossaryEntry
        $mockEntry = Mockery::mock('alias:App\Models\TranslateGlossaryEntry');

        // Mock a single entry with Long Form (Acronym)
        $fakeEntry = new \stdClass;
        $fakeEntry->source_language = 'DE';
        $fakeEntry->target_language = 'EN';
        $fakeEntry->source_term = 'Hochschulrechenzentrum (HRZ)';
        $fakeEntry->target_term = 'IT Service-Centre (HRZ)';

        $mockEntry->shouldReceive('where')
            ->with('glossary_id', $glossaryId)
            ->andReturnSelf();

        $mockEntry->shouldReceive('where')
            ->andReturnSelf();

        $mockEntry->shouldReceive('get')
            ->andReturn(new Collection([$fakeEntry]));

        // Mock DeepL Client
        $mockClient = Mockery::mock(DeepLClient::class);

        // Expect createGlossary call with THREE variants
        $mockGlossaryInfo = Mockery::mock(GlossaryInfo::class);
        $mockGlossaryInfo->glossaryId = $tempGlossaryId;

        $mockClient->shouldReceive('createGlossary')
            ->once()
            ->with(
                Mockery::type('string'),
                'de',
                'en',
                Mockery::on(function ($entries) {
                    $arr = $entries->getEntries();

                    // Should contain:
                    // 1. Hochschulrechenzentrum (HRZ)
                    // 2. Hochschulrechenzentrum
                    // 3. HRZ
                    return isset($arr['Hochschulrechenzentrum (HRZ)']) &&
                           isset($arr['Hochschulrechenzentrum']) &&
                           isset($arr['HRZ']) &&
                           $arr['HRZ'] === 'IT Service-Centre (HRZ)';
                })
            )
            ->andReturn($mockGlossaryInfo);

        // Mock TextResult
        $mockTextResult = Mockery::mock(TextResult::class);
        $mockTextResult->text = $expectedTranslation;
        $mockTextResult->detectedSourceLang = 'DE';

        $mockClient->shouldReceive('translateText')
            ->once()
            ->andReturn($mockTextResult);

        $mockClient->shouldReceive('deleteGlossary')
            ->once()
            ->with($tempGlossaryId);

        $provider = new DeeplLibraryProvider('fake-api-key', $mockClient);

        $result = $provider->translate(
            text: $input,
            sourceLang: 'DE',
            targetLang: 'EN',
            glossaryId: $glossaryId
        );

        $this->assertEquals($expectedTranslation, $result['text']);
    }

    public function test_glossary_smart_split_works_together_with_bidirectional_in_deepl()
    {
        $glossaryId = 123;
        $tempGlossaryId = 'temp_glossary_uuid';

        // Translating EN -> DE, but using the DE -> EN glossary entry acronym
        $input = 'I work at the HRZ.';
        $expectedTranslation = 'Ich arbeite im Hochschulrechenzentrum (HRZ).';

        // Mock the Eloquent Model
        $mockEntry = Mockery::mock('alias:App\Models\TranslateGlossaryEntry');

        // Entry is DE -> EN
        $fakeEntry = new \stdClass;
        $fakeEntry->source_language = 'DE';
        $fakeEntry->target_language = 'EN';
        $fakeEntry->source_term = 'Hochschulrechenzentrum (HRZ)';
        $fakeEntry->target_term = 'IT Service-Centre (HRZ)';

        $mockEntry->shouldReceive('where')->andReturnSelf();
        $mockEntry->shouldReceive('get')->andReturn(new Collection([$fakeEntry]));

        // Mock DeepL Client
        $mockClient = Mockery::mock(DeepLClient::class);
        $mockGlossaryInfo = Mockery::mock(GlossaryInfo::class);
        $mockGlossaryInfo->glossaryId = $tempGlossaryId;

        $mockClient->shouldReceive('createGlossary')
            ->once()
            ->with(
                Mockery::type('string'),
                'en',
                'de',
                Mockery::on(function ($entries) {
                    $arr = $entries->getEntries();

                    // Since it's bidirectional:
                    // sTermRaw (from entry target) = IT Service-Centre (HRZ)
                    // Variants of sTermRaw: ["IT Service-Centre (HRZ)", "IT Service-Centre", "HRZ"]
                    // All should map to entry source: "Hochschulrechenzentrum (HRZ)"
                    return isset($arr['IT Service-Centre (HRZ)']) &&
                           isset($arr['IT Service-Centre']) &&
                           isset($arr['HRZ']) &&
                           $arr['HRZ'] === 'Hochschulrechenzentrum (HRZ)';
                })
            )
            ->andReturn($mockGlossaryInfo);

        $mockTextResult = Mockery::mock(TextResult::class);
        $mockTextResult->text = $expectedTranslation;
        $mockTextResult->detectedSourceLang = 'EN';

        $mockClient->shouldReceive('translateText')->andReturn($mockTextResult);
        $mockClient->shouldReceive('deleteGlossary')->andReturnNull();

        $provider = new DeeplLibraryProvider('fake-api-key', $mockClient);

        $result = $provider->translate(
            text: $input,
            sourceLang: 'EN',
            targetLang: 'DE',
            glossaryId: $glossaryId
        );

        $this->assertEquals($expectedTranslation, $result['text']);
    }
}
