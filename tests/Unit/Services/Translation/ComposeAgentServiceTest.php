<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Translation;

use App\Services\AI\AiService;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiResponse;
use App\Services\AI\Value\TokenUsage;
use App\Services\Translation\ComposeAgentService;
use Tests\TestCase;

class ComposeAgentServiceTest extends TestCase
{
    public function test_compose_without_tools(): void
    {
        $aiService = $this->createMock(AiService::class);
        $model = $this->createMock(AiModel::class);
        $model->method('getId')->willReturn('test-model');

        $usage = new TokenUsage(
            model: $model,
            promptTokens: 10,
            completionTokens: 20
        );

        $response = new AiResponse(
            content: ['text' => 'This is a standard text response.'],
            usage: $usage
        );

        $aiService->expects($this->once())
            ->method('sendRequest')
            ->willReturn($response);

        $aiService->method('getModel')
            ->willReturn($model);

        $service = new ComposeAgentService($aiService);

        $result = $service->compose(
            userPrompt: 'Write something',
            systemPrompt: 'You are a writer',
            modelId: 'test-model',
            temperature: 0.7
        );

        $this->assertEquals('This is a standard text response.', $result['text']);
        $this->assertNotNull($result['usage']);
        $this->assertEquals(30, $result['usage']->totalTokens);
        $this->assertEquals(10, $result['usage']->promptTokens);
        $this->assertEquals(20, $result['usage']->completionTokens);
    }

    public function test_compose_with_mermaid_tool_call(): void
    {
        $aiService = $this->createMock(AiService::class);
        $model = $this->createMock(AiModel::class);
        $model->method('getId')->willReturn('test-model');
        $aiService->method('getModel')->willReturn($model);

        $usage1 = new TokenUsage(
            model: $model,
            promptTokens: 15,
            completionTokens: 30
        );
        $usage2 = new TokenUsage(
            model: $model,
            promptTokens: 50,
            completionTokens: 25
        );
        $usage3 = new TokenUsage(
            model: $model,
            promptTokens: 80,
            completionTokens: 40
        );

        // 1. Initial agent response calling the tool
        $response1 = new AiResponse(
            content: ['text' => 'Here is a diagram: <tool_call name="create_mermaid_chart">{"type":"gitGraph","description":"development branch"}</tool_call>'],
            usage: $usage1
        );

        // 2. Tool response generating raw diagram
        $response2 = new AiResponse(
            content: ['text' => "gitGraph\ncommit\nbranch develop\ncheckout develop\ncommit"],
            usage: $usage2
        );

        // 3. Final agent response combining everything
        $response3 = new AiResponse(
            content: ['text' => "Here is the diagram you requested:\n\n```mermaid\ngitGraph\ncommit\nbranch develop\ncheckout develop\ncommit\n```\n\nHope this helps!"],
            usage: $usage3
        );

        // aiService sendRequest will be called 3 times:
        // 1st: Agent prompt -> returns tool_call
        // 2nd: Tool execution (generates diagram)
        // 3rd: Agent prompt with tool result -> returns final text
        $aiService->expects($this->exactly(3))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls($response1, $response2, $response3);

        $service = new ComposeAgentService($aiService);

        $result = $service->compose(
            userPrompt: 'Draw a git graph',
            systemPrompt: 'You can use tools',
            modelId: 'test-model',
            temperature: 0.8
        );

        $expectedText = "Here is the diagram you requested:\n\n```mermaid\ngitGraph\ncommit\nbranch develop\ncheckout develop\ncommit\n```\n\nHope this helps!";
        $this->assertEquals($expectedText, $result['text']);

        $this->assertNotNull($result['usage']);
        // 15 + 50 + 80 = 145 prompt tokens
        // 30 + 25 + 40 = 95 completion tokens
        $this->assertEquals(145, $result['usage']->promptTokens);
        $this->assertEquals(95, $result['usage']->completionTokens);
        $this->assertEquals(240, $result['usage']->totalTokens);
    }

    public function test_compose_with_alternate_tool_call(): void
    {
        $aiService = $this->createMock(AiService::class);
        $model = $this->createMock(AiModel::class);
        $model->method('getId')->willReturn('test-model');
        $aiService->method('getModel')->willReturn($model);

        $response1 = new AiResponse(
            content: ['text' => "Here is a diagram:\n<tool_call>\ncreate_mermaid_chart\n{\n  \"type\": \"gitGraph\",\n  \"description\": \"development branch\"\n}\n</tool_call>"],
            usage: new TokenUsage(model: $model, promptTokens: 10, completionTokens: 20)
        );

        $response2 = new AiResponse(
            content: ['text' => "gitGraph\ncommit"],
            usage: new TokenUsage(model: $model, promptTokens: 30, completionTokens: 15)
        );

        $response3 = new AiResponse(
            content: ['text' => "Done: ```mermaid\ngitGraph\ncommit\n```"],
            usage: new TokenUsage(model: $model, promptTokens: 40, completionTokens: 20)
        );

        $aiService->expects($this->exactly(3))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls($response1, $response2, $response3);

        $service = new ComposeAgentService($aiService);

        $result = $service->compose(
            userPrompt: 'Draw a git graph',
            systemPrompt: 'You can use tools',
            modelId: 'test-model',
            temperature: 0.8
        );

        $this->assertEquals("Done: ```mermaid\ngitGraph\ncommit\n```", $result['text']);
        $this->assertEquals(80, $result['usage']->promptTokens);
        $this->assertEquals(55, $result['usage']->completionTokens);
    }

