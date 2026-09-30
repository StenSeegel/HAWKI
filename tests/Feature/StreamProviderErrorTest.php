<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Records\UsageRecord;
use App\Models\User;
use App\Services\AI\AiService;
use App\Services\AI\Value\AiResponse;
use App\Services\Storage\AvatarStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A chat request that fails at the provider or on the way there has to leave
 * a trace on the server, not only an "INTERNAL ERROR" in the user's chat.
 */
class StreamProviderErrorTest extends TestCase
{
    use RefreshDatabase;

    private array $sentPayloads = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        User::factory()->create(); // id 1, the HAWKI user the answer is authored by

        $avatars = $this->createMock(AvatarStorageService::class);
        $avatars->method('getUrl')->willReturn('');
        $this->app->instance(AvatarStorageService::class, $avatars);
    }

    private function answerWith(AiResponse $response): void
    {
        $ai = $this->createMock(AiService::class);
        $ai->method('sendRequest')->willReturnCallback(function (array $payload) use ($response) {
            $this->sentPayloads[] = $payload;

            return $response;
        });
        // The usage record looks the provider up; it records "unknown" without a model list.
        $ai->method('getAvailableModels')->willThrowException(new \RuntimeException('no models in this test'));
        $this->app->instance(AiService::class, $ai);
    }

    private function request(array $attachments = []): array
    {
        return [
            'payload' => [
                'model' => 'gpt-5.6-sol',
                'stream' => false,
                'messages' => [
                    ['role' => 'user', 'content' => ['text' => 'Passt das?', 'attachments' => $attachments]],
                ],
            ],
            'broadcast' => false,
        ];
    }

    public function test_a_provider_error_is_logged_and_marks_the_usage_record_as_an_error(): void
    {
        Log::spy();
        $this->answerWith(new AiResponse(
            content: ['text' => "INTERNAL ERROR: Missing required parameter: 'model'."],
            error: "Missing required parameter: 'model'.",
        ));

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/req/streamAI', $this->request())->assertOk();

        $record = UsageRecord::where('user_id', $user->id)->sole();
        $this->assertSame('failed', $record->status);
        $this->assertTrue($record->is_error);

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []) =>
            str_contains($message, 'The provider answered with an error')
            && $context['error'] === "Missing required parameter: 'model'."
            && $context['model'] === 'gpt-5.6-sol'
            && $context['usage_record_id'] === $record->id
        )->once();
    }

    public function test_a_successful_answer_is_no_error(): void
    {
        $this->answerWith(new AiResponse(content: ['text' => 'Ja.']));

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/req/streamAI', $this->request())->assertOk();

        $record = UsageRecord::where('user_id', $user->id)->sole();
        $this->assertSame('success', $record->status);
        $this->assertFalse($record->is_error);
    }

    public function test_attachments_reach_the_provider_as_uuids_whatever_shape_they_came_in(): void
    {
        $this->answerWith(new AiResponse(content: ['text' => 'Ja.']));

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/req/streamAI', $this->request([
            'uuid-plain',
            ['uuid' => 'uuid-stored', 'name' => 'Meldung Z1.csv', 'mime' => 'text/csv'],
            ['name' => 'no-uuid.docx'],
            'uuid-plain',
        ]))->assertOk();

        $this->assertSame(
            ['uuid-plain', 'uuid-stored'],
            $this->sentPayloads[0]['messages'][0]['content']['attachments']
        );
    }
}
