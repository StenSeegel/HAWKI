<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Transcription\SummaryTemplate;
use App\Models\Transcription\Transcription;
use App\Models\User;
use App\Services\AI\AiService;
use App\Services\AI\Config\AiConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranscriptionTemplateSummarizeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $otherUser;

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

        $this->otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'username' => 'otheruser',
            'publicKey' => 'other-public-key',
            'employeetype' => 'staff',
            'auth_type' => 'local',
            'approval' => true,
        ]);
    }

    public function test_can_list_system_and_own_templates(): void
    {
        // 1. Create a system template (user_id = null)
        $systemTemplate = SummaryTemplate::create([
            'id' => 'system-template',
            'user_id' => null,
            'name' => 'System Template',
            'sections' => [['heading' => 'Zusammenfassung', 'instruction' => 'Fasse zusammen']],
        ]);

        // 2. Create an own template
        $ownTemplate = SummaryTemplate::create([
            'id' => 'user-template',
            'user_id' => $this->user->id,
            'name' => 'User Template',
            'sections' => [['heading' => 'Key Points', 'instruction' => 'Key points']],
        ]);

        // 3. Create another user's template
        $otherTemplate = SummaryTemplate::create([
            'id' => 'other-user-template',
            'user_id' => $this->otherUser->id,
            'name' => 'Other User Template',
            'sections' => [['heading' => 'Secret', 'instruction' => 'Secret']],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/req/transcription/templates');

        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'System Template']);
        $response->assertJsonFragment(['name' => 'User Template']);
        $response->assertJsonMissing(['name' => 'Other User Template']);
    }

    public function test_can_create_template(): void
    {
        $payload = [
            'name' => 'New Template',
            'structure' => [
                ['heading' => 'To-Dos', 'instruction' => 'Finde To-Dos'],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/req/transcription/templates', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('template.name', 'New Template');
        $response->assertJsonPath('template.user_id', $this->user->id);

        $this->assertDatabaseHas('summary_templates', [
            'name' => 'New Template',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_can_update_own_template(): void
    {
        $template = SummaryTemplate::create([
            'id' => 'old-name',
            'user_id' => $this->user->id,
            'name' => 'Old Name',
            'sections' => [['heading' => 'Old Heading', 'instruction' => 'Old instruction']],
        ]);

        $payload = [
            'id' => $template->id,
            'name' => 'New Name',
            'structure' => [['heading' => 'New Heading', 'instruction' => 'New instruction']],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/req/transcription/templates', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('template.name', 'New Name');

        $template->refresh();
        $this->assertEquals('New Name', $template->name);
        $this->assertEquals('New Heading', $template->sections[0]['heading']);
    }

    public function test_cannot_update_system_or_other_user_template(): void
    {
        // System template
        $systemTemplate = SummaryTemplate::create([
            'id' => 'system-template-2',
            'user_id' => null,
            'name' => 'System Template',
            'sections' => [['heading' => 'System Heading', 'instruction' => 'System']],
        ]);

        $payload = [
            'id' => $systemTemplate->id,
            'name' => 'Malicious System Update',
            'structure' => [['heading' => 'Malicious', 'instruction' => 'Malicious']],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/req/transcription/templates', $payload);

        $response->assertStatus(500); // Throws ModelNotFoundException -> returns 500 in Controller catch

        // Other user's template
        $otherTemplate = SummaryTemplate::create([
            'id' => 'other-template',
            'user_id' => $this->otherUser->id,
            'name' => 'Other Template',
            'sections' => [['heading' => 'Other Heading', 'instruction' => 'Other']],
        ]);

        $payload = [
            'id' => $otherTemplate->id,
            'name' => 'Malicious Other Update',
            'structure' => [['heading' => 'Malicious', 'instruction' => 'Malicious']],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/req/transcription/templates', $payload);

        $response->assertStatus(500);
    }

    public function test_can_delete_own_template(): void
    {
        $template = SummaryTemplate::create([
            'id' => 'deletable-template',
            'user_id' => $this->user->id,
            'name' => 'Deletable Template',
            'sections' => [['heading' => 'Deletable', 'instruction' => 'Delete me']],
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/req/transcription/templates/{$template->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('summary_templates', [
            'id' => $template->id,
        ]);
    }

    public function test_cannot_delete_system_or_other_user_template(): void
    {
        $systemTemplate = SummaryTemplate::create([
            'id' => 'system-template-3',
            'user_id' => null,
            'name' => 'System Template',
            'sections' => [['heading' => 'System', 'instruction' => 'System']],
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/req/transcription/templates/{$systemTemplate->id}");

        $response->assertStatus(500);

        $otherTemplate = SummaryTemplate::create([
            'id' => 'other-template-2',
            'user_id' => $this->otherUser->id,
            'name' => 'Other Template',
            'sections' => [['heading' => 'Other', 'instruction' => 'Other']],
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/req/transcription/templates/{$otherTemplate->id}");

        $response->assertStatus(500);
    }

    public function test_template_summarize_preview_mode(): void
    {
        $transcription = Transcription::create([
            'title' => 'Sample Transcription',
            'user_id' => $this->user->id,
            'slug' => 'sample-slug',
            'language' => 'de',
            'model_used' => 'gpt-4o',
            'provider' => 'openai',
        ]);

        // Mock AiService and AiConfigService
        $mockAiService = $this->createMock(AiService::class);
        $mockResponse = new \App\Services\AI\Value\AiResponse(
            content: ['text' => 'Dies ist eine Vorschau-Zusammenfassung.']
        );
        $mockAiService->method('sendRequest')->willReturn($mockResponse);

        $mockAiModelObj = $this->createMock(\App\Services\AI\Value\AiModel::class);
        $mockAiModelObj->method('getId')->willReturn('gpt-4o');
        $mockAiService->method('getModelOrFail')->willReturn($mockAiModelObj);

        $mockAiConfigService = $this->createMock(AiConfigService::class);
        $mockAiConfigService->method('getDefaultModels')->willReturn(['default_model' => 'gpt-4o']);

        $this->app->instance(AiService::class, $mockAiService);
        $this->app->instance(AiConfigService::class, $mockAiConfigService);

        $response = $this->actingAs($this->user)
            ->postJson('/req/transcription/summarize', [
                'transcription_slug' => 'sample-slug',
                'preview' => true,
                'sections' => [
                    ['heading' => 'Zusammenfassung', 'instruction' => 'Fasse zusammen'],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'results' => [
                'Zusammenfassung' => 'Dies ist eine Vorschau-Zusammenfassung.',
            ],
        ]);
    }

    public function test_template_summarize_preview_with_stale_headings(): void
    {
        $transcription = Transcription::create([
            'title' => 'Sample Transcription',
            'user_id' => $this->user->id,
            'slug' => 'sample-slug',
            'language' => 'de',
            'model_used' => 'gpt-4o',
            'provider' => 'openai',
        ]);

        // Mock AiService and AiConfigService
        $mockAiService = $this->createMock(AiService::class);
        $mockResponse = new \App\Services\AI\Value\AiResponse(
            content: ['text' => 'Dies wurde neu generiert.']
        );

        $mockAiService->expects($this->once())
            ->method('sendRequest')
            ->with($this->callback(function ($payload) {
                // Ensure only "Stale Section" is requested in the prompt
                $userMessage = $payload['messages'][1]['content']['text'];

                return str_contains($userMessage, 'Anweisung geändert') && ! str_contains($userMessage, 'Keine Änderung');
            }))
            ->willReturn($mockResponse);

        $mockAiModelObj = $this->createMock(\App\Services\AI\Value\AiModel::class);
        $mockAiModelObj->method('getId')->willReturn('gpt-4o');
        $mockAiService->method('getModelOrFail')->willReturn($mockAiModelObj);

        $mockAiConfigService = $this->createMock(AiConfigService::class);
        $mockAiConfigService->method('getDefaultModels')->willReturn(['default_model' => 'gpt-4o']);

        $this->app->instance(AiService::class, $mockAiService);
        $this->app->instance(AiConfigService::class, $mockAiConfigService);

        $response = $this->actingAs($this->user)
            ->postJson('/req/transcription/summarize', [
                'transcription_slug' => 'sample-slug',
                'preview' => true,
                'stale_headings' => ['Stale Section'],
                'sections' => [
                    ['heading' => 'Fresh Section', 'instruction' => 'Keine Änderung'],
                    ['heading' => 'Stale Section', 'instruction' => 'Anweisung geändert'],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'results' => [
                'Stale Section' => 'Dies wurde neu generiert.',
            ],
        ]);
    }

    public function test_template_summarize_final_export_mode(): void
    {
        $transcription = Transcription::create([
            'title' => 'Sample Transcription',
            'user_id' => $this->user->id,
            'slug' => 'sample-slug',
            'language' => 'de',
            'duration' => 600, // 10 minutes
            'model_used' => 'gpt-4o',
            'provider' => 'openai',
        ]);

        // Mock AiService and AiConfigService
        $mockAiService = $this->createMock(AiService::class);
        $mockAiService->method('sendRequest')->willReturnCallback(function ($payload) {
            $prompt = $payload['messages'][1]['content']['text'];
            if (str_contains($prompt, 'Zusammenfassung')) {
                return new \App\Services\AI\Value\AiResponse(content: ['text' => 'Das ist das fertige Protokoll.']);
            }
            if (str_contains($prompt, 'To-Dos')) {
                return new \App\Services\AI\Value\AiResponse(content: ['text' => 'Mitarbeiter X macht Y.']);
            }

            return new \App\Services\AI\Value\AiResponse(content: ['text' => 'Mocked Header Content']);
        });

        $mockAiModelObj = $this->createMock(\App\Services\AI\Value\AiModel::class);
        $mockAiModelObj->method('getId')->willReturn('gpt-4o');
        $mockAiService->method('getModelOrFail')->willReturn($mockAiModelObj);

        $mockAiConfigService = $this->createMock(AiConfigService::class);
        $mockAiConfigService->method('getDefaultModels')->willReturn(['default_model' => 'gpt-4o']);

        $this->app->instance(AiService::class, $mockAiService);
        $this->app->instance(AiConfigService::class, $mockAiConfigService);

        $response = $this->actingAs($this->user)
            ->postJson('/req/transcription/summarize', [
                'transcription_slug' => 'sample-slug',
                'preview' => false,
                'sections' => [
                    ['heading' => 'Protokoll für {{titel}} vom {{datum}} (Dauer: {{dauer}})', 'instruction' => 'Überschrift'],
                    ['heading' => 'Zusammenfassung', 'instruction' => 'Zusammenfassung'],
                    ['heading' => 'To-Dos', 'instruction' => 'To-Dos'],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $summary = $response->json('summary');
        $this->assertStringContainsString('Protokoll für Sample Transcription vom', $summary);
        $this->assertStringContainsString('(Dauer: 10 Min)', $summary);
        $this->assertStringContainsString('Das ist das fertige Protokoll.', $summary);
        $this->assertStringContainsString('Mitarbeiter X macht Y.', $summary);

        $transcription->refresh();
        $this->assertEquals($summary, $transcription->metadata['summary']);
    }

    public function test_template_summarize_final_export_with_structure(): void
    {
        $transcription = Transcription::create([
            'title' => 'Sample Transcription',
            'user_id' => $this->user->id,
            'slug' => 'sample-slug-structure',
            'language' => 'de',
            'duration' => 600, // 10 minutes
            'model_used' => 'gpt-4o',
            'provider' => 'openai',
        ]);

        // Mock AiService and AiConfigService
        $mockAiService = $this->createMock(AiService::class);
        $mockResponse = new \App\Services\AI\Value\AiResponse(
            content: ['text' => 'Das ist das fertige Protokoll.']
        );
        $mockAiService->method('sendRequest')->willReturn($mockResponse);

        $mockAiModelObj = $this->createMock(\App\Services\AI\Value\AiModel::class);
        $mockAiModelObj->method('getId')->willReturn('gpt-4o');
        $mockAiService->method('getModelOrFail')->willReturn($mockAiModelObj);

        $mockAiConfigService = $this->createMock(AiConfigService::class);
        $mockAiConfigService->method('getDefaultModels')->willReturn(['default_model' => 'gpt-4o']);

        $this->app->instance(AiService::class, $mockAiService);
        $this->app->instance(AiConfigService::class, $mockAiConfigService);

        $response = $this->actingAs($this->user)
            ->postJson('/req/transcription/summarize', [
                'transcription_slug' => 'sample-slug-structure',
                'preview' => false,
                'sections' => [
                    ['heading' => 'Zusammenfassung', 'instruction' => 'Zusammenfassung'],
                ],
                'structure' => [
                    ['type' => 'heading', 'level' => 1, 'text' => 'Hauptüberschrift: {{titel}}'],
                    ['type' => 'divider'],
                    ['type' => 'text', 'text' => 'Dieses Protokoll wurde am {{datum}} erstellt.'],
                    ['type' => 'section', 'heading' => 'Zusammenfassung', 'instruction' => 'Zusammenfassung'],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $summary = $response->json('summary');
        // Assert headings block was converted: "# Hauptüberschrift: Sample Transcription"
        $this->assertStringContainsString('# Hauptüberschrift: Sample Transcription', $summary);
        // Assert divider block was converted
        $this->assertStringContainsString("---\n\n", $summary);
        // Assert text block was resolved: "Dieses Protokoll wurde am ..."
        $this->assertStringContainsString('Dieses Protokoll wurde am', $summary);
        // Assert section was generated and appended: "## Zusammenfassung" followed by the content
        $this->assertStringContainsString('## Zusammenfassung', $summary);
        $this->assertStringContainsString('Das ist das fertige Protokoll.', $summary);

        $transcription->refresh();
        $this->assertEquals($summary, $transcription->metadata['summary']);
    }
}
