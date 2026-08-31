<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\User;
use App\Services\AI\Providers\Responses\ResponsesRequestConverter;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiRequest;
use App\Services\Chat\Attachment\AttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResponsesGeneratedImageContextTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function model(array $overrides = []): AiModel
    {
        return new AiModel(array_merge([
            'id' => 'gpt-5',
            'input' => ['text', 'image'],
            'output' => ['text'],
            'tools' => [
                'stream' => true,
                'vision' => true,
                'image_gen' => true,
                'file_upload' => false,
            ],
        ], $overrides));
    }

    private function fakeAttachmentService(string $bytes): void
    {
        $service = $this->createMock(AttachmentService::class);
        $service->method('retrieve')->willReturn($bytes);
        $this->app->instance(AttachmentService::class, $service);
    }

    private function generatedImageAttachment(string $uuid): Attachment
    {
        return Attachment::create([
            'uuid' => $uuid,
            'name' => 'generated_image.png',
            'category' => 'private',
            'type' => 'image',
            'mime' => 'image/png',
            'user_id' => $this->user->id,
        ]);
    }

    private function convert(array $messages, ?AiModel $model = null): array
    {
        $model = $model ?? $this->model();

        return app(ResponsesRequestConverter::class)->convertRequestToPayload(
            new AiRequest(model: $model, payload: [
                'model' => $model->getId(),
                'messages' => $messages,
            ])
        );
    }

    public function test_generated_image_is_replayed_as_input_image_on_a_follow_up(): void
    {
        $this->generatedImageAttachment('uuid-generated-1');
        $this->fakeAttachmentService('PNGBYTES');

        $payload = $this->convert([
            ['role' => 'user', 'content' => ['text' => 'Draw a cat.']],
            ['role' => 'assistant', 'content' => [
                'text' => 'Here is your cat.',
                'auxiliaries' => [[
                    'type' => 'generated_image',
                    'content' => json_encode(['uuid' => 'uuid-generated-1', 'output_index' => 0]),
                ]],
            ]],
            ['role' => 'user', 'content' => ['text' => 'Make it darker.']],
        ]);

        $roles = array_column($payload['input'], 'role');
        $this->assertSame(['user', 'assistant', 'user', 'user'], $roles);

        // The replayed image sits between the assistant turn and the follow-up.
        $replayed = $payload['input'][2];
        $this->assertIsArray($replayed['content']);
        $this->assertSame('input_text', $replayed['content'][0]['type']);
        $this->assertSame('input_image', $replayed['content'][1]['type']);
        $this->assertSame(
            'data:image/png;base64,' . base64_encode('PNGBYTES'),
            $replayed['content'][1]['image_url']
        );

        // The assistant turn itself must stay text - the API rejects input_image there.
        $this->assertSame('Here is your cat.', $payload['input'][1]['content']);
    }

    public function test_user_uploaded_image_becomes_an_input_image_part(): void
    {
        Attachment::create([
            'uuid' => 'uuid-upload-1',
            'name' => 'photo.jpg',
            'category' => 'private',
            'type' => 'image',
            'mime' => 'image/jpeg',
            'user_id' => $this->user->id,
        ]);
        $this->fakeAttachmentService('JPEGBYTES');

        $payload = $this->convert([
            ['role' => 'user', 'content' => [
                'text' => 'What is in this photo?',
                'attachments' => ['uuid-upload-1'],
            ]],
        ]);

        $this->assertIsArray($payload['input']);
        $this->assertSame('user', $payload['input'][0]['role']);
        $parts = $payload['input'][0]['content'];
        $this->assertSame('input_text', $parts[0]['type']);
        $this->assertSame('What is in this photo?', $parts[0]['text']);
        $this->assertSame('input_image', $parts[1]['type']);
        $this->assertSame(
            'data:image/jpeg;base64,' . base64_encode('JPEGBYTES'),
            $parts[1]['image_url']
        );
    }

    public function test_generated_image_is_skipped_when_the_model_has_no_vision(): void
    {
        $this->generatedImageAttachment('uuid-generated-2');
        $this->fakeAttachmentService('PNGBYTES');

        $payload = $this->convert([
            ['role' => 'user', 'content' => ['text' => 'Draw a cat.']],
            ['role' => 'assistant', 'content' => [
                'text' => 'Here is your cat.',
                'auxiliaries' => [[
                    'type' => 'generated_image',
                    'content' => json_encode(['uuid' => 'uuid-generated-2']),
                ]],
            ]],
            ['role' => 'user', 'content' => ['text' => 'Make it darker.']],
        ], $this->model(['input' => ['text'], 'tools' => ['stream' => true, 'vision' => false]]));

        $this->assertSame(['user', 'assistant', 'user'], array_column($payload['input'], 'role'));
    }

    public function test_a_single_plain_user_message_still_collapses_to_a_string(): void
    {
        $payload = $this->convert([
            ['role' => 'user', 'content' => ['text' => 'Hello there.']],
        ]);

        $this->assertSame('Hello there.', $payload['input']);
    }

    public function test_duplicate_generated_image_auxiliaries_are_sent_once(): void
    {
        $this->generatedImageAttachment('uuid-generated-3');
        $this->fakeAttachmentService('PNGBYTES');

        $payload = $this->convert([
            ['role' => 'user', 'content' => ['text' => 'Draw a cat.']],
            ['role' => 'assistant', 'content' => [
                'text' => 'Here.',
                'auxiliaries' => [
                    ['type' => 'generated_image', 'content' => json_encode(['uuid' => 'uuid-generated-3'])],
                    ['type' => 'generated_image', 'content' => json_encode(['uuid' => 'uuid-generated-3'])],
                ],
            ]],
            ['role' => 'user', 'content' => ['text' => 'Again.']],
        ]);

        $imageParts = array_filter(
            $payload['input'][2]['content'],
            fn(array $part): bool => $part['type'] === 'input_image'
        );
        $this->assertCount(1, $imageParts);
    }
}
