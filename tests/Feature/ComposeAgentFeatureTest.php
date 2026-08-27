<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\AiService;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiResponse;
use App\Services\AI\Value\TokenUsage;
use App\Services\Translation\TranslationService;
use App\Services\Translation\TranslationUsageLogger;
use Tests\TestCase;

class ComposeAgentFeatureTest extends TestCase
{
    /**
     * Test that the text improve endpoint, when invoked with type 'compose',
     * correctly runs the agentic loop, triggers the Mermaid tool, and returns the expected output.
     */
    public function test_compose_endpoint_triggers_mermaid_tool_and_returns_gitgraph(): void
    {
        // 1. Mock dependencies to avoid SQLite database errors
        $aiService = $this->createMock(AiService::class);
        $model = $this->createMock(AiModel::class);
        $model->method('getId')->willReturn('test-model');
        $aiService->method('getModel')->willReturn($model);

        $translationService = $this->createMock(TranslationService::class);
        // Return a mock model ID when resolving default model for 'compose'
        $translationService->method('resolveDefaultModelForType')->willReturn('test-model');
        $translationService->method('shouldShowDebug')->willReturn(false);

        $usageLogger = $this->createMock(TranslationUsageLogger::class);

        // 2. Setup AI Service mock responses for the agentic loop
        // The first turn returns a tool call for gitGraph.
        $response1 = new AiResponse(
            content: ['text' => 'Creating diagram: <tool_call name="create_mermaid_chart">{"type":"gitGraph","description":"development workflow"}</tool_call>'],
            usage: new TokenUsage(model: $model, promptTokens: 10, completionTokens: 20)
        );

        // The second turn (tool execution) returns raw gitGraph code.
        $response2 = new AiResponse(
            content: ['text' => "gitGraph\ncommit\nbranch develop\ncheckout develop\ncommit"],
            usage: new TokenUsage(model: $model, promptTokens: 30, completionTokens: 15)
        );

        // The third turn (final agent response) returns the final text containing the markdown block.
        $response3 = new AiResponse(
            content: ['text' => "Here is your diagram:\n\n```mermaid\ngitGraph\ncommit\nbranch develop\ncheckout develop\ncommit\n```"],
            usage: new TokenUsage(model: $model, promptTokens: 40, completionTokens: 25)
        );

        $aiService->expects($this->exactly(3))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls($response1, $response2, $response3);

        // 3. Bind the mocked instances into the Laravel Service Container
        $this->instance(AiService::class, $aiService);
        $this->instance(TranslationService::class, $translationService);
        $this->instance(TranslationUsageLogger::class, $usageLogger);

        // 4. Send POST request to `/req/text/improve` bypassing middlewares to avoid session/signature/auth requirements
        $response = $this->withoutMiddleware()->postJson('/req/text/improve', [
            'type' => 'compose',
            'style' => 'Erstelle ein Git-Branching Flussdiagramm für ein Release.',
            'text' => '',
            'model' => 'test-model',
            'target_lang' => 'de',
        ]);

        // 5. Assertions
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'text' => "Here is your diagram:\n\n```mermaid\ngitGraph\ncommit\nbranch develop\ncheckout develop\ncommit\n```",
            ],
        ]);
    }
}
