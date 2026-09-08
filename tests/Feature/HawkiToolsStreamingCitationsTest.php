<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\Providers\OpenAiHawkiTools\Request\OpenAiHawkiToolsStreamingRequest;
use App\Services\AI\Tools\ToolCallRunner;
use App\Services\AI\Tools\WebSearchSources;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiResponse;
use Tests\TestCase;

/**
 * The citations auxiliary of the streamed HAWKI tools path.
 *
 * The upstream transport speaks raw cURL and cannot be faked, so the chunks are
 * fed to the request directly - which is what decides whether the sources ride
 * the finishing chunk, and only that one.
 */
class HawkiToolsStreamingCitationsTest extends TestCase
{
    private AiModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = $this->createMock(AiModel::class);
        $this->model->method('getId')->willReturn('jlu/gemma-4-26b-it');

        app(WebSearchSources::class)->collect(
            "## Sources\n\n1. [PHP 8.4](https://www.php.net/releases/8.4/)\n"
        );
    }

    /**
     * The request with its protected chunk handler opened up, and with the tools
     * still attached unless the caller says otherwise.
     */
    private function request(bool $withTools = true): object
    {
        $payload = ['model' => 'jlu/gemma-4-26b-it', 'messages' => []];

        if ($withTools) {
            $payload['tools'] = [['type' => 'function']];
        }

        return new class($payload, fn () => null, app(ToolCallRunner::class)) extends OpenAiHawkiToolsStreamingRequest
        {
            public function __construct(array $payload, \Closure $callback, ToolCallRunner $runner)
            {
                parent::__construct($payload, $callback, [], $runner);
            }

            public function handle(AiModel $model, string $chunk): AiResponse
            {
                return $this->chunkToResponse($model, $chunk);
            }
        };
    }

    private function citationsOf(AiResponse $response): ?array
    {
        foreach ($response->content['auxiliaries'] ?? [] as $auxiliary) {
            if ($auxiliary['type'] === 'hawkiToolsCitations') {
                return json_decode($auxiliary['content'], true)['citations'];
            }
        }

        return null;
    }

    private function contentChunk(string $text): string
    {
        return json_encode(['choices' => [['delta' => ['content' => $text]]]]);
    }

    private function stopChunk(): string
    {
        return json_encode(['choices' => [['delta' => new \stdClass, 'finish_reason' => 'stop']]]);
    }

    private function toolCallChunk(): string
    {
        return json_encode(['choices' => [[
            'delta' => ['tool_calls' => [[
                'index' => 0,
                'id' => 'call_1',
                'function' => ['name' => 'web_search', 'arguments' => '{"query":"php"}'],
            ]]],
            'finish_reason' => 'tool_calls',
        ]]]);
    }

    public function test_a_page_the_answer_links_becomes_a_source(): void
    {
        $request = $this->request();

        // The model followed a link on the fetched page. The tool never
        // reported that URL, so only the streamed text carries it.
        $request->handle($this->model, $this->contentChunk('Neu in [8.4]('));
        $request->handle($this->model, $this->contentChunk('https://www.php.net/manual/en/migration84.php).'));

        $citations = $this->citationsOf($request->handle($this->model, $this->stopChunk()));

        $this->assertSame(
            [
                'https://www.php.net/releases/8.4/',
                'https://www.php.net/manual/en/migration84.php',
            ],
            array_column($citations, 'url')
        );
    }

    public function test_the_finishing_chunk_carries_the_collected_sources(): void
    {
        $request = $this->request();

        $this->assertNull($this->citationsOf($request->handle($this->model, $this->contentChunk('PHP 8.4 '))));

        $citations = $this->citationsOf($request->handle($this->model, $this->stopChunk()));

        $this->assertNotNull($citations);
        $this->assertSame('https://www.php.net/releases/8.4/', $citations[0]['url']);
        $this->assertSame('PHP 8.4', $citations[0]['title']);
    }

    public function test_a_round_that_ends_in_a_tool_call_carries_nothing_yet(): void
    {
        // Another upstream request follows, so this is not the end of the
        // message - and a partial list emitted here is the one the client would
        // keep, because it reads the first citations auxiliary it finds.
        $request = $this->request();

        $request->handle($this->model, $this->toolCallChunk());
        $response = $request->handle($this->model, $this->stopChunk());

        $this->assertNull($this->citationsOf($response));
        $this->assertFalse(app(WebSearchSources::class)->isEmpty());
    }

    public function test_the_final_round_emits_even_when_the_model_asks_again(): void
    {
        // In the final round the tools are withdrawn and no further round runs,
        // so the sources of the rounds that did run would be lost with it.
        $request = $this->request(withTools: false);

        $request->handle($this->model, $this->toolCallChunk());
        $citations = $this->citationsOf($request->handle($this->model, $this->stopChunk()));

        $this->assertNotNull($citations);
        $this->assertSame('https://www.php.net/releases/8.4/', $citations[0]['url']);
    }

    public function test_the_sources_are_emitted_once(): void
    {
        $request = $this->request();

        $this->assertNotNull($this->citationsOf($request->handle($this->model, $this->stopChunk())));
        // A second finishing chunk - the usage chunk LiteLLM sends after the
        // stop chunk arrives the same way - must not repeat them.
        $this->assertNull($this->citationsOf($request->handle($this->model, $this->stopChunk())));
    }

    public function test_an_answer_without_a_search_carries_no_citations(): void
    {
        app(WebSearchSources::class)->drain();

        $response = $this->request()->handle($this->model, $this->stopChunk());

        $this->assertNull($this->citationsOf($response));
    }
}
