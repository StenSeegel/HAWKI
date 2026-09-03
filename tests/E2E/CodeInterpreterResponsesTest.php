<?php

declare(strict_types=1);

namespace Tests\E2E;

use App\Http\Middleware\ChatAccess;
use App\Http\Middleware\MandatorySignatureCheck;
use App\Http\Middleware\SessionExpiryChecker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The native code interpreter of the Responses API, end to end: this test boots
 * the local application, sends a real chat request through /req/streamAI with the
 * live OpenAI provider configuration, and reads the stream the browser would read.
 *
 * Everything except the upstream API is the real thing - the route, the two
 * middlewares that guard it, the request converter, the SSE parser and the
 * auxiliaries the frontend renders from. Only the network call leaves the machine.
 *
 * What it is here to prove, which no mocked test can:
 *
 *  1. the model actually calls the tool for a prompt that needs it;
 *  2. the run is identifiable in the UI - a "Running code..." step that resolves
 *     into "Code executed", in that order, both live and after a reload;
 *  3. the code and its output end up in the message, so the user sees them and
 *     the next turn carries them back to the model;
 *  4. a prompt that needs no code triggers no tool.
 *
 * See LiveAiProvider for how the provider configuration is obtained and how to
 * run this. It is in the 'e2e' group, which phpunit.xml excludes by default.
 */
