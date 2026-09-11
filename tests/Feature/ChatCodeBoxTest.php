<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Fenced code in a chat message has to render as a code box, the same one the
 * create mode editor shows. The styles were scoped to the editor only, so chat
 * rendered plain unstyled text.
 */
class ChatCodeBoxTest extends TestCase
{
    private function stylesheet(): string
    {
        return file_get_contents(public_path('css/hljs_custom.css'));
    }

    private function chatScript(): string
    {
        return file_get_contents(public_path('js/syntax_modifier.js'));
    }

    public function test_the_code_box_styles_reach_chat_messages(): void
    {
        $css = $this->stylesheet();

        // The rule that paints the box itself.
        $this->assertMatchesRegularExpression(
            '/\.tiptap-container \.ProseMirror pre,\s*\n\.message-text pre \{/',
            $css
        );
    }

    public function test_no_code_box_rule_is_left_editor_only(): void
    {
        foreach (explode("\n", $this->stylesheet()) as $number => $line) {
            if (! str_starts_with(trim($line), '.tiptap-container .ProseMirror')) {
                continue;
            }

            // Every editor selector is paired with a chat one, so the two views
            // cannot drift apart again.
            $this->assertStringEndsWith(
                ',',
                trim($line),
                'line '.($number + 1).' styles the editor only: '.trim($line)
            );
        }
    }

    public function test_the_header_carries_the_language_and_the_actions(): void
    {
        $js = $this->chatScript();

        // The language label is rendered by the stylesheet (pre::before); the
        // markup only adds the action buttons.
        $this->assertStringNotContainsString("classList.add('hljs-code-header')", $js);
        $this->assertStringContainsString('buildCodeActions(block, language)', $js);
        $this->assertMatchesRegularExpression('/\.message-text pre::before \{/', $this->stylesheet());
    }

    public function test_python_gets_a_run_button_and_others_do_not(): void
    {
        $js = $this->chatScript();

        $this->assertMatchesRegularExpression(
            "/if \(language === 'python' \|\| language === 'py'\) \{\s*\n\s*actions\.appendChild\(buildRunButton\(block\)\);/",
            $js
        );
    }

    public function test_the_run_button_uses_the_chat_gated_route(): void
    {
        // Not /req/text/execute-python: that one sits behind textAccess, which a
        // chat-only user does not have.
        $this->assertStringContainsString("fetch('/req/conv/executeCode'", $this->chatScript());

        $routes = collect(app('router')->getRoutes())->first(
            fn ($route) => $route->uri() === 'req/conv/executeCode'
        );

        $this->assertNotNull($routes, 'the chat code execution route must exist');
        $this->assertContains('chatAccess', $routes->gatherMiddleware());
    }

    public function test_the_chat_chrome_is_styled(): void
    {
        $css = $this->stylesheet();

        foreach ([
            '.message-text .code-block-wrapper',
            '.message-text .code-actions',
            '.message-text .editor-copy-btn',
            '.message-text .editor-minimize-btn',
            '.message-text .editor-run-code-btn',
            '.message-text .editor-code-output-content',
            '.message-text .code-block-wrapper.minimized pre code',
            '.message-text pre::before',
        ] as $selector) {
            $this->assertStringContainsString($selector, $css, $selector.' is unstyled');
        }
    }

    /**
     * A long listing folds itself when the message is complete, so it does not
     * push the answer off the screen - but not while it streams, and not in an
     * export. Its run output stays visible: that is the result the reader wants.
     */
    public function test_long_code_folds_itself_once_the_message_is_complete(): void
    {
        $js = $this->chatScript();

        $this->assertStringContainsString('const AUTO_MINIMIZE_LINES = 20;', $js);
        $this->assertStringContainsString('function formatHljs(messageElement, { collapseLongCode = true, renderDiagrams = true } = {})', $js);
        $this->assertStringContainsString(
            "codeLineCount(block) > AUTO_MINIMIZE_LINES) {\n      setCodeBoxMinimized(wrapper, true, true);",
            $js
        );

        $this->assertStringContainsString(
            'formatHljs(messageElement, { collapseLongCode: false, renderDiagrams: false });',
            file_get_contents(public_path('js/ai_chat_functions.js')),
            'a streaming answer must not fold its code'
        );
        $this->assertStringContainsString(
            'formatHljs(messageElement, { collapseLongCode: false, renderDiagrams: false });',
            file_get_contents(public_path('js/export.js')),
            'an export must not fold its code'
        );

        $this->assertStringContainsString(
            '.message-text .code-block-wrapper.minimized.auto-minimized .editor-code-output-container:not(.hidden)',
            $this->stylesheet()
        );
    }

    public function test_both_languages_label_the_buttons(): void
    {
        foreach (['en_US', 'de_DE'] as $language) {
            $texts = json_decode(file_get_contents(resource_path("language/{$language}.json")), true);

            foreach (['RunCode', 'RunningCode', 'Output', 'CodeNoOutput', 'Copied'] as $key) {
                $this->assertNotEmpty($texts[$key] ?? '', $language.' is missing '.$key);
            }
        }
    }

    public function test_the_output_panel_is_built_without_stray_text_nodes(): void
    {
        $js = $this->chatScript();

        // A template literal's newlines and indentation become text nodes inside
        // the panel, and the leading one renders as an empty line above the
        // header - which looked like unexplained padding.
        $this->assertStringNotContainsString(
            "output.innerHTML = `",
            $js
        );
        $this->assertStringContainsString("outputHeader.classList.add('editor-code-output-header')", $js);
        $this->assertStringContainsString('output.appendChild(outputHeader);', $js);
    }

    /**
     * The ```output block the code interpreter writes under its code is the
     * output OF that box, not a second code box. It is folded into the box's own
     * output panel - the same one the run button fills - so a model's run and a
     * user's run look identical.
     */
    public function test_the_code_interpreter_output_block_is_folded_into_the_output_panel(): void
    {
        $script = $this->chatScript();

        $this->assertStringContainsString('function foldOutputIntoPreviousCodeBox(', $script);

        // Folded when the output block is reached, not the code block: formatHljs
        // walks in document order, so the code box above is wrapped by then.
        $this->assertMatchesRegularExpression(
            '/if \(language === .output.\) \{\s*\n\s*foldOutputIntoPreviousCodeBox\(block\);/',
            $script
        );

        // It has to reuse the run button's panel, not build its own markup.
        $this->assertMatchesRegularExpression(
            '/foldOutputIntoPreviousCodeBox[\s\S]{0,1600}?ensureCodeOutput\(target\)/',
            $script
        );
        $this->assertMatchesRegularExpression(
            '/foldOutputIntoPreviousCodeBox[\s\S]{0,1600}?renderCodeOutput\(/',
            $script
        );

        // And the extra box must go, or the output shows twice.
        $this->assertMatchesRegularExpression(
            '/foldOutputIntoPreviousCodeBox[\s\S]{0,1600}?wrapper\.remove\(\)/',
            $script
        );
    }

    public function test_a_failed_run_is_shown_with_the_panels_error_styling(): void
    {
        $this->assertStringContainsString(
            'Traceback \(most recent call last\)',
            $this->chatScript()
        );

        $this->assertStringContainsString('.message-text .editor-code-output-content.error', $this->stylesheet());
    }
}
