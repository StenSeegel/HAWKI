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

        $this->assertStringContainsString('raw JSON array', $prompt);
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
        $this->assertStringContainsString('friendly and warm', $prompt);
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

    public function test_system_prompt_contains_html_preservation_rule(): void
    {
        $prompt = $this->callGetSystemPrompt('default');

        $this->assertStringContainsString('PRESERVE HTML', $prompt);
        $this->assertStringContainsString('EXACTLY', $prompt);
        $this->assertStringContainsString('NO EXTRA CONTENT', $prompt);
    }

    public function test_proofread_system_prompt_is_valid(): void
    {
        $prompt = $this->callGetSystemPrompt('proofread');

        $this->assertStringContainsString('proofreader', $prompt);
        $this->assertStringContainsString('Do NOT rewrite or rephrase', $prompt);
    }

    public function test_rephrase_system_prompt_is_valid(): void
    {
        $prompt = $this->callGetSystemPrompt('rephrase');

        $this->assertStringContainsString('copywriter', $prompt);
        $this->assertStringContainsString('retaining the original meaning', $prompt);
    }

    public function test_key_points_system_prompt_is_valid(): void
    {
        $prompt = $this->callGetSystemPrompt('key_points');

        $this->assertStringContainsString('summarizing assistant', $prompt);
        $this->assertStringContainsString('bulleted list', $prompt);
        $this->assertStringContainsString('ONLY the most critical key facts', $prompt);
        $this->assertStringContainsString('FORMATTING RULE: You MUST use standard Markdown bullet points', $prompt);
    }

    public function test_paraphrase_system_prompt_is_valid(): void
    {
        $prompt = $this->callGetSystemPrompt('paraphrase');

        $this->assertStringContainsString('paraphrasing', $prompt);
        $this->assertStringContainsString('entirely different words', $prompt);
    }

    public function test_shorten_system_prompt_is_valid(): void
    {
        $prompt = $this->callGetSystemPrompt('shorten');

        $this->assertStringContainsString('precise editor', $prompt);
        $this->assertStringContainsString('drastically shorten', $prompt);
    }

    public function test_expand_system_prompt_is_valid(): void
    {
        $prompt = $this->callGetSystemPrompt('expand');

        $this->assertStringContainsString('detailed writer', $prompt);
        $this->assertStringContainsString('elaborating on the ideas', $prompt);
        $this->assertStringContainsString('bullet points or numbered list', $prompt);
        $this->assertStringContainsString('continuous, well-structured prose/narrative flow', $prompt);
    }

    public function test_list_system_prompt_is_valid(): void
    {
        $prompt = $this->callGetSystemPrompt('list');

        $this->assertStringContainsString('organization assistant', $prompt);
        $this->assertStringContainsString('beautifully formatted list', $prompt);
        $this->assertStringContainsString('WITHOUT reducing or summarizing', $prompt);
        $this->assertStringContainsString('FORMATTING RULE: You MUST use standard Markdown bullet points', $prompt);
    }

    public function test_table_system_prompt_is_valid(): void
    {
        $prompt = $this->callGetSystemPrompt('table');

        $this->assertStringContainsString('data presentation expert', $prompt);
        $this->assertStringContainsString('Markdown table', $prompt);
    }

    public function test_compose_system_prompt_is_valid(): void
    {
        $prompt = $this->callGetSystemPrompt('compose');

        $this->assertStringContainsString('co-writer', $prompt);
        $this->assertStringContainsString('compose new text', $prompt);
        $this->assertStringContainsString('CODE / FLOWCHART FORMATTING', $prompt);
    }

    public function test_improve_text_strips_html_whitespaces(): void
    {
        $aiService = $this->createMock(\App\Services\AI\AiService::class);
        $usageLogger = $this->createMock(\App\Services\Translation\TranslationUsageLogger::class);
        $translationService = $this->createMock(\App\Services\Translation\TranslationService::class);
        $composeAgentService = $this->createMock(\App\Services\Translation\ComposeAgentService::class);

        $aiResponse = new \App\Services\AI\Value\AiResponse(
            content: ['text' => "<ul>\n  <li>Item 1</li>\n  <li>Item 2</li>\n</ul>"]
        );

        $aiService->method('sendRequest')->willReturn($aiResponse);
        $translationService->method('resolveDefaultModelForType')->willReturn('mock-model');
        $translationService->method('shouldShowDebug')->willReturn(false);

        $service = new TextImprovementService($aiService, $usageLogger, $translationService, $composeAgentService);

        $result = $service->improveText('some input text', type: 'list');

        $this->assertEquals('<ul><li>Item 1</li><li>Item 2</li></ul>', $result['text']);
    }

    public function test_improve_text_does_not_strip_code_blocks_with_specific_languages(): void
    {
        $aiService = $this->createMock(\App\Services\AI\AiService::class);
        $usageLogger = $this->createMock(\App\Services\Translation\TranslationUsageLogger::class);
        $translationService = $this->createMock(\App\Services\Translation\TranslationService::class);
        $composeAgentService = $this->createMock(\App\Services\Translation\ComposeAgentService::class);

        $mermaidContent = "```mermaid\ngraph TD\n  A --> B\n```";
        $composeAgentService->method('compose')->willReturn([
            'text' => $mermaidContent,
            'usage' => null,
        ]);

        $translationService->method('resolveDefaultModelForType')->willReturn('mock-model');
        $translationService->method('shouldShowDebug')->willReturn(false);

        $service = new TextImprovementService($aiService, $usageLogger, $translationService, $composeAgentService);

        $result = $service->improveText('create a flowchart', type: 'compose');

        $this->assertEquals($mermaidContent, $result['text']);
    }
}
