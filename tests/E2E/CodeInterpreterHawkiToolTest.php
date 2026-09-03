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
 * HAWKI's own code interpreter, end to end: a model on the ki@JLU gateway, whose
 * API brings no sandbox of its own, running Python on code-exec-mcp through the
 * HAWKI tool runtime.
 *
 * This is the same test matrix as CodeInterpreterResponsesTest, deliberately: a
 * user cannot tell which provider serves a model, so the two implementations have
 * to be indistinguishable in the chat. The status step, the code block and the
 * output block all have to look the same.
 *
 * Unlike the native path this one really does execute the tool here - the MCP
 * server is reached from this machine, through the gateway - so it needs the
 * gateway key and a reachable MCP endpoint, and it is slower.
 *
 * See LiveAiProvider for the provider configuration and how to run this.
 */
#[Group('e2e')]
class CodeInterpreterHawkiToolTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER = 'ki-at-jlu';

    /**
     * The gateway model the code interpreter capability is ticked on. Override
     * with E2E_HAWKI_CODE_INTERPRETER_MODEL to measure a different one - Gemma in
     * particular has needed its own prompt placement for web search, so it is
     * worth measuring separately.
     */
    private const DEFAULT_MODEL = 'jlu/qwen3.8-27b';

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

        // The tool must be HAWKI's here, not a native one the gateway does not have.
        $overridden = app(\App\Services\AI\AiService::class)
            ->getModelOrFail($this->model)
            ->getProvider()
            ->getConfig()
            ->isHawkiToolOverridden('code_interpreter');

        if (! $overridden) {
            $this->markTestSkipped(
                "The code_interpreter override is off for provider '".self::PROVIDER
                ."', so this model gets no HAWKI code interpreter to test."
            );
        }

        config(['system.disable_stream_buffering' => false]);
    }

    private function modelId(): string
    {
        $configured = getenv('E2E_HAWKI_CODE_INTERPRETER_MODEL');

        return is_string($configured) && $configured !== '' ? $configured : self::DEFAULT_MODEL;
    }

    public function test_a_computational_prompt_runs_code_on_the_mcp_sandbox(): void
    {
        $chunks = $this->stream(
            'Berechne den SHA-256 Hexdigest der exakten Zeichenkette '.self::PROBE_STRING
            .' und gib nur den Digest aus.'
        );

        $this->assertRunsResolved($this->codeInterpreterStatuses($chunks));

        $this->assertStringContainsString(
            hash('sha256', self::PROBE_STRING),
            $this->text($chunks),
            'The answer does not carry the real digest, so the sandbox result did not reach the model.'
        );
    }

    /**
     * The code and its output belong in the message on this path too - and here
     * it matters more, because the sandbox result reaches the model as a tool
     * message that is thrown away with the request. Without the block in the
     * message, the next turn has no record that anything was computed.
     */
    public function test_the_code_and_its_output_are_written_into_the_message(): void
    {
        $chunks = $this->stream(
            'Berechne den SHA-256 Hexdigest der exakten Zeichenkette '.self::PROBE_STRING
            .' und gib nur den Digest aus.'
        );

        $text = $this->text($chunks);

        $this->assertStringContainsString(
            '```python',
            $text,
            'The message carries no python block, so the user cannot see or re-run what was executed.'
        );

        $this->assertStringContainsString(
            '```output',
            $text,
            'The message carries no output block, so the sandbox result is invisible to the user.'
        );

        $this->assertStringContainsString(
            hash('sha256', self::PROBE_STRING),
            substr($text, 0, (int) strpos($text, '```output') + 200),
            'The output block does not hold the computed digest.'
        );
    }

    public function test_the_run_is_identifiable_in_the_persisted_status_log(): void
    {
        $chunks = $this->stream(
            'Berechne den SHA-256 Hexdigest der exakten Zeichenkette '.self::PROBE_STRING
            .' und gib nur den Digest aus.'
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
            'A reloaded message has to show "Running code..." before "Code executed".'
        );
    }

    /**
     * The sandbox has no way to hand back a file: its working directory is
     * read-only and plt.show() produces nothing, so a printed data URI is the one
     * route out. That convention lives in the tool's argument schema - a model
     * that is not told it writes savefig('plot.png') and the run fails.
     *
     * The base64 is taken out of the output before the model sees it, so this also
     * checks that the model was not handed the bytes.
     */
    public function test_a_plot_is_stored_and_handed_over_as_an_image(): void
    {
        $chunks = $this->stream(
            'Zeichne mit matplotlib den Graphen von y = sin(x) fuer x von 0 bis 2*pi '
            .'und zeige mir das Diagramm.'
        );

        $images = $this->auxiliaries($chunks, 'generated_image');

        $this->assertNotEmpty(
            $images,
            'No generated_image auxiliary was emitted. Either the model did not print a data URI - '
            .'check the code argument description - or the extraction did not run.'
        );

        $this->assertNotSame('', (string) ($images[0]['url'] ?? ''), 'The stored plot has no URL.');

        $text = $this->text($chunks);

        $this->assertStringNotContainsString(
            'iVBORw0KGgo',
            $text,
            'The base64 of the plot reached the message, and therefore the model. It has to be '
            .'extracted in CodeInterpreterTool before the output cap truncates it.'
        );
    }

    /**
     * The regression this suite exists for.
     *
     * Given a script and asked to run it, gemma read it instead, answered
     * "since I could not run the code tool with image output in this environment
     * I interpreted the calculations", and presented statistics it had made up.
     * Two causes, both in the prompt: the image guidance was phrased as a list of
     * things that do NOT work, which compresses to "images are unsupported"; and
     * nothing forbade stating results that no tool run had produced.
     *
     * The seed makes the fabrication detectable - numpy's mean for seed 42 is a
     * fixed number no model can guess.
     */
    public function test_a_script_it_is_asked_to_run_is_run_and_not_narrated(): void
    {
        $chunks = $this->stream(
            "Führe dieses Skript aus und zeige mir die Ergebnisse und das Diagramm:\n\n"
            ."import numpy as np\n"
            ."import matplotlib.pyplot as plt\n"
            ."np.random.seed(42)\n"
            ."data = np.random.normal(100, 15, 1000)\n"
            ."print('Mittelwert:', data.mean())\n"
            ."plt.hist(data, bins=30); plt.title('Verteilung'); plt.show()\n"
        );

        $text = $this->text($chunks);

        $this->assertRunsResolved($this->codeInterpreterStatuses($chunks));

        // numpy's mean for seed 42 is 100.28998083733488. A narrated answer says
        // "about 100" and gets this wrong.
        $this->assertStringContainsString(
            '100.28',
            str_replace(',', '.', $text),
            'The reported mean is not the value the code produces, so it was not read back from a run.'
        );

        $this->assertNotEmpty(
            $this->auxiliaries($chunks, 'generated_image'),
            'The histogram never became an image.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/nicht (direkt )?(mit Bildausgabe )?(ausf[üu]hren|möglich)|kann keine Bilder|'
            .'cannot (show|display|produce) (images|plots)/iu',
            $text,
            'The model claimed the environment cannot produce images. State the image support '
            .'positively in the awareness prompt and the code argument description.'
        );
    }

    public function test_a_question_of_stable_knowledge_runs_no_code(): void
    {
        $chunks = $this->stream('Was ist die Hauptstadt von Frankreich? Antworte in einem Satz.');

        $this->assertEmpty(
            $this->codeInterpreterStatuses($chunks),
            'The model ran code for a question it should answer directly. Tune '
            .'hawki_tools.tools.code_interpreter.awareness.'
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
