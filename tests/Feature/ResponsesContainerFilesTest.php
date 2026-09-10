<?php

namespace Tests\Feature;

use App\Services\AI\Interfaces\ModelProviderInterface;
use App\Services\AI\Providers\Responses\ContainerFiles;
use App\Services\AI\Providers\Responses\Request\ResponsesRequest;
use App\Services\AI\Providers\Responses\Request\ResponsesStreamingRequest;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiResponse;
use App\Services\AI\Value\ProviderConfig;
use App\Services\Chat\Attachment\AttachmentService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A file the code interpreter writes exists only in OpenAI's container, and the
 * model links it as sandbox:/mnt/data/… - a dead link for the user. HAWKI fetches
 * it through the containers endpoint while the container lives and stores it in
 * its own filesystem, announced as a 'container_file' auxiliary.
 */
class ResponsesContainerFilesTest extends TestCase
{
    private const CONTENT_URL = 'https://api.openai.com/v1/containers/cntr_1/files/cfile_1/content';

    private array $stored = [];

    protected function setUp(): void
    {
        parent::setUp();

        $attachments = $this->createMock(AttachmentService::class);
        $attachments->method('storeGeneratedFile')->willReturnCallback(
            function (string $bytes, string $filename, string $category, ?string $mimeHint) {
                $this->stored[] = compact('bytes', 'filename', 'category', 'mimeHint');

                return [
                    'uuid' => 'file-uuid-'.count($this->stored),
                    'url' => 'https://hawki.test/files/'.$filename,
                    'mime' => 'text/csv',
                    'name' => $filename,
                ];
            }
        );
        $this->app->instance(AttachmentService::class, $attachments);
    }

    public function test_a_cited_container_file_is_fetched_and_announced_once(): void
    {
        Http::fake([self::CONTENT_URL => Http::response("a,b\n1,2\n", 200, ['Content-Type' => 'text/csv'])]);

        $request = new ResponsesStreamingRequest(['model' => 'gpt-5'], static function (): void {});

        $first = $this->stream($request, $this->annotationChunk());
        $second = $this->stream($request, $this->annotationChunk());

        $files = $this->containerFiles($first);
        $this->assertCount(1, $files);
        $this->assertSame('daten.csv', $files[0]['filename']);
        $this->assertSame('https://hawki.test/files/daten.csv', $files[0]['url']);
        $this->assertSame('file-uuid-1', $files[0]['uuid']);
        $this->assertSame(2, $files[0]['output_index']);

        // Mentioned twice - the image and the download link - but one file.
        $this->assertCount(0, $this->containerFiles($second));
        $this->assertCount(1, $this->stored);
        $this->assertSame("a,b\n1,2\n", $this->stored[0]['bytes']);
        $this->assertSame('private', $this->stored[0]['category']);

        Http::assertSent(fn ($request) => $request->url() === self::CONTENT_URL
            && $request->hasHeader('Authorization', 'Bearer sk-test'));
    }

    public function test_a_failed_fetch_is_logged_and_the_answer_goes_on(): void
    {
        Http::fake([self::CONTENT_URL => Http::response('', 404)]);

        $request = new ResponsesStreamingRequest(['model' => 'gpt-5'], static function (): void {});

        $response = $this->stream($request, $this->annotationChunk());

        $this->assertNull($response->error);
        $this->assertCount(0, $this->containerFiles($response));
        $this->assertCount(0, $this->stored);
    }

    public function test_a_file_beyond_the_size_limit_is_not_taken(): void
    {
        Http::fake([self::CONTENT_URL => Http::response(str_repeat('x', ContainerFiles::MAX_BYTES + 1), 200)]);

        $request = new ResponsesStreamingRequest(['model' => 'gpt-5'], static function (): void {});

        $response = $this->stream($request, $this->annotationChunk());

        $this->assertCount(0, $this->containerFiles($response));
        $this->assertCount(0, $this->stored);
    }

    public function test_other_annotations_are_left_alone(): void
    {
        Http::fake();

        $request = new ResponsesStreamingRequest(['model' => 'gpt-5'], static function (): void {});

        $response = $this->stream($request, [
            'type' => 'response.output_text.annotation.added',
            'output_index' => 2,
            'annotation' => ['type' => 'url_citation', 'url' => 'https://example.org', 'title' => 'Example'],
        ]);

        $this->assertCount(0, $this->containerFiles($response));
        Http::assertNothingSent();
    }