    public function test_validate_syntax_gitgraph(): void
    {
        $aiService = $this->createMock(AiService::class);
        $service = new ComposeAgentService($aiService);

        // Valid gitGraph
        $validCode = "gitGraph\ncommit\nbranch develop\ncheckout develop\ncommit";
        $res = $service->validateSyntax($validCode, 'gitGraph');
        $this->assertTrue($res['valid']);

        // Invalid gitGraph - standalone tag command
        $invalidCode1 = "gitGraph\ncommit\ntag \"v1.0\"";
        $res = $service->validateSyntax($invalidCode1, 'gitGraph');
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('Standalone `tag` command is invalid', $res['error']);

        // Invalid gitGraph - checkout non-existent branch
        $invalidCode2 = "gitGraph\ncheckout feature-xyz";
        $res = $service->validateSyntax($invalidCode2, 'gitGraph');
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString("Cannot checkout branch 'feature-xyz'", $res['error']);

        // Invalid gitGraph - commit before checking out newly created branch
        $invalidCode3 = "gitGraph\nbranch develop\ncommit";
        $res = $service->validateSyntax($invalidCode3, 'gitGraph');
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('committed without checking out', $res['error']);
    }

    public function test_validate_syntax_flowchart(): void
    {
        $aiService = $this->createMock(AiService::class);
        $service = new ComposeAgentService($aiService);

        // Valid flowchart
        $validCode = "flowchart TD\nA --> B\nC([Start])\nD{Is Valid?}";
        $res = $service->validateSyntax($validCode, 'flowchart');
        $this->assertTrue($res['valid']);

        // Invalid flowchart - invalid connection arrow `->`
        $invalidCode1 = "flowchart TD\nA -> B";
        $res = $service->validateSyntax($invalidCode1, 'flowchart');
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('Invalid connection arrow `->`', $res['error']);

        // Invalid flowchart - unbalanced brackets
        $invalidCode2 = "flowchart TD\nA[Unbalanced bracket";
        $res = $service->validateSyntax($invalidCode2, 'flowchart');
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('Unbalanced brackets', $res['error']);
    }

    public function test_validate_syntax_sequencediagram(): void
    {
        $aiService = $this->createMock(AiService::class);
        $service = new ComposeAgentService($aiService);

        // Valid sequenceDiagram
        $validCode = "sequenceDiagram\nactor Alice\nparticipant Bob\nAlice->>Bob: Hello\nloop 5 times\nBob-->>Alice: Hi\nend";
        $res = $service->validateSyntax($validCode, 'sequenceDiagram');
        $this->assertTrue($res['valid']);

        // Invalid sequenceDiagram - unclosed loop
        $invalidCode1 = "sequenceDiagram\nAlice->>Bob: Hello\nloop 5 times\nBob-->>Alice: Hi";
        $res = $service->validateSyntax($invalidCode1, 'sequenceDiagram');
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('Unclosed block `loop`', $res['error']);

        // Invalid sequenceDiagram - unmatched end
        $invalidCode2 = "sequenceDiagram\nAlice->>Bob: Hello\nend";
        $res = $service->validateSyntax($invalidCode2, 'sequenceDiagram');
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('Found `end` command without a matching block', $res['error']);
    }

    public function test_validate_syntax_classdiagram(): void
    {
        $aiService = $this->createMock(AiService::class);
        $service = new ComposeAgentService($aiService);

        // Valid classDiagram
        $validCode = "classDiagram\nAnimal <|-- Duck\nclass Animal {\n+int age\n}";
        $res = $service->validateSyntax($validCode, 'classDiagram');
        $this->assertTrue($res['valid']);

        // Invalid classDiagram - invalid relation arrow `->`
        $invalidCode1 = "classDiagram\nAnimal -> Duck";
        $res = $service->validateSyntax($invalidCode1, 'classDiagram');
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString("Invalid relation '->'", $res['error']);

        // Invalid classDiagram - unbalanced braces
        $invalidCode2 = "classDiagram\nclass Animal {\n+int age";
        $res = $service->validateSyntax($invalidCode2, 'classDiagram');
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('Unbalanced braces `{` and `}`', $res['error']);
    }

