<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\Tools\ToolArgumentSanitizer;
use PHPUnit\Framework\TestCase;

class ToolArgumentSanitizerTest extends TestCase
{
    private ToolArgumentSanitizer $sanitizer;

    private array $schema = [
        'type' => 'object',
        'properties' => [
            'query' => ['type' => 'string'],
        ],
        'required' => ['query'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new ToolArgumentSanitizer();
    }

    public function test_clean_arguments_pass_through(): void
    {
        $result = $this->sanitizer->sanitize('{"query": "current weather in Giessen"}', $this->schema);

        $this->assertTrue($result['ok']);
        $this->assertSame(['query' => 'current weather in Giessen'], $result['arguments']);
    }

    public function test_strips_the_gemma_chat_template_artifact(): void
    {
        // Verbatim output observed from jlu/gemma-4-26b-it: valid JSON, but the
        // value carries a leaked chat template token.
        $raw = '{"query": "current weather in Giessen Germany<|\"|>"}';

        $result = $this->sanitizer->sanitize($raw, $this->schema);

        $this->assertTrue($result['ok']);
        $this->assertSame('current weather in Giessen Germany', $result['arguments']['query']);
        $this->assertStringNotContainsString('<|', $result['arguments']['query']);
    }

    public function test_strips_template_tokens_from_nested_values(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['urls' => ['type' => 'array']],
            'required' => ['urls'],
        ];

        $result = $this->sanitizer->sanitize(
            '{"urls": ["https://example.com<|end|>", "https://example.org"]}',
            $schema
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(
            ['https://example.com', 'https://example.org'],
            $result['arguments']['urls']
        );
    }

    public function test_recovers_a_json_object_wrapped_in_prose(): void
    {
        $result = $this->sanitizer->sanitize(
            'Sure, here you go: {"query": "php 8.4 release"} - hope that helps',
            $this->schema
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('php 8.4 release', $result['arguments']['query']);
    }

    public function test_rejects_arguments_that_are_not_json(): void
    {
        $result = $this->sanitizer->sanitize('current weather in Giessen', $this->schema);

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
    }

    public function test_rejects_a_missing_required_argument(): void
    {
        $result = $this->sanitizer->sanitize('{"unrelated": "value"}', $this->schema);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('query', (string) $result['error']);
    }

    public function test_rejects_an_argument_that_is_empty_after_stripping(): void
    {
        // Nothing but a template token: the value collapses to an empty string,
        // which must not reach the tool as a search for "".
        $result = $this->sanitizer->sanitize('{"query": "<|\"|>"}', $this->schema);

        $this->assertFalse($result['ok']);
    }

    public function test_rejects_a_wrongly_typed_argument(): void
    {
        $result = $this->sanitizer->sanitize('{"query": 42}', $this->schema);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('string', (string) $result['error']);
    }

    public function test_drops_arguments_the_schema_does_not_declare(): void
    {
        $result = $this->sanitizer->sanitize(
            '{"query": "test", "max_results": 99, "unsafe": "x"}',
            $this->schema
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(['query' => 'test'], $result['arguments']);
    }
}