    /**
     * Group chats answer without streaming; the citations sit in the message part.
     */
    public function test_a_non_streamed_response_fetches_the_files_its_message_cites(): void
    {
        Http::fake([self::CONTENT_URL => Http::response("a,b\n", 200, ['Content-Type' => 'text/csv'])]);

        $request = new ResponsesRequest(['model' => 'gpt-5']);
        $method = new \ReflectionMethod($request, 'dataToResponse');
        $method->setAccessible(true);

        $response = $method->invoke($request, [
            'id' => 'resp_1',
            'output' => [
                ['type' => 'code_interpreter_call', 'status' => 'completed', 'code' => 'x = 1', 'outputs' => []],
                ['type' => 'message', 'content' => [[
                    'type' => 'output_text',
                    'text' => 'Die Tabelle: [daten.csv](sandbox:/mnt/data/daten.csv)',
                    'annotations' => [$this->citation(), $this->citation()],
                ]]],
            ],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
        ], $this->model());

        $files = $this->containerFiles($response);
        $this->assertCount(1, $files);
        $this->assertSame('daten.csv', $files[0]['filename']);
        $this->assertCount(1, $this->stored);
    }

    public function test_the_containers_endpoint_hangs_off_the_provider_api_root(): void
    {
        $this->assertSame('https://api.openai.com/v1', ContainerFiles::containersBaseUrl('https://api.openai.com/v1/responses'));
        $this->assertSame('https://gateway.example/openai/v1', ContainerFiles::containersBaseUrl('https://gateway.example/openai/v1/responses/'));
        $this->assertSame('https://api.openai.com/v1', ContainerFiles::containersBaseUrl('https://api.openai.com/v1'));
    }

    /**
     * The extension decides for the text formats sniffing cannot tell apart, a
     * specific hint next, the bytes last.
     */
    public function test_the_mime_type_of_a_generated_file_is_resolved_sensibly(): void
    {
        $service = new AttachmentService(...$this->attachmentServiceDependencies());

        $this->assertSame('text/csv', $service->mimeOfGeneratedFile("a,b\n", 'daten.csv', 'text/plain'));
        $this->assertSame('application/json', $service->mimeOfGeneratedFile('{}', 'out.json', null));
        $this->assertSame('application/x-custom', $service->mimeOfGeneratedFile('...', 'blob.bin', 'application/x-custom; charset=binary'));
        $this->assertSame('image/png', $service->mimeOfGeneratedFile(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='), 'noext', 'application/octet-stream'));
    }

    private function attachmentServiceDependencies(): array
    {
        $constructor = new \ReflectionMethod(AttachmentService::class, '__construct');

        return array_map(
            fn (\ReflectionParameter $parameter) => $this->createMock($parameter->getType()->getName()),
            $constructor->getParameters()
        );
    }

    private function annotationChunk(): array
    {
        return [
            'type' => 'response.output_text.annotation.added',
            'output_index' => 2,
            'annotation' => $this->citation(),
        ];
    }

    private function citation(): array
    {
        return [
            'type' => 'container_file_citation',
            'container_id' => 'cntr_1',
            'file_id' => 'cfile_1',
            'filename' => 'daten.csv',
            'start_index' => 10,
            'end_index' => 40,
        ];
    }

    private function model(): AiModel
    {
        $config = new ProviderConfig('openai', [
            'active' => true,
            'adapter' => 'Responses',
            'api_key' => 'sk-test',
            'api_url' => 'https://api.openai.com/v1/responses',
        ]);

        $provider = $this->createMock(ModelProviderInterface::class);
        $provider->method('getConfig')->willReturn($config);

        $model = $this->createMock(AiModel::class);
        $model->method('getProvider')->willReturn($provider);

        return $model;
    }

    private function stream(ResponsesStreamingRequest $request, array $chunk): AiResponse
    {
        $method = new \ReflectionMethod($request, 'chunkToResponse');
        $method->setAccessible(true);

        return $method->invoke($request, $this->model(), json_encode($chunk));
    }

    private function containerFiles(AiResponse $response): array
    {
        return array_values(array_map(
            static fn (array $aux): array => json_decode($aux['content'], true),
            array_filter(
                $response->content['auxiliaries'] ?? [],
                static fn (array $aux): bool => $aux['type'] === 'container_file'
            )
        ));
    }
}
