<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\Value\ProviderConfig;
use PHPUnit\Framework\TestCase;

class ProviderConfigHawkiToolsTest extends TestCase
{
    public function test_provider_without_hawki_tools_overrides_nothing(): void
    {
        $config = new ProviderConfig('anthropic-usa', [
            'active' => true,
            'adapter' => 'Anthropic',
        ]);

        $this->assertSame([], $config->getHawkiTools());
        $this->assertFalse($config->isHawkiToolOverridden('web_search'));
        $this->assertNull($config->getHawkiToolBinding('web_search'));
    }

    public function test_override_is_read_from_hawki_tools(): void
    {
        $config = new ProviderConfig('ki-at-jlu', [
            'active' => true,
            'adapter' => 'OpenAiHawkiTools',
            'hawki_tools' => [
                'web_search' => ['override' => true, 'binding' => 'websearch-mcp'],
            ],
        ]);

        $this->assertTrue($config->isHawkiToolOverridden('web_search'));
        $this->assertSame('websearch-mcp', $config->getHawkiToolBinding('web_search'));
    }

    public function test_override_false_keeps_the_native_tool(): void
    {
        $config = new ProviderConfig('ki-at-jlu', [
            'hawki_tools' => [
                'web_search' => ['override' => false, 'binding' => 'websearch-mcp'],
            ],
        ]);

        $this->assertFalse($config->isHawkiToolOverridden('web_search'));
    }

    public function test_unknown_tool_is_never_overridden(): void
    {
        $config = new ProviderConfig('ki-at-jlu', [
            'hawki_tools' => [
                'web_search' => ['override' => true],
            ],
        ]);

        $this->assertFalse($config->isHawkiToolOverridden('code_execution'));
        $this->assertNull($config->getHawkiToolBinding('code_execution'));
    }

    public function test_missing_binding_is_null_not_empty_string(): void
    {
        $config = new ProviderConfig('ki-at-jlu', [
            'hawki_tools' => [
                'web_search' => ['override' => true, 'binding' => ''],
            ],
        ]);

        $this->assertTrue($config->isHawkiToolOverridden('web_search'));
        $this->assertNull($config->getHawkiToolBinding('web_search'));
    }

    public function test_malformed_hawki_tools_section_is_ignored(): void
    {
        $config = new ProviderConfig('ki-at-jlu', [
            'hawki_tools' => 'not-an-array',
        ]);

        $this->assertSame([], $config->getHawkiTools());
        $this->assertFalse($config->isHawkiToolOverridden('web_search'));
    }
}
