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

    public function test_a_stale_header_is_removed_before_a_new_one_is_added(): void
    {
        $js = $this->chatScript();

        // Swept across the whole message, not just the <pre> being rebuilt: a
        // stale header can sit anywhere after a re-render.
        $this->assertStringContainsString(
            "messageElement.querySelectorAll('.hljs-code-header').forEach((stale) => stale.remove());",
            $js
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
        $this->assertStringNotContainsString("classList.add('chat-copy-code-btn')", $js);
        $this->assertStringContainsString("classList.add('chat-run-code-btn')", $js);
    }

    public function test_the_run_button_still_reaches_the_header(): void
    {
        $js = $this->chatScript();

        $this->assertStringContainsString('header.appendChild(buildCodeActions(block, language));', $js);
        $this->assertStringContainsString('actions.appendChild(buildRunButton(block));', $js);
    }
}
