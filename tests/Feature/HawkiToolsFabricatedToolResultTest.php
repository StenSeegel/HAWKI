<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\Providers\OpenAiHawkiTools\Request\OpenAiHawkiToolsStreamingRequest;
use App\Services\AI\Tools\ToolCallRunner;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiResponse;
use Tests\TestCase;

/**
 * The 'fabricated_tool_result' auxiliary of the streamed HAWKI tools path: a
 * sandbox: link in an answer for which no code ran names a file the model made
 * up, and the frontend replaces such an answer as a whole.
 *
 * The chunks are fed to the request directly, as in the citations test, and the
 * check that runs after the last round is called the same way.
 */
class HawkiToolsFabricatedToolResultTest extends TestCase
{
    private AiModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = $this->createMock(AiModel::class);
        $this->model->method('getId')->willReturn('jlu/gemma-4-26b-it');
    }

    private function request(bool $codeInterpreterRan = false): object
    {
        $payload = [
            'model' => 'jlu/gemma-4-26b-it',
            'messages' => [],
            'tools' => [['type' => 'function']],
        ];

        $request = new class($payload, fn () => null, app(ToolCallRunner::class)) extends OpenAiHawkiToolsStreamingRequest
        {
            public function __construct(array $payload, \Closure $callback, ToolCallRunner $runner)
            {
                parent::__construct($payload, $callback, [], $runner);
            }

            public function handle(AiModel $model, string $chunk): AiResponse
            {
                return $this->chunkToResponse($model, $chunk);
            }

            public function finish(AiModel $model): ?AiResponse
            {
                return $this->fabricationFlagAfterStream($model);
            }
        };

        // Set by a tool round, which needs a live sandbox to run.
        $property = new \ReflectionProperty(OpenAiHawkiToolsStreamingRequest::class, 'codeInterpreterRan');
        $property->setValue($request, $codeInterpreterRan);

        return $request;
    }

    private function flagOf(?AiResponse $response): ?array
    {
        foreach ($response?->content['auxiliaries'] ?? [] as $auxiliary) {
            if ($auxiliary['type'] === 'fabricated_tool_result') {
                return json_decode($auxiliary['content'], true);
            }
        }

        return null;
    }

    /**
     * Streams the answer and ends it with the given finish reason.
     *
     * @return array{0: AiResponse, 1: ?AiResponse} the last chunk, and the chunk
     *                                             sent after the last round
     */
    private function answer(string $text, bool $codeInterpreterRan = false, string $finishReason = 'stop'): array
    {
        $request = $this->request($codeInterpreterRan);

        $request->handle($this->model, json_encode(['choices' => [['delta' => ['content' => $text]]]]));
        $last = $request->handle($this->model, json_encode(['choices' => [['delta' => new \stdClass, 'finish_reason' => $finishReason]]]));

        return [$last, $request->finish($this->model)];
    }

    private function assertNotFlagged(string $text): void
    {
        [$last, $after] = $this->answer($text);

        $this->assertNull($this->flagOf($last), $text);
        $this->assertNull($after, $text);
    }

    public function test_a_sandbox_link_without_a_run_flags_the_finishing_chunk(): void
    {
        // The client stops reading at the first chunk marked done, so the flag
        // has to ride that chunk - a chunk after it is never seen.
        [$last, $after] = $this->answer('Du kannst die fertige Datei hier herunterladen: [Praesentation_JLU_RAG.pptx](sandbox:/tmp/Praesentation_JLU_RAG.pptx)');

        $this->assertTrue($last->isDone);
        $this->assertSame(['found' => ['sandbox link']], $this->flagOf($last));
        $this->assertNull($after);
    }

    public function test_an_answer_cut_off_by_the_token_limit_is_flagged_after_the_stream(): void
    {
        // finish_reason 'length' marks no chunk done; the client reads on until
        // the connection closes, and gets the flag as a chunk of its own.
        [$last, $after] = $this->answer('[Deck.pptx](sandbox:/tmp/Deck.pptx) und weiter', finishReason: 'length');

        $this->assertNull($this->flagOf($last));
        $this->assertSame(['found' => ['sandbox link']], $this->flagOf($after));
        $this->assertSame('', $after->content['text']);
        $this->assertFalse($after->isDone);
    }

    public function test_a_sandbox_link_after_a_run_is_not_flagged(): void
    {
        [$last, $after] = $this->answer('[Deck.pptx](sandbox:/tmp/Deck.pptx)', codeInterpreterRan: true);

        $this->assertNull($this->flagOf($last));
        $this->assertNull($after);
    }

    public function test_a_sandbox_path_in_an_explanation_is_not_flagged(): void
    {
        $this->assertNotFlagged('Der Pfad sandbox:/tmp/ ist nur im Container gueltig.');
        $this->assertNotFlagged('Ein Link sieht so aus: `[Deck.pptx](sandbox:/tmp/Deck.pptx)`');
        $this->assertNotFlagged("```markdown\n[Deck.pptx](sandbox:/tmp/Deck.pptx)\n```");
    }

    public function test_an_output_block_alone_is_not_flagged(): void
    {
        // A model also writes the expected output of an example as a block.
        $this->assertNotFlagged("```output\n42\n```");
    }
}
