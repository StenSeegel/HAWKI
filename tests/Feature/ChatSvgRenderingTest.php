<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * An SVG a model writes into its answer, or a sandbox prints, has to show up as
 * a picture. It was showing up as source text: markdown-it runs with html off,
 * the code box drew no preview, and the output panel only knew PNG base64.
 *
 * Pinned the way ChatCodeBoxTest pins the code box: on the delivered script and
 * stylesheet, since there is no JS test runner in this project.
 */
class ChatSvgRenderingTest extends TestCase
{
    private function chatScript(): string
    {
        return file_get_contents(public_path('js/syntax_modifier.js'));
    }

    /**
     * The picture stands in for the code, as a mermaid diagram does in the
     * create mode editor; the toggle in the box's actions brings the code back.
     */
    public function test_a_fenced_svg_block_shows_the_drawing_with_the_code_one_click_away(): void
    {
        $js = $this->chatScript();

        $this->assertStringContainsString("if (kind === 'svg' || (kind === 'mermaid' && renderDiagrams)) {\n      buildDiagramView(block, kind);", $js);

        // Drawn as an <img> with a data URI: an image never runs a script.
        $this->assertStringContainsString("img.setAttribute('src', svgDataUri(block.textContent));", $js);
        $this->assertStringContainsString("'data:image/svg+xml;base64,' + base64Utf8(", $js);

        // The download button sits in the box's corner, not on the picture, for
        // SVG and mermaid alike; the message-wide framing leaves the box alone.
        $this->assertSame(2, substr_count($js, 'addDiagramDownloadButton(preview);'));
        $this->assertStringContainsString(
            "image.closest('.generated-image-frame, .inline-image-frame, .diagram-preview')",
            file_get_contents(public_path('js/message_functions.js'))
        );
        $this->assertStringContainsString(
            '.message-text .diagram-preview .image-download-btn {',
            file_get_contents(public_path('css/hljs_custom.css'))
        );

        // The toggle sits with the other actions and says what a click brings.
        $this->assertStringContainsString("toggle.classList.add('editor-toggle-btn');", $js);
        $this->assertStringContainsString("setDiagramMode(wrapper, !wrapper.classList.contains('diagram-active'));", $js);
        $this->assertStringContainsString("(translation?.CodeView || 'Code')", $js);
        $this->assertStringContainsString("(translation?.DiagramView || 'Diagram')", $js);

        // Only a finished drawing - a streaming block has no closing tag yet.
        $this->assertStringContainsString('function isCompleteSvg(text)', $js);
        $this->assertStringContainsString("['svg', 'xml', 'html'].includes(lang)", $js);

        $css = file_get_contents(public_path('css/hljs_custom.css'));
        $this->assertStringContainsString('.message-text .code-block-wrapper.diagram-active > pre {', $css);
        $this->assertStringContainsString('.message-text .code-block-wrapper.diagram-active > .diagram-preview {', $css);
        $this->assertStringContainsString('.message-text .code-block-wrapper.minimized .diagram-preview {', $css);
    }

    /**
     * Mermaid needs its library and a finished diagram: drawn when the message
     * is complete, never while it streams, never in an export. A diagram the
     * library cannot draw stays code, and strict security because the text is
     * a model's.
     */
    public function test_mermaid_is_drawn_only_for_complete_messages(): void
    {
        $js = $this->chatScript();

        $this->assertStringContainsString("if (lang === 'mermaid') {\n    return 'mermaid';", $js);
        $this->assertStringContainsString("securityLevel: 'strict'", $js);
        $this->assertStringContainsString('https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js', $js);

        // Invalid diagram: the code stays, and no toggle leads to an empty frame.
        $this->assertMatchesRegularExpression('/if \(ok\) \{\s*addDiagramDownloadButton\(preview\);\s*setDiagramMode\(wrapper, true\);\s*\} else \{[^}]*toggle\.remove\(\);\s*preview\.remove\(\);/', $js);

        foreach (['ai_chat_functions.js', 'export.js'] as $file) {
            $this->assertStringContainsString(
                'renderDiagrams: false',
                file_get_contents(public_path('js/'.$file)),
                $file.' must not draw diagrams'
            );
        }
    }

