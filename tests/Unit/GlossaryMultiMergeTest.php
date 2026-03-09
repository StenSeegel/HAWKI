<?php

namespace Tests\Unit;

use App\Services\Translation\Providers\DeeplLibraryProvider;
use App\Services\Translation\Providers\AiModelTranslationProvider;
use App\Services\AI\AiService;
use App\Services\AI\Response\AiResponse;
use DeepL\DeepLClient;
use DeepL\GlossaryInfo;
use DeepL\TextResult;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class GlossaryMultiMergeTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function mockTranslateSetting(): void
    {
        $mockSettingModel = Mockery::mock('alias:App\Models\TranslateSetting');

        $mockApiKeySetting = new \stdClass;
        $mockApiKeySetting->value = 'fake-api-key';

        $mockFilterSetting = new \stdClass;
        $mockFilterSetting->typed_value = false; // Disable filtering for easier test

        $mockSettingModel->shouldReceive('where')
            ->with('key', 'deepl_api_key')
            ->andReturn(Mockery::mock(['first' => $mockApiKeySetting]));

        $mockSettingModel->shouldReceive('where')
            ->with('key', 'filter_glossary')
            ->andReturn(Mockery::mock(['first' => $mockFilterSetting]));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_multiple_glossaries_are_merged_in_deepl()
    {
        $this->mockTranslateSetting();
        $glossaryIds = [1, 2];
        
        $mockEntry = Mockery::mock('alias:App\Models\TranslateGlossaryEntry');

        // Entries for glossary 1
        $entry1 = new \stdClass;
        $entry1->source_language = 'DE';
        $entry1->target_language = 'EN';
        $entry1->source_term = 'Term1';
        $entry1->target_term = 'Translated1';
        $entry1->case_sensitive = true;

        // Entries for glossary 2
        $entry2 = new \stdClass;
        $entry2->source_language = 'DE';
        $entry2->target_language = 'EN';
        $entry2->source_term = 'Term2';
        $entry2->target_term = 'Translated2';
        $entry2->case_sensitive = true;

        $mockEntry->shouldReceive('whereIn')
            ->with('glossary_id', [1, 2])
            ->andReturnSelf();
        
        $mockEntry->shouldReceive('where')->andReturnSelf();
        
        $mockEntry->shouldReceive('get')
            ->andReturn(new Collection([$entry1, $entry2]));

        // DeepL Client Mock
        $mockClient = Mockery::mock(DeepLClient::class);
        $mockGlossaryInfo = Mockery::mock(GlossaryInfo::class);
        $mockGlossaryInfo->glossaryId = 'temp_multi_glossary';

        $mockClient->shouldReceive('createGlossary')
            ->once()
            ->with(
                Mockery::type('string'),
                'de',
                'en',
                Mockery::on(function ($entries) {
                    $arr = $entries->getEntries();
                    return isset($arr['Term1']) && isset($arr['Term2']);
                })
            )
            ->andReturn($mockGlossaryInfo);

        $mockTextResult = Mockery::mock(TextResult::class);
        $mockTextResult->text = 'Translated Text';
        $mockTextResult->detectedSourceLang = 'DE';

        $mockClient->shouldReceive('translateText')->andReturn($mockTextResult);
        $mockClient->shouldReceive('deleteGlossary')->andReturnNull();

        $provider = new DeeplLibraryProvider('fake-api-key', $mockClient);
        
        $result = $provider->translate(
            text: 'Term1 and Term2',
            sourceLang: 'DE',
            targetLang: 'EN',
            glossaryId: $glossaryIds
        );

        $this->assertEquals('Translated Text', $result['text']);
    }
}
