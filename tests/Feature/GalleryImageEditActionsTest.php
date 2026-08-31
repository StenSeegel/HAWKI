<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class GalleryImageEditActionsTest extends TestCase
{
    private const RATIOS = ['1:1', '3:4', '9:16', '4:3', '16:9'];

    private function renderGallery(string $language = 'en_US'): string
    {
        $translation = json_decode(
            file_get_contents(resource_path("language/{$language}.json")),
            true
        );

        return Blade::render(
            file_get_contents(resource_path('views/partials/home/modals/image-gallery-modal.blade.php')),
            ['translation' => $translation]
        );
    }

    public function test_both_actions_are_offered(): void
    {
        $html = $this->renderGallery();

        $this->assertStringContainsString('onclick="commentOnGalleryImage()"', $html);
        $this->assertStringContainsString('onclick="removeGalleryImageBackground()"', $html);
        $this->assertStringContainsString('onclick="toggleGalleryRatioMenu(this)"', $html);
        $this->assertStringContainsString('Comment', $html);
        $this->assertStringContainsString('Remove background', $html);
        $this->assertStringContainsString('Change size', $html);
    }

    public function test_comment_attaches_the_image_and_focuses_the_input(): void
    {
        $js = file_get_contents(public_path('js/message_functions.js'));
        $body = $this->functionBody($js, 'function commentOnGalleryImage() {');

        // Acts on the image the gallery is showing, whichever turn it came from.
        $this->assertStringContainsString('const image = galleryImageSource;', $body);
        $this->assertStringContainsString('removeStoredAttachments(inputField)', $body);
        $this->assertStringContainsString('attachStoredFile(inputField, {', $body);
        $this->assertStringContainsString('uuid: image.dataset.uuid,', $body);

        // Hands the caret over instead of sending anything.
        $this->assertStringContainsString('inputField.focus();', $body);
        $this->assertStringNotContainsString('sendMessageConv', $body);
        $this->assertStringNotContainsString('enableImageGeneration', $body);
        $this->assertStringNotContainsString('inputField.value', $body);
    }

    public function test_the_comment_tool_is_localized(): void
    {
        $this->assertStringContainsString('>Comment<', $this->renderGallery('en_US'));
        $this->assertStringContainsString('>Kommentieren<', $this->renderGallery('de_DE'));
    }

    public function test_the_tools_sit_in_a_toolbar_on_the_image(): void
    {
        $html = $this->renderGallery();

        // Inside the frame that wraps the picture, not in the prompt column.
        $frameAt = strpos($html, 'gallery-image-frame');
        $toolbarAt = strpos($html, 'gallery-toolbar');
        $promptAt = strpos($html, 'gallery-prompt');

        $this->assertNotFalse($toolbarAt);
        $this->assertLessThan($toolbarAt, $frameAt);
        $this->assertLessThan($promptAt, $toolbarAt);

        $css = file_get_contents(public_path('css/chat_modules.css'));

        // Pinned to the top edge of the picture, by the area that spans it.
        $area = $this->cssRule($css, '#image-gallery-modal .gallery-toolbar-area');
        $this->assertStringContainsString('position: absolute;', $area);
        $this->assertStringContainsString('top: 0.75rem;', $area);

        // And the menu drops downward out of it.
        $menu = $this->cssRule($css, '#image-gallery-modal .gallery-ratio-menu');
        $this->assertStringContainsString('top: calc(100% + 0.5rem);', $menu);
    }

    public function test_the_toolbar_reacts_to_the_image_width_not_the_viewport(): void
    {
        $html = $this->renderGallery();
        $css = file_get_contents(public_path('css/chat_modules.css'));

        // A wrapper spanning the picture is the size container.
        $this->assertStringContainsString('gallery-toolbar-area', $html);
        $area = $this->cssRule($css, '#image-gallery-modal .gallery-toolbar-area');
        $this->assertStringContainsString('container-type: inline-size;', $area);
        // Out of flow with both offsets, so its width comes from the frame and it
        // cannot widen the shrink-wrapping frame either.
        $this->assertStringContainsString('position: absolute;', $area);
        $this->assertStringContainsString('left: 0.75rem;', $area);
        $this->assertStringContainsString('right: 0.75rem;', $area);

        // The pill itself no longer positions or clamps itself.
        $bar = $this->cssRule($css, '#image-gallery-modal .gallery-toolbar');
        $this->assertStringNotContainsString('position: absolute;', $bar);
        $this->assertStringNotContainsString('translateX', $bar);
        $this->assertStringContainsString('max-width: 100%;', $bar);

        // Labels drop on a narrow image via a container query, not a media query.
        // 32rem, because the three labelled tools measure 489px in german.
        $this->assertStringContainsString('@container (max-width: 32rem)', $css);
        $this->assertDoesNotMatchRegularExpression(
            '/@media[^{]*\{[^@]*\.gallery-tool span/s',
            $css
        );
    }

    public function test_the_icon_only_tools_keep_a_tooltip(): void
    {
        $html = $this->renderGallery('de_DE');

        // The labels disappear on a narrow image, so the title has to carry them.
        $this->assertStringContainsString('title="Hintergrund entfernen"', $html);
        $this->assertStringContainsString('title="Größe ändern"', $html);
    }

    public function test_the_tools_use_the_supplied_glyphs(): void
    {
        $html = $this->renderGallery();

        // eraser.svg
        $this->assertStringContainsString('m5.082 11.09 8.828 8.828', $html);
        // fullscreen.svg
        $this->assertStringContainsString('rect width="10" height="8" x="7" y="8" rx="1"', $html);

        // Both stroked, both taking their colour from the toolbar.
        $this->assertSame(2, substr_count($html, 'stroke="currentColor"'));
    }

    public function test_the_icons_take_the_toolbars_text_colour_in_both_themes(): void
    {
        // style.css has a global `svg { stroke: var(--stroke-color) }`, and a css
        // rule beats the icons' own stroke="currentColor" attribute - so without
        // restating it the glyphs follow the theme's stroke colour instead of the
        // colour of the label next to them.
        $css = file_get_contents(public_path('css/chat_modules.css'));
        $rule = $this->cssRule($css, '#image-gallery-modal .gallery-tool svg');

        $this->assertStringContainsString('stroke: currentColor;', $rule);
        $this->assertStringContainsString('fill: none;', $rule);
        $this->assertStringContainsString('width: 18px;', $rule);

        // The colour itself is set once, on the tool, for both themes.
        $tool = $this->cssRule($css, '#image-gallery-modal .gallery-tool', 'border-radius');
        $this->assertStringContainsString('color: #f5f5f7;', $tool);

        // The global rule this works around is still the one in style.css.
        $global = file_get_contents(public_path('css/style.css'));
        $this->assertStringContainsString('stroke: var(--stroke-color);', $global);
    }

    /**
     * The rule for a selector. A selector can appear more than once (a base rule
     * plus an override inside a container query), so $mustContain picks the one
     * that is meant.
     */
    private function cssRule(string $css, string $selector, string $mustContain = ''): string
    {
        $offset = 0;
        while (($start = strpos($css, $selector . ' {', $offset)) !== false) {
            $end = strpos($css, '}', $start);
            $rule = substr($css, $start, $end - $start + 1);

            if ($mustContain === '' || str_contains($rule, $mustContain)) {
                return $rule;
            }

            $offset = $end;
        }

        $this->fail("missing rule: {$selector}");
    }

    public function test_every_aspect_ratio_from_the_menu_is_offered(): void
    {
        $html = $this->renderGallery();

        foreach (self::RATIOS as $ratio) {
            $this->assertStringContainsString("applyGalleryAspectRatio('{$ratio}')", $html);
        }

        // The shape is drawn from the ratio, so no icon per format is needed.
        $this->assertStringContainsString('aspect-ratio: 16 / 9;', $html);
        $this->assertStringContainsString('aspect-ratio: 9 / 16;', $html);
    }

    public function test_the_labels_are_localized(): void
    {
        $english = $this->renderGallery('en_US');
        $german = $this->renderGallery('de_DE');

        $this->assertStringContainsString('Remove background', $english);
        $this->assertStringContainsString('Change size', $english);
        $this->assertStringContainsString('Create this image with a different aspect ratio', $english);
        $this->assertStringContainsString('Widescreen', $english);

        $this->assertStringContainsString('Hintergrund entfernen', $german);
        $this->assertStringContainsString('Größe ändern', $german);
        $this->assertStringContainsString('Dieses Bild mit einem anderen Seitenverhältnis erstellen', $german);
        $this->assertStringContainsString('Breitbild', $german);
    }

    public function test_the_prompts_are_localized_and_keep_the_ratio_placeholder(): void
    {
        foreach (['en_US', 'de_DE'] as $language) {
            $keys = json_decode(file_get_contents(resource_path("language/{$language}.json")), true);

            $this->assertArrayHasKey('RemoveBackgroundPrompt', $keys, $language);
            $this->assertArrayHasKey('AspectRatioPrompt', $keys, $language);
            $this->assertStringContainsString('{ratio}', $keys['AspectRatioPrompt'], $language);
        }

        $german = json_decode(file_get_contents(resource_path('language/de_DE.json')), true);

        $this->assertSame(
            'Entferne den Hintergrund aus diesem Bild. Behalte alle Motive im Vordergrund unverändert und '
            . 'vollständig bei, mit sauberen, glatten Kanten. Mache den Hintergrund transparent.',
            $german['RemoveBackgroundPrompt']
        );
        $this->assertSame('Setze das Seitenverhältnis auf {ratio}.', $german['AspectRatioPrompt']);
    }

    public function test_the_handlers_send_the_prompt_with_image_generation_enabled(): void
    {
        $js = file_get_contents(public_path('js/message_functions.js'));

        $this->assertStringContainsString('function removeGalleryImageBackground()', $js);
        $this->assertStringContainsString('function applyGalleryAspectRatio(ratio)', $js);
        $this->assertStringContainsString('async function sendGalleryImagePrompt(prompt, ratio = null)', $js);

        // Reuses the normal send path rather than duplicating it.
        $this->assertStringContainsString('await sendMessageConv(inputField)', $js);
        // And turns image generation on the way the input button does.
        $this->assertStringContainsString("addInputFilter(input.id, 'image_gen')", $js);
        // The prompt template keeps the placeholder substitution.
        $this->assertStringContainsString("template.replace('{ratio}', ratio)", $js);
    }

    public function test_the_actions_attach_the_image_the_gallery_is_showing(): void
    {
        $js = file_get_contents(public_path('js/message_functions.js'));

        // The prompt talks about one specific picture, so that one is attached.
        $this->assertStringContainsString('const image = galleryImageSource;', $js);
        $this->assertStringContainsString('removeStoredAttachments(inputField)', $js);
        $this->assertStringContainsString('attachStoredFile(inputField, {', $js);
        $this->assertStringContainsString('uuid: image.dataset.uuid,', $js);
    }

    public function test_the_ratio_is_a_one_shot_and_does_not_stick(): void
    {
        $js = file_get_contents(public_path('js/message_functions.js'));

        $this->assertStringContainsString("enableImageGeneration(inputField.closest('.input-container'), ratio)", $js);
        $this->assertStringContainsString("delete inputField.closest('.input-container')", $js);

        // And it is cleared with the button too.
        $chatlog = file_get_contents(public_path('js/chatlog_functions.js'));
        $this->assertStringContainsString('delete button.dataset.ratio;', $chatlog);
    }

    public function test_the_newest_generated_image_is_preselected_not_auto_sent(): void
    {
        $js = file_get_contents(public_path('js/message_functions.js'));
        $syntax = file_get_contents(public_path('js/syntax_modifier.js'));
        $attachments = file_get_contents(public_path('js/attachment_handler.js'));

        $this->assertStringContainsString('function preselectGeneratedImage(image)', $js);
        $this->assertStringContainsString('preselectGeneratedImage(', $syntax);

        // Stored files skip the upload and are only referenced.
        $this->assertStringContainsString('function attachStoredFile(inputField, fileData)', $attachments);
        $this->assertStringContainsString('if (attachment.fileData.uuid && !attachment.fileData.file)', $attachments);

        // Nothing is replayed from the auxiliaries any more.
        $converter = file_get_contents(
            base_path('app/Services/AI/Providers/Responses/ResponsesRequestConverter.php')
        );
        $this->assertStringNotContainsString('buildGeneratedImageInput', $converter);
    }

    public function test_the_attach_helpers_resolve_the_input_container(): void
    {
        // Callers pass the .input-field textarea, but the queue is keyed by the
        // .input container's id and the thumbnails live inside it. Reading them
        // off the textarea silently attached nothing at all.
        $js = file_get_contents(public_path('js/attachment_handler.js'));

        // Scoped to the two helpers: handleSelectedFiles() is legitimately called
        // with the container already.
        $attach = $this->functionBody($js, 'function attachStoredFile(inputField, fileData) {');
        $this->assertStringContainsString("inputField?.closest('.input')", $attach);
        $this->assertStringNotContainsString('inputField.id', $attach);
        $this->assertStringNotContainsString("inputField.querySelector('.file-attachments')", $attach);

        $remove = $this->functionBody($js, 'function removeStoredAttachments(inputField) {');
        $this->assertStringContainsString("inputField?.closest('.input')", $remove);
        $this->assertStringNotContainsString('inputField?.id', $remove);
    }

    /** Everything from a function's signature up to its closing brace. */
    private function functionBody(string $source, string $signature): string
    {
        $start = strpos($source, $signature);
        $this->assertNotFalse($start, "missing: {$signature}");

        $depth = 0;
        for ($i = $start + strlen($signature) - 1; $i < strlen($source); $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        $this->fail("unbalanced braces after: {$signature}");
    }

    public function test_the_thumbnail_is_appended_before_its_status_is_set(): void
    {
        // updateFileStatus() looks the thumbnail up in the document, so calling
        // it first was a no-op and left the tile without its state.
        $js = file_get_contents(public_path('js/attachment_handler.js'));

        $appendAt = strpos($js, "attachmentContainer.querySelector('.attachments-list').appendChild(thumbnail);");
        $statusAt = strpos($js, "updateFileStatus(storedFileData.tempId, 'complete');");

        $this->assertNotFalse($appendAt);
        $this->assertNotFalse($statusAt);
        $this->assertLessThan($statusAt, $appendAt);
    }

    public function test_an_image_only_needs_vision_not_file_upload(): void
    {
        // Every Responses model has file_upload => false, so implying it would
        // rule out the only models that can generate images at all.
        $rules = file_get_contents(public_path('js/model_list_filtering.js'));
        $this->assertMatchesRegularExpression(
            '/vision:\s*\{[^}]*implies:\s*\[\]/s',
            $rules
        );

        $attachments = file_get_contents(public_path('js/attachment_handler.js'));
        $this->assertStringContainsString("if(type === 'pdf' || type === 'docx'){", $attachments);
    }
}