    public function test_a_picture_box_is_not_folded_for_its_length(): void
    {
        $this->assertStringContainsString(
            "if (collapseLongCode && !wrapper.classList.contains('diagram-active')",
            $this->chatScript()
        );
    }

    public function test_both_languages_label_the_toggle(): void
    {
        foreach (['en_US', 'de_DE'] as $language) {
            $texts = json_decode(file_get_contents(resource_path("language/{$language}.json")), true);

            foreach (['CodeView', 'DiagramView'] as $key) {
                $this->assertNotEmpty($texts[$key] ?? '', $language.' is missing '.$key);
            }
        }
    }

    public function test_svg_markup_written_into_the_answer_is_fenced(): void
    {
        $js = $this->chatScript();

        $this->assertStringContainsString('let processed = fenceRawSvg(segment)', $js);
        $this->assertStringContainsString("'\\n```svg\\n' + match.trim() + '\\n```\\n'", $js);
    }

    public function test_the_output_panel_lifts_svgs_as_well_as_pngs(): void
    {
        $js = $this->chatScript();

        $this->assertStringContainsString('liftSvgsOutOfOutput(String(text), inlineImages).replace(PNG_OUTPUT_REGEX', $js);

        foreach (['SVG_BASE64_OUTPUT_REGEX', 'SVG_DATA_URI_OUTPUT_REGEX', 'SVG_MARKUP_OUTPUT_REGEX'] as $pattern) {
            $this->assertStringContainsString('.replace('.$pattern.',', $js);
        }
    }

    public function test_a_namespace_the_model_left_out_is_declared(): void
    {
        $js = $this->chatScript();

        $this->assertStringContainsString('declareSvgNamespaces(String(markup).trim())', $js);
        $this->assertStringContainsString('<svg xmlns="http://www.w3.org/2000/svg"', $js);
        $this->assertStringContainsString('<svg xmlns:xlink="http://www.w3.org/1999/xlink"', $js);
    }

    public function test_a_preview_with_only_a_view_box_is_given_a_size(): void
    {
        $js = $this->chatScript();

        $this->assertStringContainsString('base64Utf8(giveSvgIntrinsicSize(declareSvgNamespaces(', $js);
        $this->assertStringContainsString('function giveSvgIntrinsicSize(markup)', $js);
    }

    public function test_a_linked_image_file_from_the_container_is_shown(): void
    {
        $js = $this->chatScript();

        $this->assertStringContainsString(
            "if (isImageFileName(name) && !shown().includes(url) && !link.querySelector('img'))",
            $js
        );
    }

    /**
     * The picture of the reported conversation: a viewBox, no width, no height.
     * Visible while streaming, 0x0 once the finished message got its download
     * frame. Files stored from now on get a size; this keeps the old ones visible.
     */
    public function test_a_size_less_image_is_not_collapsed_by_its_download_frame(): void
    {
        $js = file_get_contents(public_path('js/message_functions.js'));

        $this->assertStringContainsString('keepSizelessImageVisible(image, frame);', $js);
        $this->assertStringContainsString("frame.style.display = 'block';", $js);
    }

    public function test_a_downloaded_svg_keeps_its_extension(): void
    {
        $js = file_get_contents(public_path('js/message_functions.js'));

        $this->assertStringContainsString("? 'image.svg' : 'image.png'", $js);
    }

    /**
     * The chat log builds its messages before they are shown, so a decision
     * that needs a layout waits until the frame is visible.
     */
    public function test_the_frame_decision_waits_for_a_layout(): void
    {
        $js = file_get_contents(public_path('js/message_functions.js'));

        $this->assertStringContainsString("if (!image.complete || !frame.isConnected || frame.offsetParent === null) {\n            return false;", $js);
        $this->assertStringContainsString('setTimeout(tick, 250);', $js);
    }
}
