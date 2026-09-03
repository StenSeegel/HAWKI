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

        $this->assertStringContainsString("classList.add('hljs-code-header')", $js);
        $this->assertStringContainsString('buildCodeActions(block, language)', $js);
        $this->assertStringContainsString("classList.add('hljs-lang-name')", $js);
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
            '.message-text .hljs-code-header',
            '.message-text .chat-run-code-btn',
            '.message-text .chat-code-output',
            '.message-text .chat-code-output-content',
        ] as $selector) {
            $this->assertStringContainsString($selector, $css, $selector.' is unstyled');
        }
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
}
