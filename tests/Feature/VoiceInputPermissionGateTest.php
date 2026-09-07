<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The chat's voice input drives the realtime transcription, whose routes are
 * behind the transcription permission. The controls, the typing indicator and
 * the TURN credential in the page head must follow the same permission.
 */
class VoiceInputPermissionGateTest extends TestCase
{
    use RefreshDatabase;

    private function renderVoiceInput(): string
    {
        return Blade::render(
            file_get_contents(resource_path('views/partials/home/components/realtime-voice-input.blade.php')),
            ['translation' => []]
        );
    }

    public function test_the_microphone_is_hidden_without_transcription_access(): void
    {
        $this->actingAs(User::factory()->create(['permissions' => ['chat.access' => true]]));

        $html = $this->renderVoiceInput();

        $this->assertStringNotContainsString('realtime-mic-btn', $html);
        $this->assertStringNotContainsString('live-input-device-select', $html);
    }

    public function test_the_microphone_is_shown_with_transcription_access(): void
    {
        $this->actingAs(User::factory()->create(['permissions' => ['chat.access' => true, 'transcription.access' => true]]));

        $html = $this->renderVoiceInput();

        $this->assertStringContainsString('id="realtime-mic-btn"', $html);
        $this->assertStringContainsString('toggleRealtimeTranscription', $html);
    }

    public function test_the_input_field_gates_the_typing_indicator_and_includes_the_component(): void
    {
        $source = file_get_contents(resource_path('views/partials/home/input-field.blade.php'));

        $this->assertStringContainsString("@include('partials.home.components.realtime-voice-input')", $source);
        $this->assertStringNotContainsString('id="realtime-mic-btn"', $source, 'the button must only exist in the gated component');

        $gate = strpos($source, "hasAccess('transcription.access')");
        $indicator = strpos($source, 'id="realtime-typing-indicator"');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($indicator);
        $this->assertLessThan($indicator, $gate, 'the permission check has to wrap the typing indicator');
    }

    public function test_the_layout_withholds_the_turn_credential_and_script_from_other_roles(): void
    {
        $source = file_get_contents(resource_path('views/layouts/home.blade.php'));

        $meta = strpos($source, '<meta name="ice-servers"');
        $credential = strpos($source, "config('realtime_bridge.turn_password')");
        $gateInMeta = strpos($source, "hasAccess('transcription.access')", $meta);
        $this->assertNotFalse($meta);
        $this->assertNotFalse($credential);
        $this->assertNotFalse($gateInMeta);
        $this->assertLessThan($credential, $gateInMeta, 'the permission check has to guard the TURN credential');

        $script = strpos($source, "asset('js/modules/realtime_transcription.js')");
        $this->assertNotFalse($script);
        $this->assertStringContainsString(
            "@if(Auth::user()?->hasAccess('transcription.access'))",
            substr($source, max(0, $script - 120), 120),
            'the script include has to sit directly inside an @if on the permission'
        );
    }
}
