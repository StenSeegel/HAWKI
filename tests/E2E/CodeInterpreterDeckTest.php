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
 * A user asks for a PowerPoint and gets one - through a real model, HAWKI's own
 * code interpreter, and a sandbox that carries node, pptxgenjs and LibreOffice.
 *
 * This is the whole chain CodeSandboxDeckProbeTest measures piece by piece,
 * driven the way a user drives it: one German sentence, and at the end a
 * 'container_file' auxiliary naming a .pptx plus a link in the answer the
 * frontend can resolve. Everything in between - which library the model reaches
 * for, whether it renders the slides back, whether the note in the tool result
 * steers it to the sandbox: link - is the model's business and shows up in the
 * failure message when it goes wrong.
 *
 * Needs a sandbox with the document toolchain behind code-exec-mcp. Against the
 * gateway's old image the first assertion fails with the model's excuse, which
 * is the point: run it after every sandbox image change. See LiveAiProvider for
 * the setup, and CodeInterpreterHawkiToolTest for the model override:
 *
 *   docker exec hawki-dev-app php -d memory_limit=1G vendor/bin/phpunit --group e2e --filter CodeInterpreterDeck
 *   E2E_HAWKI_CODE_INTERPRETER_MODEL=jlu/gemma-4-26b-it ... (the model that first failed at this)
 */
