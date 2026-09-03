<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A code box must end up with exactly one header, and it must keep it.
 *
 * Streaming re-renders the message on every chunk and the final render replaces
 * the markup once more. With a "does one already exist?" guard the fresh header
 * landed next to a stale one, and with no highlighting on the final render the
 * header with the buttons disappeared when the stream finished.
 */
class ChatCodeBoxHeaderTest extends TestCase
{
    private function chatScript(): string
    {
        return file_get_contents(public_path('js/syntax_modifier.js'));
    }

    public function test_the_box_is_rebuilt_rather_than_appended_to(): void
    {
        $js = $this->chatScript();

        // Rebuilt from scratch on every render, so nothing piles up across
        // streaming chunks and the final render.
        $this->assertStringContainsString(
            "wrapper.querySelectorAll('.hljs-code-header, .code-actions').forEach((stale) => stale.remove());",
            $js
        );
    }

    public function test_the_language_label_is_rendered_only_once(): void
    {
        // The stylesheet already labels a code box through pre::before. Adding a
        // header element for it as well is what showed the language twice.
        $this->assertStringNotContainsString(
            "classList.add('hljs-code-header')",
            $this->chatScript()
        );
        $this->assertStringNotContainsString(
            '.message-text .hljs-code-header {',
            file_get_contents(public_path('css/hljs_custom.css'))
        );
    }

    public function test_the_header_is_no_longer_skipped_when_one_exists(): void
    {
        // The old guard is what allowed a second header to survive a re-render.
        $this->assertStringNotContainsString(
            "if (!block.parentElement.querySelector('.hljs-code-header'))",
            $this->chatScript()
        );
    }

    public function test_the_final_render_rebuilds_the_code_box(): void
    {
        $js = file_get_contents(public_path('js/message_functions.js'));

        // updateMessageElement replaces .message-text; without this the header
        // and its run button are gone once the stream completes.
        $this->assertMatchesRegularExpression(
            '/msgTxtElement\.innerHTML = markdownProcessed;\s*\n\s*formatMathFormulas\(msgTxtElement\);\s*\n(\s*\/\/[^\n]*\n)*\s*formatHljs\(messageElement\);/',
            $js
        );
    }

    public function test_only_one_copy_button_is_offered(): void
    {
        $js = $this->chatScript();

        // The chat appends its own .copy-btn into this header from a template in
        // activateMessageControls(); a second one here would sit next to it.
        // Ours carries .copy-btn, and activateMessageControls() looks for one in
        // the whole box before adding the chat's own.
        $this->assertStringContainsString("classList.add('editor-copy-btn', 'copy-btn')", $js);

        $legacy = file_get_contents(public_path('js/message_functions.js'));
        $this->assertStringContainsString("!box.querySelector('.copy-btn')", $legacy);
    }

    public function test_the_run_button_still_reaches_the_header(): void
    {
        $js = $this->chatScript();

        $this->assertStringContainsString('wrapper.appendChild(buildCodeActions(block, language));', $js);
        $this->assertStringContainsString('actions.appendChild(buildRunButton(block));', $js);
        $this->assertStringContainsString('actions.appendChild(buildMinimizeButton());', $js);
    }

    public function test_the_box_offers_the_same_controls_as_the_create_mode_editor(): void
    {
        $js = $this->chatScript();
        $editor = file_get_contents(public_path('js/translate/TextCreateApp.js'));

        // Same classes, so the two code boxes can share one stylesheet and look
        // alike: copy, minimize and run.
        foreach (['editor-copy-btn', 'editor-minimize-btn', 'editor-run-code-btn', 'code-actions'] as $class) {
            $this->assertStringContainsString($class, $js, 'chat is missing '.$class);
            $this->assertStringContainsString($class, $editor, 'the editor no longer uses '.$class);
        }
    }

    public function test_minimize_toggles_the_wrapper(): void
    {
        $js = $this->chatScript();

        $this->assertStringContainsString("wrapper.classList.toggle('minimized')", $js);
        $this->assertStringContainsString('MINIMIZE_ICON', $js);
        $this->assertStringContainsString('MAXIMIZE_ICON', $js);
    }
}
