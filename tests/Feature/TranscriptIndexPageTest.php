<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranscriptIndexPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed necessary system texts and settings to avoid view rendering errors
        $this->seed(\Database\Seeders\AppSystemTextSeeder::class);
        $this->seed(\Database\Seeders\AppLocalizedTextSeeder::class);
        $this->seed(\Database\Seeders\AppSettingsSeeder::class);

        // Ensure we have a default language in session
        session(['language' => ['id' => 'de_DE', 'name' => 'Deutsch']]);
    }

    public function test_transcript_page_renders_and_contains_choice_cards(): void
    {
        $this->withoutExceptionHandling();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/transcript');

        $response->assertStatus(200);
        $response->assertSee('Datei hochladen');
        $response->assertSee('Audio aufnehmen');

        // After fix, it should use the global function
        $response->assertSee('onclick="showTranscriptMode(\'file\')"', false);
        $response->assertSee('onclick="showTranscriptMode(\'live\')"', false);
    }

    public function test_sidebar_contains_live_options_containers(): void
    {
        $this->withoutExceptionHandling();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/transcript');

        $response->assertStatus(200);
        $response->assertSee('id="live-record-sidebar-options"', false);
        $response->assertSee('id="live-transcript-sidebar-options"', false);
    }
}
