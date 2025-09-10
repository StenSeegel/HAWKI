<?php

namespace Tests\Unit;

use App\Services\Citations\Formatters\GoogleCitationFormatter;
use PHPUnit\Framework\TestCase;

class GoogleCitationFormatterTest extends TestCase
{
    private GoogleCitationFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new GoogleCitationFormatter();
    }

    public function test_get_provider_name(): void
    {
        $this->assertEquals('google', $this->formatter->getProviderName());
    }

    public function test_has_citations_returns_false_for_empty_data(): void
    {
        $this->assertFalse($this->formatter->hasCitations([]));
    }

    public function test_has_citations_returns_true_for_grounding_chunks(): void
    {
        $data = [
            'groundingChunks' => [
                ['web' => ['title' => 'test']],
            ],
        ];

        $this->assertTrue($this->formatter->hasCitations($data));
    }

    public function test_has_citations_returns_true_for_grounding_supports(): void
    {
        $data = [
            'groundingSupports' => [
                ['segment' => ['text' => 'test']],
            ],
        ];

        $this->assertTrue($this->formatter->hasCitations($data));
    }

    public function test_has_citations_returns_true_for_search_entry_point(): void
    {
        $data = [
            'searchEntryPoint' => [
                'searchQuery' => 'test query',
            ],
        ];

        $this->assertTrue($this->formatter->hasCitations($data));
    }

    public function test_format_basic_citation_data(): void
    {
        $providerData = [
            'groundingChunks' => [
                [
                    'web' => [
                        'title' => 'Example Source',
                        'uri' => 'https://example.com',
                        'snippet' => 'Example snippet',
                    ],
                ],
            ],
        ];

        $result = $this->formatter->format($providerData, 'test message');

        $this->assertEquals('hawki_v1', $result['format']);
        $this->assertEquals('segments', $result['processing_mode']);
        $this->assertCount(1, $result['citations']);

        $citation = $result['citations'][0];
        $this->assertEquals(1, $citation['id']);
        $this->assertEquals('Example Source', $citation['title']);
        $this->assertEquals('https://example.com', $citation['url']);
        $this->assertEquals('Example snippet', $citation['snippet']);
    }

    public function test_format_with_text_segments(): void
    {
        $providerData = [
            'groundingChunks' => [
                [
                    'web' => [
                        'title' => 'Source 1',
                        'uri' => 'https://source1.com',
                        'snippet' => 'Snippet 1',
                    ],
                ],
                [
                    'web' => [
                        'title' => 'Source 2',
                        'uri' => 'https://source2.com',
                        'snippet' => 'Snippet 2',
                    ],
                ],
            ],
            'groundingSupports' => [
                [
                    'segment' => [
                        'text' => 'important information',
                        'startIndex' => 0,
                        'endIndex' => 21,
                    ],
                    'groundingChunkIndices' => [0],
                ],
                [
                    'segment' => [
                        'text' => 'additional facts',
                        'startIndex' => 25,
                        'endIndex' => 41,
                    ],
                    'groundingChunkIndices' => [1],
                ],
            ],
        ];

        $result = $this->formatter->format($providerData, 'important information and additional facts');

        $this->assertCount(2, $result['citations']);
        $this->assertCount(2, $result['text_processing']['text_segments']);

        $segment1 = $result['text_processing']['text_segments'][0];
        $this->assertEquals('important information', $segment1['text']);
        $this->assertEquals([1], $segment1['citationIds']);

        $segment2 = $result['text_processing']['text_segments'][1];
        $this->assertEquals('additional facts', $segment2['text']);
        $this->assertEquals([2], $segment2['citationIds']);
    }

    public function test_format_merges_duplicate_text_segments(): void
    {
        $providerData = [
            'groundingChunks' => [
                [
                    'web' => [
                        'title' => 'Source 1',
                        'uri' => 'https://source1.com',
                        'snippet' => 'Snippet 1',
                    ],
                ],
                [
                    'web' => [
                        'title' => 'Source 2',
                        'uri' => 'https://source2.com',
                        'snippet' => 'Snippet 2',
                    ],
                ],
            ],
            'groundingSupports' => [
                [
                    'segment' => [
                        'text' => 'duplicate text',
                        'startIndex' => 0,
                        'endIndex' => 14,
                    ],
                    'groundingChunkIndices' => [0],
                ],
                [
                    'segment' => [
                        'text' => 'duplicate text', // Same text!
                        'startIndex' => 0,
                        'endIndex' => 14,
                    ],
                    'groundingChunkIndices' => [1], // Different source
                ],
            ],
        ];

        $result = $this->formatter->format($providerData, 'duplicate text here');

        $this->assertCount(2, $result['citations']);
        $this->assertCount(1, $result['text_processing']['text_segments']); // Merged!

        $mergedSegment = $result['text_processing']['text_segments'][0];
        $this->assertEquals('duplicate text', $mergedSegment['text']);
        $this->assertEquals([1, 2], $mergedSegment['citationIds']); // Both citation IDs
    }

    public function test_format_with_search_metadata(): void
    {
        $providerData = [
            'groundingChunks' => [
                [
                    'web' => [
                        'title' => 'Search Result',
                        'uri' => 'https://search.com',
                        'snippet' => 'Search snippet',
                    ],
                ],
            ],
            'searchEntryPoint' => [
                'searchQuery' => 'golf tournaments',
                'renderedContent' => '<div>Search results HTML</div>',
            ],
        ];

        $result = $this->formatter->format($providerData, 'search results');

        $this->assertEquals('golf tournaments', $result['searchMetadata']['query']);
        $this->assertEquals('<div>Search results HTML</div>', $result['searchMetadata']['renderedContent']);
    }

    public function test_format_handles_missing_optional_fields(): void
    {
        $providerData = [
            'groundingChunks' => [
                [
                    'web' => [
                        'title' => 'Minimal Source',
                        'uri' => 'https://minimal.com',
                        // No snippet
                    ],
                ],
            ],
        ];

        $result = $this->formatter->format($providerData, 'test message');

        $citation = $result['citations'][0];
        $this->assertEquals('', $citation['snippet']); // Empty string for missing snippet
    }

    public function test_format_ignores_empty_text_segments(): void
    {
        $providerData = [
            'groundingChunks' => [
                [
                    'web' => [
                        'title' => 'Source',
                        'uri' => 'https://source.com',
                        'snippet' => 'Snippet',
                    ],
                ],
            ],
            'groundingSupports' => [
                [
                    'segment' => [
                        'text' => '',  // Empty text
                        'startIndex' => 0,
                        'endIndex' => 0,
                    ],
                    'groundingChunkIndices' => [0],
                ],
                [
                    'segment' => [
                        'text' => '   ',  // Whitespace only
                        'startIndex' => 0,
                        'endIndex' => 3,
                    ],
                    'groundingChunkIndices' => [0],
                ],
            ],
        ];

        $result = $this->formatter->format($providerData, 'test message');

        $this->assertCount(1, $result['citations']);
        $this->assertCount(0, $result['text_processing']['text_segments']); // Empty segments filtered out
    }

    public function test_format_preserves_backwards_compatibility(): void
    {
        $providerData = [
            'groundingChunks' => [
                [
                    'web' => [
                        'title' => 'Legacy Test',
                        'uri' => 'https://legacy.com',
                        'snippet' => 'Legacy snippet',
                    ],
                ],
            ],
            'groundingSupports' => [
                [
                    'segment' => [
                        'text' => 'legacy text',
                        'startIndex' => 0,
                        'endIndex' => 11,
                    ],
                    'groundingChunkIndices' => [0],
                ],
            ],
        ];

        $result = $this->formatter->format($providerData, 'legacy text here');

        // Check backwards compatibility field
        $this->assertArrayHasKey('textSegments', $result);
        $this->assertEquals($result['text_processing']['text_segments'], $result['textSegments']);
    }
}
