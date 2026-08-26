<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Transcription\SummaryTemplate;
use App\Models\Transcription\Transcription;
use App\Models\Transcription\TranscriptionText;
use App\Models\User;
use App\Services\AI\AiService;
use App\Services\AI\Config\AiConfigService;
use App\Services\Transcription\SummaryTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SummaryTemplateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SummaryTemplateRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'username' => 'testuser',
            'publicKey' => 'test-public-key',
            'employeetype' => 'staff',
            'auth_type' => 'local',
            'approval' => true,
        ]);

        $this->registry = new SummaryTemplateRegistry;
    }

    /**
     * Test template resolution.
     */
    public function test_template_resolution(): void
    {
        $this->actingAs($this->user);

        // Resolve built-in 'legacy' template
        $legacy = $this->registry->resolve('legacy');
        $this->assertEquals('legacy', $legacy->id);
        $this->assertEquals('Standard-Protokoll', $legacy->name);
        $this->assertTrue($legacy->is_builtin);

        // Resolve built-in 'meeting-protocol'
        $meeting = $this->registry->resolve('meeting-protocol');
        $this->assertEquals('meeting-protocol', $meeting->id);

        // Resolve custom user template
        $custom = SummaryTemplate::create([
            'id' => 'my-custom-template',
            'user_id' => $this->user->id,
            'name' => 'My Custom',
            'sections' => [['heading' => 'Section A', 'instruction' => 'Do something']],
            'is_builtin' => false,
        ]);

        $resolvedCustom = $this->registry->resolve('my-custom-template');
        $this->assertEquals('my-custom-template', $resolvedCustom->id);
        $this->assertEquals('My Custom', $resolvedCustom->name);

        // Resolve non-existent template falls back to legacy
        $fallback = $this->registry->resolve('non-existent');
        $this->assertEquals('legacy', $fallback->id);

        // Resolve when template_id is null falls back to legacy
        $fallbackNull = $this->registry->resolve(null);
        $this->assertEquals('legacy', $fallbackNull->id);
    }

    /**
     * Test placeholder resolution & prompt assembly via controller endpoint.
     */
    public function test_placeholder_resolution_and_prompt_assembly(): void
    {
        $transcription = Transcription::create([
            'title' => 'My Test Presentation',
            'user_id' => $this->user->id,
            'slug' => 'presentation-slug',
            'language' => 'de',
            'duration' => 120, // 2 minutes
            'model_used' => 'gpt-4o',
            'provider' => 'openai',
        ]);

        TranscriptionText::create([
            'transcription_id' => $transcription->id,
            'transcript_text' => 'Segment 1. Segment 2.',
            'segments' => [
                ['speaker' => 'Dr. Müller', 'text' => 'Hallo zusammen.', 'start' => 0.0, 'end' => 2.0],
                ['speaker' => 'Prof. Schmidt', 'text' => 'Guten Tag.', 'start' => 3.0, 'end' => 5.0],
            ],
        ]);

        // Mock AI Service to assert prompt structure and placeholder values
        $mockAiService = $this->createMock(AiService::class);
        $mockAiService->expects($this->once())
            ->method('sendRequest')
            ->with($this->callback(function ($payload) {
                $userMessage = $payload['messages'][1]['content']['text'];

                // Verify transcript context is appended
                $expectedTranscript = "Dr. Müller: Hallo zusammen.\nProf. Schmidt: Guten Tag.\n";
                $this->assertStringContainsString($expectedTranscript, $userMessage);

                // Verify instruction is at the beginning of the prompt
                $this->assertStringStartsWith('Fasse das Meeting zusammen.', $userMessage);

                return true;
            }))
            ->willReturn(new \App\Services\AI\Value\AiResponse(content: ['text' => 'Das ist das Protokoll.']));

        $mockAiModelObj = $this->createMock(\App\Services\AI\Value\AiModel::class);
        $mockAiModelObj->method('getId')->willReturn('gpt-4o');
        $mockAiService->method('getModelOrFail')->willReturn($mockAiModelObj);

        $mockAiConfigService = $this->createMock(AiConfigService::class);
        $mockAiConfigService->method('getDefaultModels')->willReturn(['default_model' => 'gpt-4o']);

        $this->app->instance(AiService::class, $mockAiService);
        $this->app->instance(AiConfigService::class, $mockAiConfigService);

        // Request with custom sections that have placeholders in the headings
        $response = $this->actingAs($this->user)
            ->postJson('/req/transcription/summarize', [
                'transcription_slug' => 'presentation-slug',
                'sections' => [
                    ['heading' => '# Protokoll: {{title}} am {{date}} (Dauer: {{duration}})', 'instruction' => null],
                    ['heading' => 'Teilnehmer: {{participants}}', 'instruction' => null],
                    ['heading' => 'Zusammenfassung', 'instruction' => 'Fasse das Meeting zusammen.'],
                ],
            ]);

        $response->assertStatus(200);
        $summary = $response->json('summary');

        // Check if placeholders resolved correctly
        $today = date('d.m.Y');
        $this->assertStringContainsString("# Protokoll: My Test Presentation am {$today} (Dauer: 2 Min)", $summary);
        $this->assertStringContainsString('Teilnehmer: Dr. Müller, Prof. Schmidt', $summary);
        $this->assertStringContainsString('Das ist das Protokoll.', $summary);
    }

    /**
     * Test legacy fallback when no template_id is provided.
     */
    public function test_legacy_fallback_when_no_template_id(): void
    {
        $transcription = Transcription::create([
            'title' => 'Default Legacy Meeting',
            'user_id' => $this->user->id,
            'slug' => 'legacy-slug',
            'language' => 'de',
            'duration' => 60,
            'model_used' => 'gpt-4o',
            'provider' => 'openai',
        ]);

        TranscriptionText::create([
            'transcription_id' => $transcription->id,
            'transcript_text' => 'Ad-hoc text.',
            'segments' => [
                ['speaker' => 'Moderator', 'text' => 'Start session.', 'start' => 0.0, 'end' => 3.0],
            ],
        ]);

        $mockAiService = $this->createMock(AiService::class);
        $mockAiService->expects($this->once())
            ->method('sendRequest')
            ->with($this->callback(function ($payload) {
                $userMessage = $payload['messages'][1]['content']['text'];

                // Assert it uses the legacy prompt instructions
                $this->assertStringContainsString('Du bist ein Experte für Gesprächsprotokolle.', $userMessage);
                $this->assertStringContainsString('1. Titel/Thema (basierend auf dem Inhalt)', $userMessage);

                return true;
            }))
            ->willReturn(new \App\Services\AI\Value\AiResponse(content: ['text' => 'Legacy assembled summary output.']));

        $mockAiModelObj = $this->createMock(\App\Services\AI\Value\AiModel::class);
        $mockAiModelObj->method('getId')->willReturn('gpt-4o');
        $mockAiService->method('getModelOrFail')->willReturn($mockAiModelObj);

        $mockAiConfigService = $this->createMock(AiConfigService::class);
        $mockAiConfigService->method('getDefaultModels')->willReturn(['default_model' => 'gpt-4o']);

        $this->app->instance(AiService::class, $mockAiService);
        $this->app->instance(AiConfigService::class, $mockAiConfigService);

        // Make request without template_id
        $response = $this->actingAs($this->user)
            ->postJson('/req/transcription/summarize', [
                'transcription_slug' => 'legacy-slug',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'summary' => 'Legacy assembled summary output.',
        ]);

        $transcription->refresh();
        $this->assertEquals('legacy', $transcription->summary_template_id);
    }
}
