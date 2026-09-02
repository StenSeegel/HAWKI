<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\Providers\Google\GoogleRequestConverter;
use App\Services\AI\Utils\MessageAttachmentFinder;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiRequest;
use PHPUnit\Framework\TestCase;

class GoogleWebSearchToolInjectionTest extends TestCase
{
    private function convert(array $modelTools, ?array $requestedTools): array
    {
        // Final class, but it short circuits to an empty array before touching the
        // database when no message carries attachments - as is the case here.
        $finder = new MessageAttachmentFinder();

        $model = $this->createMock(AiModel::class);
        $model->method('getTools')->willReturn($modelTools);
        $model->method('hasTool')->willReturnCallback(
            fn (string $tool) => ($modelTools[$tool] ?? false) === true
        );

        $payload = [
            'model' => 'gemini-2.0-flash',
            'messages' => [
                ['role' => 'user', 'content' => ['text' => 'Hello']],
            ],
            'stream' => false,
        ];

        if ($requestedTools !== null) {
            $payload['tools'] = $requestedTools;
        }

        return (new GoogleRequestConverter($finder))
            ->convertRequestToPayload(new AiRequest(model: $model, payload: $payload));
    }

    public function test_search_is_injected_when_model_and_frontend_both_enable_it(): void
    {
        $payload = $this->convert(
            ['stream' => true, 'web_search' => true],
            ['web_search' => true]
        );

        $this->assertCount(1, $payload['tools']);
        $this->assertArrayHasKey('google_search', $payload['tools'][0]);
    }

    public function test_search_is_not_injected_when_the_frontend_disabled_it(): void
    {
        $payload = $this->convert(
            ['stream' => true, 'web_search' => true],
            ['web_search' => false]
        );

        $this->assertSame([], $payload['tools']);
    }

    public function test_model_without_the_web_search_tool_never_gets_search(): void
    {
        // Regression: this branch used to fall back to "web search always on",
        // which silently searched for every model that carried no web_search key.
        $payload = $this->convert(
            ['stream' => true],
            ['web_search' => true]
        );

        $this->assertSame([], $payload['tools']);
    }

    public function test_model_with_web_search_disabled_never_gets_search(): void
    {
        $payload = $this->convert(
            ['stream' => true, 'web_search' => false],
            ['web_search' => true]
        );

        $this->assertSame([], $payload['tools']);
    }

    public function test_request_without_a_tools_key_gets_no_search(): void
    {
        $payload = $this->convert(
            ['stream' => true, 'web_search' => true],
            null
        );

        $this->assertSame([], $payload['tools']);
    }
}
