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

    public function test_a_generated_image_reaches_the_model_as_a_normal_attachment(): void
    {
        $this->generatedImageAttachment('uuid-generated-1');
        $this->fakeAttachmentService('PNGBYTES');

        $payload = $this->convert([
            ['role' => 'user', 'content' => ['text' => 'Draw a cat.']],
            ['role' => 'assistant', 'content' => ['text' => 'Here is your cat.']],
            ['role' => 'user', 'content' => [
                'text' => 'Make it darker.',
                'attachments' => ['uuid-generated-1'],
            ]],
        ]);

        $this->assertSame(['user', 'assistant', 'user'], array_column($payload['input'], 'role'));

        $parts = $payload['input'][2]['content'];
        $this->assertSame('input_text', $parts[0]['type']);
        $this->assertSame('Make it darker.', $parts[0]['text']);
        $this->assertSame('input_image', $parts[1]['type']);
        $this->assertSame(
            'data:image/png;base64,' . base64_encode('PNGBYTES'),
            $parts[1]['image_url']
        );
    }

    public function test_nothing_is_replayed_from_the_generated_image_auxiliaries(): void
    {
        $this->generatedImageAttachment('uuid-generated-9');
        $this->fakeAttachmentService('PNGBYTES');

        // Attachments are the only route now, so an auxiliary on its own adds
        // no image and no extra turn.
        $payload = $this->convert([
            ['role' => 'user', 'content' => ['text' => 'Draw a cat.']],
            ['role' => 'assistant', 'content' => [
                'text' => 'Here is your cat.',
                'auxiliaries' => [[
                    'type' => 'generated_image',
                    'content' => json_encode(['uuid' => 'uuid-generated-9', 'output_index' => 0]),
                ]],
            ]],
            ['role' => 'user', 'content' => ['text' => 'Make it darker.']],
        ]);

        $this->assertSame(['user', 'assistant', 'user'], array_column($payload['input'], 'role'));
        $this->assertSame('Make it darker.', $payload['input'][2]['content']);
        $this->assertStringNotContainsString('input_image', json_encode($payload['input']));
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

    public function test_an_attached_image_is_reported_as_skipped_without_vision(): void
    {
        $this->generatedImageAttachment('uuid-generated-2');
        $this->fakeAttachmentService('PNGBYTES');

        $payload = $this->convert([
            ['role' => 'user', 'content' => [
                'text' => 'Make it darker.',
                'attachments' => ['uuid-generated-2'],
            ]],
        ], $this->model(['input' => ['text'], 'tools' => ['stream' => true, 'vision' => false]]));

        $parts = $payload['input'][0]['content'];
        $this->assertStringNotContainsString('input_image', json_encode($parts));
        $this->assertStringContainsString('image not supported', $parts[1]['text']);
    }

    public function test_a_single_plain_user_message_still_collapses_to_a_string(): void
    {
        $payload = $this->convert([
            ['role' => 'user', 'content' => ['text' => 'Hello there.']],
        ]);

        $this->assertSame('Hello there.', $payload['input']);
    }

    public function test_the_aspect_ratio_picks_the_orientation_and_travels_on(): void
    {
        $cases = [
            ['9:16', '1024x1536'],
            ['3:4', '1024x1536'],
            ['16:9', '1536x1024'],
            ['4:3', '1536x1024'],
            ['1:1', '1024x1024'],
        ];

        foreach ($cases as [$ratio, $expectedApiSize]) {
            $payload = $this->convertWithImageGeneration(['image_generation_ratio' => $ratio]);
            $tool = $this->imageGenerationTool($payload);

            $this->assertSame($expectedApiSize, $tool['size'], $ratio);
            $this->assertSame($ratio, $payload['_hawki_image_generation_ratio'], $ratio);
        }
    }

    /**
     * The chat UI has no size buttons: the stored picture is the small preset,
     * whatever an older client may still send.
     */
    public function test_without_a_ratio_the_image_is_the_small_preset(): void
    {
        foreach ([[], ['image_generation_size' => 'big']] as $extra) {
            $payload = $this->convertWithImageGeneration($extra);

            $this->assertSame('1024x1024', $this->imageGenerationTool($payload)['size']);
            $this->assertSame('small', $payload['_hawki_image_generation_size']);
            $this->assertArrayNotHasKey('_hawki_image_generation_ratio', $payload);
        }
    }

    public function test_a_malformed_ratio_is_ignored(): void
    {
        $payload = $this->convertWithImageGeneration(['image_generation_ratio' => 'sixteen by nine']);

        $this->assertSame('1024x1024', $this->imageGenerationTool($payload)['size']);
        $this->assertArrayNotHasKey('_hawki_image_generation_ratio', $payload);
    }

    private function convertWithImageGeneration(array $extra): array
    {
        $model = $this->model();

        return app(ResponsesRequestConverter::class)->convertRequestToPayload(
            new AiRequest(model: $model, payload: array_merge([
                'model' => $model->getId(),
                'messages' => [['role' => 'user', 'content' => ['text' => 'Draw a cat.']]],
                'tools' => ['image_generation' => true],
            ], $extra))
        );
    }

    private function imageGenerationTool(array $payload): array
    {
        foreach ($payload['tools'] ?? [] as $tool) {
            if (($tool['type'] ?? '') === 'image_generation') {
                return $tool;
            }
        }

        $this->fail('the image generation tool should be in the payload');
    }
}
