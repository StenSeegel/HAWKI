<?php

namespace Tests\Feature;

use App\Services\Citations\CitationService;
use Tests\TestCase;

class CitationIntegrationTest extends TestCase
{
    private CitationService $citationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->citationService = new CitationService();
    }

    public function test_google_citation_integration_with_real_world_scenario(): void
    {
        // Simulate a real Google API response for golf tournament query
        $googleApiResponse = [
            'groundingChunks' => [
                [
                    'web' => [
                        'title' => 'europeantour.com',
                        'uri' => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/AUZIYQGFwoteQdTcbINKggrK8B7hQUaq6o1jMBjT642TpIsQajwOltxcQSiLbMCZ-iHEbJMj6nSImsoSGGegd4Fcv6uxI5xJI7JQNnRef6-v1cGhdP2Flivdv1tBcAcDFiJDxVqDwXm-ymWgaoWwbXufCQ==',
                        'snippet' => 'BMW PGA Championship information',
                    ],
                ],
                [
                    'web' => [
                        'title' => 'lpga.com',
                        'uri' => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/AUZIYQEqhn2Vacp1iemwykSTfjTVvKHHATW14sd1HjdG1wkIj-CPFJC57KoXPfdofDI_SMYY9Cj-bRxEeR1GOAVNvrfzru70kaTwG3HthJ5D_tKm5I_uCblHXZHnCkI0',
                        'snippet' => 'LPGA tournament details',
                    ],
                ],
                [
                    'web' => [
                        'title' => 'usatoday.com',
                        'uri' => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/AUZIYQHirtjGDfKO1VBSKOBtcvLVemWocwSBSaalXcpruncBG9-KbOLh2CDRWaQ9zkZlgwDVSFImsj5VoqDhsKekkPW8t1Upmjrs8PYsh-PY2_vSMy1BgRm3fqdDNVES8PKQ0BbKpCQfdSUUyjacyLbW',
                        'snippet' => 'Golf news and updates',
                    ],
                ],
                [
                    'web' => [
                        'title' => 'nbcsports.com',
                        'uri' => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/AUZIYQE6jvZsaLnagR_6R2NzbTOX2J7vOs-K_X67oMB7lUCRtSQueq7lVQPqesb2AssZw0gzTWgGsV63q_d6vnnPPxtKeORBRaJjLFC3ayPMPvxMImXJUK3nlceb1gDW15wC',
                        'snippet' => 'Sports coverage and analysis',
                    ],
                ],
            ],
            'groundingSupports' => [
                [
                    'segment' => [
                        'text' => 'BMW PGA Championship',
                        'startIndex' => 89,
                        'endIndex' => 110,
                    ],
                    'groundingChunkIndices' => [0],
                ],
                [
                    'segment' => [
                        'text' => 'Kroger Queen City Championship presented by P&G',
                        'startIndex' => 156,
                        'endIndex' => 204,
                    ],
                    'groundingChunkIndices' => [1, 2],
                ],
                [
                    'segment' => [
                        'text' => 'Folds of Honor Collegiate',
                        'startIndex' => 298,
                        'endIndex' => 323,
                    ],
                    'groundingChunkIndices' => [3],
                ],
                [
                    'segment' => [
                        'text' => 'Folds of Honor Collegiate', // Duplicate segment!
                        'startIndex' => 298,
                        'endIndex' => 323,
                    ],
                    'groundingChunkIndices' => [3], // Same citation
                ],
            ],
            'searchEntryPoint' => [
                'searchQuery' => 'golf tournaments September 8-14 2025',
                'renderedContent' => '<div class="container">Search results for golf tournaments</div>',
            ],
        ];

        $messageText = 'Das prestigeträchtigste Event dieser Woche ist die BMW PGA Championship auf der DP World Tour. Auf der LPGA Tour steht die Kroger Queen City Championship presented by P&G auf dem Programm. Zusätzlich wird die Folds of Honor Collegiate ausgetragen.';

        $result = $this->citationService->formatCitations('google', $googleApiResponse, $messageText);

        // Verify citation structure
        $this->assertIsArray($result);
        $this->assertEquals('hawki_v1', $result['format']);
        $this->assertEquals('segments', $result['processing_mode']);

        // Verify citations
        $this->assertCount(4, $result['citations']);
        $this->assertEquals('europeantour.com', $result['citations'][0]['title']);
        $this->assertEquals('lpga.com', $result['citations'][1]['title']);
        $this->assertEquals('usatoday.com', $result['citations'][2]['title']);
        $this->assertEquals('nbcsports.com', $result['citations'][3]['title']);

        // Verify text segments - duplicates should be merged
        $this->assertCount(3, $result['text_processing']['text_segments']); // Not 4, because of duplicate

        // Find the BMW PGA segment
        $bmwSegment = collect($result['text_processing']['text_segments'])
            ->firstWhere('text', 'BMW PGA Championship');
        $this->assertNotNull($bmwSegment);
        $this->assertEquals([1], $bmwSegment['citationIds']);

        // Find the LPGA segment
        $lpgaSegment = collect($result['text_processing']['text_segments'])
            ->firstWhere('text', 'Kroger Queen City Championship presented by P&G');
        $this->assertNotNull($lpgaSegment);
        $this->assertEquals([2, 3], $lpgaSegment['citationIds']); // Multiple sources

        // Find the Folds segment (duplicates should be merged)
        $foldsSegment = collect($result['text_processing']['text_segments'])
            ->firstWhere('text', 'Folds of Honor Collegiate');
        $this->assertNotNull($foldsSegment);
        $this->assertEquals([4], $foldsSegment['citationIds']); // Single citation despite duplicate

        // Verify search metadata
        $this->assertEquals('golf tournaments September 8-14 2025', $result['searchMetadata']['query']);
        $this->assertStringContainsString('Search results for golf tournaments', $result['searchMetadata']['renderedContent']);

        // Verify backwards compatibility
        $this->assertArrayHasKey('textSegments', $result);
        $this->assertEquals($result['text_processing']['text_segments'], $result['textSegments']);
    }

    public function test_anthropic_citation_integration(): void
    {
        $anthropicData = [
            'groundingChunks' => [
                [
                    'web' => [
                        'title' => 'Example Source',
                        'uri' => 'https://example.com',
                    ],
                ],
            ],
        ];

        $messageText = 'This is a test with inline citations [1] in the text.';

        $result = $this->citationService->formatCitations('anthropic', $anthropicData, $messageText);

        $this->assertIsArray($result);
        $this->assertEquals('hawki_v1', $result['format']);
        $this->assertEquals('inline', $result['processing_mode']);
        $this->assertTrue($result['text_processing']['inline_markers']);
    }

    public function test_openai_responses_citation_integration(): void
    {
        $openaiData = [
            'citations' => [
                [
                    'url' => 'https://openai-source.com',
                    'title' => 'OpenAI Source',
                    'start_index' => 10,
                    'end_index' => 20,
                ],
            ],
            'searchQueries' => ['test query'],
        ];

        $messageText = 'This is a test message with OpenAI citations.';

        $result = $this->citationService->formatCitations('openai_responses', $openaiData, $messageText);

        $this->assertIsArray($result);
        $this->assertEquals('hawki_v1', $result['format']);
        $this->assertCount(1, $result['citations']);
        $this->assertEquals('OpenAI Source', $result['citations'][0]['title']);
    }

    public function test_citation_service_handles_invalid_provider_gracefully(): void
    {
        $result = $this->citationService->formatCitations('invalid_provider', ['test' => 'data'], 'test message');

        $this->assertNull($result);
    }

    public function test_citation_service_handles_empty_data_gracefully(): void
    {
        $result = $this->citationService->formatCitations('google', [], 'test message');

        $this->assertNull($result);
    }

    public function test_multiple_providers_can_be_used_sequentially(): void
    {
        // Test Google
        $googleData = [
            'groundingChunks' => [
                ['web' => ['title' => 'Google Source', 'uri' => 'https://google.com']],
            ],
        ];

        $googleResult = $this->citationService->formatCitations('google', $googleData, 'google test');
        $this->assertEquals('segments', $googleResult['processing_mode']);

        // Test Anthropic
        $anthropicData = [
            'groundingChunks' => [
                ['web' => ['title' => 'Anthropic Source', 'uri' => 'https://anthropic.com']],
            ],
        ];

        $anthropicResult = $this->citationService->formatCitations('anthropic', $anthropicData, 'anthropic test');
        $this->assertEquals('inline', $anthropicResult['processing_mode']);

        // Verify they produce different processing modes but same base structure
        $this->assertEquals($googleResult['format'], $anthropicResult['format']); // Same format version
        $this->assertNotEquals($googleResult['processing_mode'], $anthropicResult['processing_mode']); // Different modes
    }
}
