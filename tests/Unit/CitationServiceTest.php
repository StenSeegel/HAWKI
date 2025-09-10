<?php

namespace Tests\Unit;

use App\Services\Citations\CitationService;
use App\Services\Citations\Formatters\GoogleCitationFormatter;
use PHPUnit\Framework\TestCase;

class CitationServiceTest extends TestCase
{
    private CitationService $citationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->citationService = new CitationService();
    }

    public function test_format_citations_returns_null_for_empty_data(): void
    {
        $result = $this->citationService->formatCitations('google', [], 'test message');

        $this->assertNull($result);
    }

    public function test_format_citations_returns_null_for_unknown_provider(): void
    {
        $result = $this->citationService->formatCitations('unknown_provider', ['test' => 'data'], 'test message');

        $this->assertNull($result);
    }

    public function test_format_citations_with_google_provider(): void
    {
        $providerData = [
            'groundingChunks' => [
                [
                    'web' => [
                        'title' => 'Test Source',
                        'uri' => 'https://example.com',
                        'snippet' => 'Test snippet',
                    ],
                ],
            ],
            'groundingSupports' => [
                [
                    'segment' => [
                        'text' => 'test text',
                        'startIndex' => 0,
                        'endIndex' => 9,
                    ],
                    'groundingChunkIndices' => [0],
                ],
            ],
        ];

        $result = $this->citationService->formatCitations('google', $providerData, 'test text here');

        $this->assertIsArray($result);
        $this->assertEquals('hawki_v1', $result['format']);
        $this->assertEquals('segments', $result['processing_mode']);
        $this->assertCount(1, $result['citations']);
        $this->assertEquals('Test Source', $result['citations'][0]['title']);
        $this->assertEquals('https://example.com', $result['citations'][0]['url']);
    }

    public function test_get_standard_format_returns_expected_schema(): void
    {
        $schema = CitationService::getStandardFormat();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('citations', $schema);
        $this->assertArrayHasKey('textSegments', $schema);
        $this->assertArrayHasKey('searchMetadata', $schema);
    }

    public function test_register_formatter_allows_custom_formatter(): void
    {
        $customFormatter = new GoogleCitationFormatter();
        
        $this->citationService->registerFormatter($customFormatter);

        // Test that the formatter is registered by calling formatCitations
        $providerData = [
            'groundingChunks' => [
                [
                    'web' => [
                        'title' => 'Custom Test',
                        'uri' => 'https://custom.com',
                        'snippet' => 'Custom snippet',
                    ],
                ],
            ],
        ];

        $result = $this->citationService->formatCitations('google', $providerData, 'test');

        $this->assertIsArray($result);
        $this->assertEquals('Custom Test', $result['citations'][0]['title']);
    }
}