    public function test_validate_syntax_erdiagram(): void
    {
        $aiService = $this->createMock(AiService::class);
        $service = new ComposeAgentService($aiService);

        // Valid erDiagram
        $validCode = "erDiagram\nUSER ||--o{ POST : \"creates\"\nUSER {\nint id PK\n}";
        $res = $service->validateSyntax($validCode, 'erDiagram');
        $this->assertTrue($res['valid']);

        // Invalid erDiagram - invalid connector arrow `->`
        $invalidCode1 = "erDiagram\nUSER -> POST : \"creates\"";
        $res = $service->validateSyntax($invalidCode1, 'erDiagram');
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString("Invalid relationship connector '->'", $res['error']);

        // Invalid erDiagram - unbalanced braces
        $invalidCode2 = "erDiagram\nUSER {\nint id PK";
        $res = $service->validateSyntax($invalidCode2, 'erDiagram');
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('Unbalanced braces `{` and `}`', $res['error']);
    }

    public function test_validate_syntax_statediagram(): void
    {
        $aiService = $this->createMock(AiService::class);
        $service = new ComposeAgentService($aiService);

        // Valid stateDiagram-v2
        $validCode = "stateDiagram-v2\n[*] --> Off\nOff --> On : Turn On";
        $res = $service->validateSyntax($validCode, 'stateDiagram-v2');
        $this->assertTrue($res['valid']);

        // Invalid stateDiagram - v1 diagram check
        $invalidCode1 = "stateDiagram\n[*] --> Off";
        $res = $service->validateSyntax($invalidCode1, 'stateDiagram-v2');
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('Must start with `stateDiagram-v2`', $res['error']);

        // Invalid stateDiagram - unbalanced braces
        $invalidCode2 = "stateDiagram-v2\nstate On {\n[*] --> Idle";
        $res = $service->validateSyntax($invalidCode2, 'stateDiagram-v2');
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('Unbalanced braces `{` and `}`', $res['error']);
    }

    public function test_validate_syntax_pie(): void
    {
        $aiService = $this->createMock(AiService::class);
        $service = new ComposeAgentService($aiService);

        // Valid pie
        $validCode = "pie showData\n\"A\" : 10\n\"B\" : 20";
        $res = $service->validateSyntax($validCode, 'pie');
        $this->assertTrue($res['valid']);

        // Invalid pie - missing colon
        $invalidCode1 = "pie showData\n\"A\" 10";
        $res = $service->validateSyntax($invalidCode1, 'pie');
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('Invalid pie slice format', $res['error']);
    }

    public function test_validate_syntax_mindmap(): void
    {
        $aiService = $this->createMock(AiService::class);
        $service = new ComposeAgentService($aiService);

        // Valid mindmap
        $validCode = "mindmap\nroot((Topic))\nSubtopic";
        $res = $service->validateSyntax($validCode, 'mindmap');
        $this->assertTrue($res['valid']);

        // Invalid mindmap - unbalanced node shape
        $invalidCode1 = "mindmap\nroot((Topic";
        $res = $service->validateSyntax($invalidCode1, 'mindmap');
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('Unbalanced shape delimiters', $res['error']);
    }

    public function test_compose_with_self_correction_retry_flowchart(): void
    {
        $aiService = $this->createMock(AiService::class);
        $model = $this->createMock(AiModel::class);
        $model->method('getId')->willReturn('test-model');
        $aiService->method('getModel')->willReturn($model);

        $response1 = new AiResponse(
            content: ['text' => 'Here is a flowchart: <tool_call name="create_mermaid_chart">{"type":"flowchart","description":"test flowchart"}</tool_call>'],
            usage: new TokenUsage(model: $model, promptTokens: 10, completionTokens: 20)
        );

        // 1st tool response generates invalid flowchart (using invalid `->` connector)
        $response2 = new AiResponse(
            content: ['text' => "flowchart TD\nA -> B"],
            usage: new TokenUsage(model: $model, promptTokens: 30, completionTokens: 15)
        );

        // 2nd tool response (after receiving feedback) generates corrected flowchart
        $response3 = new AiResponse(
            content: ['text' => "flowchart TD\nA --> B"],
            usage: new TokenUsage(model: $model, promptTokens: 50, completionTokens: 15)
        );

        // Final response combining everything
        $response4 = new AiResponse(
            content: ['text' => "Here is the corrected flowchart:\n\n```mermaid\nflowchart TD\nA --> B\n```"],
            usage: new TokenUsage(model: $model, promptTokens: 70, completionTokens: 20)
        );

        // SendRequest will be called 4 times in total
        $aiService->expects($this->exactly(4))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls($response1, $response2, $response3, $response4);

        $service = new ComposeAgentService($aiService);

        $result = $service->compose(
            userPrompt: 'Draw a flowchart',
            systemPrompt: 'You can use tools',
            modelId: 'test-model',
            temperature: 0.8
        );

        $this->assertStringContainsString("flowchart TD\nA --> B", $result['text']);
    }
}
