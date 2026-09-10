<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The live-editing switch disappeared once with a feature merge (KI-718) and
 * looked like a caching bug from the outside: live mode kept running for
 * users whose session had it on, and nobody could switch it. The sidebar has
 * to render the switch whenever the admin setting allows live mode.
 */
class TranslateSidebarLiveToggleTest extends TestCase
{
    private function renderSidebar(bool $enableLiveMode): string
    {
        return view('translate.components.sidebar.main-panel', [
            'translation' => [],
            'enableLiveMode' => $enableLiveMode,
            'deeplApiKeyPresent' => false,
            'createModeAllowed' => true,
        ])->render();
    }

    public function test_the_live_editing_switch_is_rendered_when_live_mode_is_allowed(): void
    {
        $html = $this->renderSidebar(true);

        $this->assertStringContainsString('id="liveTranslationToggle"', $html);
        $this->assertStringContainsString('id="live-translation-btn"', $html);
        $this->assertStringContainsString('id="rephraseModeBtn"', $html);
    }

    public function test_the_live_editing_switch_follows_the_admin_setting(): void
    {
        $this->assertStringNotContainsString('liveTranslationToggle', $this->renderSidebar(false));
    }

    public function test_the_scripts_wire_the_switch(): void
    {
        $ui = file_get_contents(public_path('js/translate/UIManager.js'));
        $app = file_get_contents(public_path('js/translate/TranslateApp.js'));

        $this->assertStringContainsString("'liveTranslationToggle'", $ui, 'UIManager must register the switch');
        $this->assertStringContainsString('elements.liveTranslationToggle.addEventListener', $app, 'TranslateApp must listen to the switch');
    }
}
