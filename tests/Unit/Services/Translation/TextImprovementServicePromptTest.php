<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Translation;

use App\Services\Translation\TextImprovementService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class TextImprovementServicePromptTest extends TestCase
{
    private function callGetSystemPrompt(
        string $type,
        bool $isBatch = false,
        ?string $sourceLang = null,
        ?string $targetLang = null,
        ?string $style = null,
        ?string $tone = null,
        ?string $formality = null,
        ?array $exclusions = null,
        ?string $context = null
    ): string {
        $service = $this->createMock(TextImprovementService::class);

        $reflection = new ReflectionMethod(TextImprovementService::class, 'getSystemPrompt');

        return $reflection->invoke($service, $type, $isBatch, $sourceLang, $targetLang, $style, $tone, $formality, $exclusions, $context);
    }

    public function test_synonym_system_prompt_contains_json_format_example(): void
    {
        $prompt = $this->callGetSystemPrompt('synonyms');

        $this->assertStringContainsString('JSON array containing 5 strings', $prompt);
        $this->assertStringContainsString('["Word 1", "Word 2"', $prompt);
    }

    public function test_synonym_system_prompt_contains_grammar_rule(): void
    {
        $prompt = $this->callGetSystemPrompt('synonyms');

        $this->assertStringContainsString('GRAMMAR', $prompt);
        $this->assertStringContainsString('case, number, gender', $prompt);
    }

    public function test_synonym_system_prompt_contains_context_rule(): void
    {
        $prompt = $this->callGetSystemPrompt('synonyms');

        $this->assertStringContainsString('CONTEXT', $prompt);
        $this->assertStringContainsString('semantically perfectly', $prompt);
    }

    public function test_correction_system_prompt_contains_grammar_rules(): void
    {
        $prompt = $this->callGetSystemPrompt('correction');

        $this->assertStringContainsString('grammar', $prompt);
        $this->assertStringContainsString('spelling', $prompt);
        $this->assertStringContainsString('separable verbs', $prompt);
        $this->assertStringContainsString('correction assistant', $prompt);
    }

    public function test_system_prompt_contains_style_and_tone_instructions(): void
    {
        $prompt = $this->callGetSystemPrompt('default', false, null, null, 'formal', 'friendly');

        $this->assertStringContainsString('formal, professional style', $prompt);
        $this->assertStringContainsString('friendly, warm tone', $prompt);
    }

    public function test_system_prompt_contains_exclusions(): void
    {
        $exclusions = ['Var 1', 'Var 2'];
        $prompt = $this->callGetSystemPrompt('alternatives', false, null, null, null, null, null, $exclusions);

        $this->assertStringContainsString('Var 1', $prompt);
        $this->assertStringContainsString('Var 2', $prompt);
        $this->assertStringContainsString('SIGNIFICANTLY', $prompt);
    }

    public function test_default_system_prompt_is_valid(): void
    {
        $prompt = $this->callGetSystemPrompt('default');

        $this->assertStringContainsString('text improvement', $prompt);
        $this->assertStringContainsString('spelling', $prompt);
    }

    public function test_alternatives_system_prompt_is_valid(): void
    {
        $prompt = $this->callGetSystemPrompt('alternatives');

        $this->assertStringContainsString('creative text improvement', $prompt);
    }
}