#[Group('e2e')]
class CodeInterpreterResponsesTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER = 'openai';

    /**
     * The model the code interpreter capability is ticked on. Override with
     * E2E_CODE_INTERPRETER_MODEL to measure a different one.
     */
    private const DEFAULT_MODEL = 'gpt-5.6-luna';

    /**
     * The prompt has to be genuinely out of reach, or it proves nothing: a model
     * that can compute something in its head will, tool or no tool. A SHA-256
     * digest cannot be recalled or reasoned out, so an exact match is only
     * possible if code really ran.
     */
    private const PROBE_STRING = 'hawki-code-interpreter-probe';

    private string $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        try {
            $this->model = LiveAiProvider::seed(self::PROVIDER, $this->modelId());
        } catch (SkipLiveProvider $e) {
            $this->markTestSkipped($e->getMessage());
        }

        // The controller clears every output buffer before streaming, which would
        // take the one this test captures the stream with. Off, the buffers are
        // left alone and the echoed chunks land where they can be read.
        config(['system.disable_stream_buffering' => false]);
    }

    private function modelId(): string
    {
        $configured = getenv('E2E_CODE_INTERPRETER_MODEL');

        return is_string($configured) && $configured !== '' ? $configured : self::DEFAULT_MODEL;
    }

    public function test_a_computational_prompt_runs_code_and_returns_the_exact_result(): void
    {
        $chunks = $this->stream(
            'Compute the SHA-256 hex digest of the exact string '.self::PROBE_STRING
            .' and answer with the digest only.'
        );

        $statuses = $this->codeInterpreterStatuses($chunks);

        $this->assertNotEmpty(
            $statuses,
            'The model did not call the code interpreter. Either the tool is not reaching the payload '
            .'or the prompt is not out of reach for this model. Events seen: '
            .json_encode($this->statusTypes($chunks))
        );

        $this->assertRunsResolved($statuses);

        $this->assertStringContainsString(
            hash('sha256', self::PROBE_STRING),
            $this->text($chunks),
            'The answer does not carry the real digest, so whatever ran did not compute it.'
        );
    }

    /**
     * The status step is what tells the user why the answer took a moment. It has
     * to survive the reload too, which means the persisted log - not just the live
     * auxiliaries - has to carry the run in a sane order.
     */
    public function test_the_run_is_identifiable_in_the_persisted_status_log(): void
    {
        $chunks = $this->stream(
            'Compute the SHA-256 hex digest of the exact string '.self::PROBE_STRING
            .' and answer with the digest only.'
        );

        $log = $this->statusLog($chunks);

        $this->assertNotEmpty($log, 'No status_log auxiliary was persisted with the message.');

        $steps = array_values(array_map(
            static fn (array $entry) => $entry['status'],
            array_filter($log, static fn (array $entry) => ($entry['type'] ?? null) === 'code_interpreter')
        ));

        $this->assertRunsResolved($steps);
        $this->assertSame(
            ['in_progress', 'completed'],
            array_slice($steps, 0, 2),
            'The reloaded message must show "Running code..." before "Code executed". A trailing '
            .'in_progress entry renders as a step that never finishes.'
        );
    }

    /**
     * The user asked for an answer, not for a transcript - but a computed answer
     * they cannot check is worth little, so the code and its output belong in the
     * message. Being in the message is also what puts them in front of the model
     * on the next turn.
     */
    public function test_the_executed_code_is_written_into_the_message(): void
    {
        $chunks = $this->stream(
            'Compute the SHA-256 hex digest of the exact string '.self::PROBE_STRING
            .' and answer with the digest only.'
        );

        $text = $this->text($chunks);

        $this->assertStringContainsString(
            '```python',
            $text,
            'The message carries no python block, so the user cannot see or re-run what was executed.'
        );

        $call = $this->auxiliaries($chunks, 'code_interpreter_call');

        $this->assertNotEmpty($call, 'No code_interpreter_call auxiliary was emitted.');
        $this->assertNotSame(
            '',
            trim($call[0]['code'] ?? ''),
            'The auxiliary carries no code. The code deltas are named '
            .'response.code_interpreter_call_code.*, not response.code_interpreter_code.*.'
        );
    }

    /**
     * The API returns 'outputs' as null unless the request asks for it, so
     * without the 'include' the user saw the code with nothing under it. This is
     * the assertion that pins that request field down.
     */
    public function test_what_the_sandbox_printed_comes_back_and_is_shown(): void
    {
        $chunks = $this->stream(
            'Run Python that prints the SHA-256 hex digest of the exact string '
            .self::PROBE_STRING.' using print(), then report what it printed.'
        );

        $call = $this->auxiliaries($chunks, 'code_interpreter_call');

        $this->assertNotEmpty($call, 'No code_interpreter_call auxiliary was emitted.');

        $this->assertSame(
            hash('sha256', self::PROBE_STRING),
            trim($call[0]['output'] ?? ''),
            'The sandbox output did not reach HAWKI. The request needs '
            ."include: ['code_interpreter_call.outputs']."
        );

        $this->assertStringContainsString(
            "```output\n".hash('sha256', self::PROBE_STRING),
            $this->text($chunks),
            'The output is not rendered under the code, so the user cannot see the result of the run.'
        );
    }

    /**
     * A plot arrives as an 'image' output whose url is a complete data URI. It
     * used to be turned into the literal text "[image]", so the model announced a
     * chart and the message showed none - the bug this suite was extended for.
     */
    public function test_a_plot_is_stored_and_handed_over_as_an_image(): void
    {
        $chunks = $this->stream(
            'Plot y = sin(x) for x from 0 to 2*pi with matplotlib and show me the chart.'
        );

        $images = $this->auxiliaries($chunks, 'generated_image');

        $this->assertNotEmpty(
            $images,
            'No generated_image auxiliary was emitted, so the chart the model drew is nowhere in the message.'
        );

        $this->assertNotSame('', (string) ($images[0]['url'] ?? ''), 'The stored plot has no URL.');
        $this->assertNotSame('', (string) ($images[0]['uuid'] ?? ''), 'Without a uuid the plot cannot be linked to the message.');

        $text = $this->text($chunks);

        $this->assertStringNotContainsString(
            'iVBORw0KGgo',
            $text,
            'The base64 of the plot is in the message text; only the stored URL belongs there.'
        );
        $this->assertStringNotContainsString(
            '[image]',
            $text,
            'The image output was rendered as placeholder text instead of being stored.'
        );
    }

    public function test_a_question_of_stable_knowledge_runs_no_code(): void
    {
        $chunks = $this->stream('Was ist die Hauptstadt von Frankreich? Antworte in einem Satz.');

        $this->assertEmpty(
            $this->codeInterpreterStatuses($chunks),
            'The model ran code for a question it should answer directly. Tune the awareness prompt.'
        );

        $this->assertStringContainsString('Paris', $this->text($chunks));
    }


    /**
     * Every started run has to announce itself and then resolve, or the UI keeps
     * spinning. Two things make this looser than one exact pair:
     *
     * - the native path sends 'in_progress' three times for one call
     *   (output_item.added, ...call.in_progress, ...call.interpreting), which the
     *   frontend deduplicates, so repeats are expected and harmless;
     * - a model may call the tool several times - qwen3-coder-next uses all three
     *   rounds - so several pairs are expected too.
     *
     * Collapsing consecutive duplicates leaves exactly the invariant worth
     * asserting: alternating in_progress, completed, ending on completed.
     *
     * @param  array<int,string>  $statuses
     */
    private function assertRunsResolved(array $statuses): void
    {
        $this->assertNotEmpty($statuses, 'No code interpreter run was reported at all.');

        $collapsed = [];
        foreach ($statuses as $status) {
            if (end($collapsed) !== $status) {
                $collapsed[] = $status;
            }
        }

        $this->assertSame(
            0,
            count($collapsed) % 2,
            'A run was started and never resolved: '.json_encode($statuses)
        );

        foreach (array_chunk($collapsed, 2) as $index => $pair) {
            $this->assertSame(
                ['in_progress', 'completed'],
                $pair,
                'Run '.($index + 1).' did not announce itself and then resolve: '.json_encode($statuses)
            );
        }
    }

    /**
     * Sends the prompt the way the browser does and returns the decoded chunks.
     *
     * @return array<int,array<string,mixed>>
     */
    private function stream(string $prompt): array
    {
        $user = User::where('username', 'admin')->firstOrFail();

        $payload = [
            'payload' => [
                'model' => $this->model,
                'stream' => true,
                'messages' => [
                    ['role' => 'user', 'content' => ['text' => $prompt]],
                ],
            ],
            'broadcast' => false,
        ];

        // The controller flushes after every chunk, which would push the chunk
        // out of a plain buffer and onto the console. An output callback gets
        // handed each flushed chunk instead, collects it, and returns nothing so
        // the test run stays readable.
        $raw = '';
        ob_start(function (string $chunk) use (&$raw): string {
            $raw .= $chunk;

            return '';
        });

        try {
            $this->actingAs($user)
                ->withoutMiddleware([
                    SessionExpiryChecker::class,
                    MandatorySignatureCheck::class,
                    ChatAccess::class,
                ])
                ->postJson('/req/streamAI', $payload);
        } finally {
            ob_end_flush();
        }

        $chunks = [];

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                $chunks[] = $decoded;
            }
        }

        $this->assertNotEmpty(
            $chunks,
            'The stream produced nothing readable. Raw output: '.substr($raw, 0, 2000)
        );

        return $chunks;
    }

    /**
     * @param  array<int,array<string,mixed>>  $chunks
     */
    private function text(array $chunks): string
    {
        $text = '';

        foreach ($chunks as $chunk) {
            $content = json_decode((string) ($chunk['content'] ?? ''), true);
            $text .= $content['text'] ?? '';
        }

        return $text;
    }

    /**
     * The decoded content of every auxiliary of one type, in arrival order.
     *
     * @param  array<int,array<string,mixed>>  $chunks
     * @return array<int,array<string,mixed>>
     */
    private function auxiliaries(array $chunks, string $type): array
    {
        $found = [];

        foreach ($chunks as $chunk) {
            $content = json_decode((string) ($chunk['content'] ?? ''), true);

            foreach ($content['auxiliaries'] ?? [] as $auxiliary) {
                if (($auxiliary['type'] ?? null) !== $type) {
                    continue;
                }

                $decoded = json_decode((string) ($auxiliary['content'] ?? ''), true);

                if (is_array($decoded)) {
                    $found[] = $decoded;
                }
            }
        }

        return $found;
    }

    /**
     * @param  array<int,array<string,mixed>>  $chunks
     * @return array<int,string>
     */
    private function codeInterpreterStatuses(array $chunks): array
    {
        return array_values(array_map(
            static fn (array $status) => (string) $status['status'],
            array_filter(
                $this->auxiliaries($chunks, 'status'),
                static fn (array $status) => ($status['type'] ?? null) === 'code_interpreter'
            )
        ));
    }

    /**
     * Every status type that arrived, for the failure message of a test that
     * expected a tool call and did not get one.
     *
     * @param  array<int,array<string,mixed>>  $chunks
     * @return array<int,string>
     */
    private function statusTypes(array $chunks): array
    {
        return array_values(array_unique(array_map(
            static fn (array $status) => (string) ($status['type'] ?? $status['status'] ?? '?'),
            $this->auxiliaries($chunks, 'status')
        )));
    }

    /**
     * @param  array<int,array<string,mixed>>  $chunks
     * @return array<int,array<string,mixed>>
     */
    private function statusLog(array $chunks): array
    {
        $logs = $this->auxiliaries($chunks, 'status_log');

        return $logs === [] ? [] : ($logs[count($logs) - 1]['log'] ?? []);
    }
}
