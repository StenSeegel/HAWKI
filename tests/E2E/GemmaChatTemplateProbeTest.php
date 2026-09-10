<?php

declare(strict_types=1);

namespace Tests\E2E;

use App\Services\AI\AiService;
use App\Services\AI\Providers\AbstractRequest;
use App\Services\AI\Providers\OpenAiHawkiTools\OpenAiHawkiToolsRequestConverter;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiRequest;
use App\Services\AI\Value\AiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Measures the two gemma adaptations against the model as the gateway serves it:
 * the tool prompt placement (config/hawki_tools.php awareness_placement) and the
 * chat template token stripping in ToolArgumentSanitizer.
 *
 * Result on 2026-09-10, after the gateway deployed gemma's new chat template:
 * both placements 15/15 on questions that should search and 0/6 on questions that
 * should not, with and without prior turns; a system-only instruction obeyed
 * verbatim; and no leaked template token in any tool argument. The placement
 * default went back to 'system' on the strength of these numbers.
 *
 * Not an assertion suite - it prints a table. Run it with:
 *   docker exec hawki-dev-app vendor/bin/phpunit --group e2e-probe
 *
 * E2E_PROBE_MODEL picks another model, E2E_PROBE_RUNS repeats every question.
 */
#[Group('e2e-probe')]
class GemmaChatTemplateProbeTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER = 'ki-at-jlu';

    private const SHOULD_SEARCH = [
        'Wie ist das Wetter heute in Gießen?',
        'Was kostet aktuell ein Deutschlandticket?',
        'Wer ist derzeit Präsident der Justus-Liebig-Universität Gießen?',
        'Was sind die neuesten Nachrichten zur Energiepolitik in Deutschland?',
        'Suche im Netz, wann das Wintersemester 2026/27 an der JLU beginnt.',
    ];

    private const SHOULD_NOT_SEARCH = [
        'Was ist die Hauptstadt von Frankreich? Antworte in einem Satz.',
        'Erkläre in zwei Sätzen, was eine Primzahl ist.',
    ];

    /**
     * A few turns of ordinary chat in front of the question. With the
     * instruction in the system prompt it is now several turns away from the
     * question - the case the 'user' placement was introduced for.
     */
    private const HISTORY = [
        ['role' => 'user', 'content' => ['text' => 'Hallo, kannst du mir bei einem Text helfen?']],
        ['role' => 'assistant', 'content' => ['text' => 'Natürlich, worum geht es?']],
        ['role' => 'user', 'content' => ['text' => 'Schreibe einen Satz über Herbstwetter.']],
        ['role' => 'assistant', 'content' => ['text' => 'Der Herbst legt einen kühlen Nebel über die Felder und färbt die Blätter golden.']],
        ['role' => 'user', 'content' => ['text' => 'Danke, das klingt gut.']],
        ['role' => 'assistant', 'content' => ['text' => 'Gern! Sag Bescheid, wenn du noch etwas brauchst.']],
    ];

    private AiModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $modelId = getenv('E2E_PROBE_MODEL') ?: 'jlu/gemma-4-26b-it';

        try {
            LiveAiProvider::seed(self::PROVIDER, $modelId);
        } catch (SkipLiveProvider $e) {
            $this->markTestSkipped($e->getMessage());
        }

        $this->model = app(AiService::class)->getModelOrFail($modelId);

        // Without this the stream closes the test's output buffer and the
        // streamed chunks are printed instead of captured.
        config(['system.disable_stream_buffering' => false]);
    }

    public function test_probe(): void
    {
        $runs = (int) (getenv('E2E_PROBE_RUNS') ?: 1);
        $rows = [];

        foreach (['user', 'system'] as $placement) {
            foreach ([true, false] as $shouldSearch) {
                $questions = $shouldSearch ? self::SHOULD_SEARCH : self::SHOULD_NOT_SEARCH;

                foreach ($questions as $question) {
                    for ($run = 0; $run < $runs; $run++) {
                        $rows[] = $this->ask($placement, $question, $shouldSearch);
                    }
                }
            }
        }

        $this->report($rows);
        $this->assertNotEmpty($rows);
    }


    public function test_probe_with_history(): void
    {
        $runs = (int) (getenv('E2E_PROBE_RUNS') ?: 1);
        $rows = [];

        foreach (['user', 'system'] as $placement) {
            foreach ([true, false] as $shouldSearch) {
                foreach ($shouldSearch ? self::SHOULD_SEARCH : self::SHOULD_NOT_SEARCH as $question) {
                    for ($run = 0; $run < $runs; $run++) {
                        $rows[] = $this->ask($placement, $question, $shouldSearch, true);
                    }
                }
            }
        }

        $this->report($rows);
        $this->assertNotEmpty($rows);
    }

    /**
     * The whole loop through HAWKI's tool runtime, streamed, the way a chat turn
     * runs it - this is where the leaked template token was observed, and where a
     * tool result has to travel back into gemma's template.
     */
    public function test_streamed_loop(): void
    {
        $placement = getenv('E2E_PROBE_PLACEMENT') ?: 'system';
        config(['hawki_tools.awareness_placement' => $placement]);

        $user = \App\Models\User::where('username', 'admin')->firstOrFail();

        $raw = '';
        ob_start(function (string $chunk) use (&$raw): string {
            $raw .= $chunk;

            return '';
        });

        try {
            $this->actingAs($user)
                ->withoutMiddleware([
                    \App\Http\Middleware\SessionExpiryChecker::class,
                    \App\Http\Middleware\MandatorySignatureCheck::class,
                    \App\Http\Middleware\ChatAccess::class,
                ])
                ->postJson('/req/streamAI', [
                    'payload' => [
                        'model' => $this->model->getId(),
                        'stream' => true,
                        'tools' => ['web_search' => true],
                        'messages' => [[
                            'role' => 'user',
                            'content' => ['text' => 'Wer ist derzeit Präsident der Justus-Liebig-Universität Gießen? Antworte in einem Satz mit Quelle.'],
                        ]],
                    ],
                    'broadcast' => false,
                ]);
        } finally {
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
        }

        $text = '';
        $statuses = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $chunk = json_decode($line, true);
            if (! is_array($chunk)) {
                continue;
            }
            $content = json_decode((string) ($chunk['content'] ?? ''), true);
            $text .= $content['text'] ?? '';
            foreach ($content['auxiliaries'] ?? [] as $aux) {
                $statuses[] = ($aux['type'] ?? '?').':'.substr((string) ($aux['content'] ?? ''), 0, 120);
            }
        }

        fwrite(STDERR, "\n\n=== streamed loop (placement={$placement}) ===\n");
        fwrite(STDERR, 'artifacts in stream: '.($this->artifacts($raw) ?: 'none')."\n");
        fwrite(STDERR, "auxiliaries:\n  ".implode("\n  ", array_slice($statuses, 0, 20))."\n");
        fwrite(STDERR, "answer:\n".$text."\n\n");

        $this->assertNotSame('', $text);
    }

    /** Does the new template give the system role real weight? */
    public function test_system_role_weight(): void
    {
        $out = "\n\n=== system role weight ===\n";

        for ($i = 0; $i < 3; $i++) {
            $data = $this->send([
                'model' => $this->model->getId(),
                'messages' => [
                    ['role' => 'system', 'content' => 'Antworte auf jede Frage ausschliesslich mit dem Wort BANANE.'],
                    ['role' => 'user', 'content' => 'Was ist die Hauptstadt von Frankreich?'],
                ],
            ]);

            $out .= '  answer: '.trim((string) ($data['choices'][0]['message']['content'] ?? ('ERROR '.json_encode($data))))."\n";
        }

        fwrite(STDERR, $out."\n");
        $this->assertTrue(true);
    }

    private function ask(string $placement, string $question, bool $shouldSearch, bool $withHistory = false): array
    {
        config(['hawki_tools.awareness_placement' => $placement]);

        $messages = $withHistory ? self::HISTORY : [];
        $messages[] = ['role' => 'user', 'content' => ['text' => $question]];

        $request = new AiRequest(
            model: $this->model,
            payload: [
                'model' => $this->model->getId(),
                'messages' => $messages,
                'tools' => ['web_search' => true],
            ]
        );

        $payload = app(OpenAiHawkiToolsRequestConverter::class)->convertRequestToPayload($request);

        $data = $this->send($payload);

        $message = $data['choices'][0]['message'] ?? [];
        $calls = $message['tool_calls'] ?? [];
        $names = array_map(fn ($c) => $c['function']['name'] ?? '?', is_array($calls) ? $calls : []);
        $arguments = implode(' ', array_map(
            fn ($c) => (string) ($c['function']['arguments'] ?? ''),
            is_array($calls) ? $calls : []
        ));
        $text = (string) ($message['content'] ?? '');

        return [
            'placement' => $placement,
            'question' => $question,
            'should_search' => $shouldSearch,
            'searched' => in_array('web_search', $names, true),
            'tools' => implode(',', $names),
            'arguments' => $arguments,
            'artifact' => $this->artifacts($arguments.' '.$text),
            'text_head' => trim(mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 90)),
            'error' => $data['__error'] ?? ($data['error']['message'] ?? null),
        ];
    }

    /**
     * Chat template tokens that must not reach HAWKI at all. A leaked '<|"|>' in
     * a tool argument is what ToolArgumentSanitizer was written for, and a
     * '```tool_code' fence means the model wrote the call as text instead of
     * emitting a native tool_calls entry.
     */
    private function artifacts(string $haystack): string
    {
        $found = [];
        foreach (['<|', '|>', '<start_of_turn>', '<end_of_turn>', '```tool_code'] as $token) {
            if (str_contains($haystack, $token)) {
                $found[] = $token;
            }
        }

        return implode(' ', array_unique($found));
    }

    private function send(array $payload): array
    {
        $payload['stream'] = false;

        $request = new class($payload) extends AbstractRequest
        {
            public array $raw = [];

            public function __construct(private array $payload) {}

            public function run(AiModel $model): void
            {
                $this->executeNonStreamingRequest(
                    model: $model,
                    payload: $this->payload,
                    dataToResponse: function (array $data): AiResponse {
                        $this->raw = $data;

                        return new AiResponse(content: ['text' => '']);
                    }
                );
            }
        };

        $request->run($this->model);

        return $request->raw !== [] ? $request->raw : ['__error' => 'no data'];
    }

    private function report(array $rows): void
    {
        $out = "\n\n=== gemma chat template probe: ".$this->model->getId()." ===\n";

        foreach (['user', 'system'] as $placement) {
            $set = array_filter($rows, fn ($r) => $r['placement'] === $placement);
            $wanted = array_filter($set, fn ($r) => $r['should_search']);
            $unwanted = array_filter($set, fn ($r) => ! $r['should_search']);

            $hit = count(array_filter($wanted, fn ($r) => $r['searched']));
            $false = count(array_filter($unwanted, fn ($r) => $r['searched']));

            $out .= sprintf(
                "placement=%-6s  searched when it should: %d/%d   searched when it should not: %d/%d\n",
                $placement, $hit, count($wanted), $false, count($unwanted)
            );
        }

        $out .= "\n";

        foreach ($rows as $r) {
            $out .= sprintf(
                "[%-6s] %-8s %-55s tools=%-30s %s%s\n",
                $r['placement'],
                $r['should_search'] ? 'want' : 'no-want',
                mb_substr($r['question'], 0, 55),
                $r['tools'] === '' ? '-' : $r['tools'],
                $r['artifact'] !== '' ? 'ARTIFACT['.$r['artifact'].'] ' : '',
                $r['error'] ? 'ERROR: '.$r['error'] : ''
            );

            if ($r['arguments'] !== '') {
                $out .= '            args: '.mb_substr($r['arguments'], 0, 200)."\n";
            } elseif ($r['text_head'] !== '') {
                $out .= '            text: '.$r['text_head']."\n";
            }
        }

        fwrite(STDERR, $out."\n");
    }
}