#[Group('e2e')]
class CodeInterpreterDeckTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER = 'ki-at-jlu';

    private const DEFAULT_MODEL = 'jlu/qwen3.8-27b';

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

        $overridden = app(\App\Services\AI\AiService::class)
            ->getModelOrFail($this->model)
            ->getProvider()
            ->getConfig()
            ->isHawkiToolOverridden('code_interpreter');

        if (! $overridden) {
            $this->markTestSkipped("The code_interpreter override is off for provider '".self::PROVIDER."'.");
        }

        config(['system.disable_stream_buffering' => false]);
    }

    private function modelId(): string
    {
        $configured = getenv('E2E_HAWKI_CODE_INTERPRETER_MODEL');

        return is_string($configured) && $configured !== '' ? $configured : self::DEFAULT_MODEL;
    }

    public function test_a_request_for_a_deck_ends_in_a_downloadable_pptx(): void
    {
        $chunks = $this->stream(
            'Erstelle eine PPTX zum Thema Anthropomorphisierung von LLM mit max. 3 Slides.'
        );

        $text = $this->text($chunks);
        $statuses = $this->codeInterpreterStatuses($chunks);
        $files = $this->auxiliaries($chunks, 'container_file');

        $this->assertNotEmpty(
            $statuses,
            "The model never ran code. It answered:\n".mb_substr($text, 0, 1500)
        );

        $this->assertNotEmpty(
            $files,
            "Code ran (".count($statuses)." status steps) but no container_file was announced. "
            ."Either the run failed - read the ```output block - or the deck was not printed as a file data URI.\n"
            .mb_substr($text, 0, 3000)
        );

        $deck = $files[0];
        $this->assertStringEndsWith('.pptx', strtolower((string) ($deck['filename'] ?? '')), 'The file is not a .pptx: '.json_encode($deck));
        $this->assertStringContainsString('presentationml', (string) ($deck['mime'] ?? ''));
        $this->assertNotSame('', (string) ($deck['url'] ?? ''), 'The stored deck has no URL.');
        $this->assertArrayNotHasKey('inline', $deck);

        // The answer has to hand the user the link the frontend resolves.
        $this->assertMatchesRegularExpression(
            '/\]\(sandbox:[^)]*'.preg_quote((string) $deck['filename'], '/').'\)/',
            $text,
            'The answer does not link the deck as [name](sandbox:/tmp/name). Tail of the answer:'."\n"
            .mb_substr($text, -1200)
        );

        // And it did not paste the bytes into the chat. The literal "base64," is
        // expected - HAWKI writes the model's own code into the message, and that
        // code encodes the file; a payload is what must not be there.
        $this->assertDoesNotMatchRegularExpression(
            '/base64,[A-Za-z0-9+\/=]{200,}/',
            $text,
            'A base64 payload reached the message text.'
        );

        fwrite(STDOUT, "\n=== DECK ===\n".json_encode([
            'model' => $this->model,
            'code_runs' => count(array_filter($statuses, static fn (string $s) => $s === 'completed')),
            'file' => $deck,
            'slides_previewed' => count($this->auxiliaries($chunks, 'generated_image')),
            // The code HAWKI writes into the message shows which route the model took.
            'library' => str_contains($text, 'hawki_slides') ? 'hawki_slides'
                : (str_contains($text, 'hawki-slides') ? 'hawki-slides (js)'
                : (str_contains($text, 'pptxgenjs') ? 'pptxgenjs (raw)'
                : (str_contains($text, 'from pptx') || str_contains($text, 'import pptx') ? 'python-pptx' : 'unknown'))),
            'first_try_clean' => ! preg_match('/```output\n(?:Error|Traceback|\[stderr\])/', $text),
            // What went wrong on the first try, so the class of mistake is on record.
            'first_error' => preg_match('/```output\n((?:Error|Traceback|\[stderr\])[\s\S]{0,400})/', $text, $m)
                ? preg_replace('/\s+/', ' ', $m[1]) : null,
            'answer_chars' => mb_strlen($text),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * The turn after a deck was built. The stored assistant message carries the
     * code interpreter's echo - python block, output block with the delivery
     * note, the sandbox: link - and gemma once answered the follow-up by copying
     * all of that from its context instead of calling the tool: a link with no
     * file behind it. CodeInterpreterHistory sends the echo back as the tool
     * exchange it was; this is the check that the gateway accepts that history
     * and the model runs the tool again.
     */
    public function test_a_follow_up_after_an_earlier_deck_runs_the_tool_again(): void
    {
        $earlierCode = "from hawki_slides import Deck\nimport subprocess, glob, base64\n"
            ."deck = Deck(title=\"Anthropomorphisierung von LLMs\", author=\"HAWKI\", lang=\"de\")\n"
            ."deck.title(\"Anthropomorphisierung von LLMs\", \"Warum wir Maschinen vermenschlichen\")\n"
            ."deck.bullets(\"Was ist Anthropomorphisierung?\", [\"Zuschreibung menschlicher Eigenschaften\", \"Sprache als Auslöser\"])\n"
            ."deck.closing(\"Danke · Fragen?\")\n"
            ."deck.save(\"/tmp/Anthropomorphisierung von LLMs.pptx\")";

        $earlierOutput = "[image 1 was produced and is shown to the user]\n"
            .'[file 1 "Anthropomorphisierung von LLMs.pptx" was produced and is offered to the user as a download. '
            .'Link it in your answer exactly as [Anthropomorphisierung von LLMs.pptx](sandbox:/tmp/Anthropomorphisierung%20von%20LLMs.pptx) - HAWKI points that link at the stored file.]';

        // The inline plots HAWKI writes under the echo, as the stored message has them.
        $earlierTurn = "\n\n```python\n".$earlierCode."\n```\n\n```output\n".$earlierOutput."\n```\n\n"
            ."![Plot](https://app.hawki.dev/req/conv/attachment/view/725c5321-9a58-458e-91e3-54aeccc37291)\n\n"
            ."![Plot](https://app.hawki.dev/req/conv/attachment/view/d5ee5ac2-4752-4e1c-b026-55fbea120cf6)\n\n"
            ."![Plot](https://app.hawki.dev/req/conv/attachment/view/8b5349b8-0b76-4606-a1a8-3689425f4019)\n\n"
            ."Die Präsentation ist fertig: [Anthropomorphisierung von LLMs.pptx](sandbox:/tmp/Anthropomorphisierung%20von%20LLMs.pptx)";

        $chunks = $this->stream([
            ['role' => 'user', 'content' => ['text' => 'Erstelle eine PPTX zum Thema Anthropomorphisierung von LLM mit max. 3 Slides.']],
            ['role' => 'assistant', 'content' => ['text' => $earlierTurn]],
            ['role' => 'user', 'content' => ['text' => 'Bitte noch einmal, aber in einem neutralen Design ohne JLU-Vorlage.']],
        ]);

        $text = $this->text($chunks);
        $statuses = $this->codeInterpreterStatuses($chunks);
        $files = $this->auxiliaries($chunks, 'container_file');

        $this->assertNotEmpty(
            $statuses,
            "The model answered the follow-up without running code - the earlier turn was copied, not redone:\n".mb_substr($text, 0, 2000)
        );

        $this->assertNotEmpty(
            $files,
            "Code ran but no new deck was announced:\n".mb_substr($text, 0, 3000)
        );

        // Every sandbox: link in the answer has to name a file THIS turn produced.
        preg_match_all('/\]\(sandbox:\/tmp\/([^)]+)\)/', $text, $links);
        $produced = array_map(static fn (array $f) => rawurlencode((string) $f['filename']), $files);
        foreach ($links[1] as $linked) {
            $this->assertContains(
                str_replace('%2F', '/', rawurlencode(rawurldecode($linked))),
                $produced,
                "The answer links '".rawurldecode($linked)."', which this turn did not produce. Produced: ".implode(', ', array_column($files, 'filename'))
            );
        }

        // No chat template delimiter as text (gemma once ended with "<turn|>").
        $this->assertDoesNotMatchRegularExpression('/<\|?(?:turn|end_of_turn|im_end)\|?>/', $text, 'A template marker reached the answer.');

        // Every attachment the answer points at exists: the model once wrote
        // ![Plot](.../attachment/view/<uuid>) lines with uuids it made up.
        $known = array_column(array_merge($files, $this->auxiliaries($chunks, 'generated_image')), 'uuid');
        preg_match_all('/\/attachment\/view\/([0-9a-f-]{36})/', $text, $referenced);
        foreach (array_unique($referenced[1]) as $uuid) {
            $this->assertContains($uuid, $known, "The answer links attachment {$uuid}, which this turn did not produce.");
        }

        fwrite(STDOUT, "\n=== FOLLOW-UP ===\n".json_encode([
            'model' => $this->model,
            'code_runs' => count(array_filter($statuses, static fn (string $s) => $s === 'completed')),
            'files' => array_column($files, 'filename'),
            'purple_requested' => str_contains($text, 'style="purple"') || str_contains($text, "style='purple'"),
            'links' => $links[1],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function stream(string|array $prompt): array
    {
        $user = User::where('username', 'admin')->firstOrFail();

        $messages = is_string($prompt)
            ? [['role' => 'user', 'content' => ['text' => $prompt]]]
            : $prompt;

        $payload = [
            'payload' => [
                'model' => $this->model,
                'stream' => true,
                'messages' => $messages,
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
                ->withoutMiddleware([SessionExpiryChecker::class, MandatorySignatureCheck::class, ChatAccess::class])
                ->postJson('/req/streamAI', $payload);
        } finally {
            ob_end_flush();
        }

        $chunks = [];
        foreach (explode("\n", $raw) as $line) {
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded)) {
                $chunks[] = $decoded;
            }
        }

        $this->assertNotEmpty($chunks, 'The stream produced nothing readable. Raw: '.substr($raw, 0, 2000));

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
}
