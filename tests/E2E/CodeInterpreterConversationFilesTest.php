<?php

declare(strict_types=1);

namespace Tests\E2E;

use App\Http\Middleware\ChatAccess;
use App\Http\Middleware\MandatorySignatureCheck;
use App\Http\Middleware\SessionExpiryChecker;
use App\Models\Attachment;
use App\Models\User;
use App\Services\AI\Tools\CodeInterpreterTool;
use App\Services\AI\Tools\SandboxImages;
use App\Services\Chat\Attachment\AttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * KI-771 with a model in the loop: "put the otter picture on a slide of that
 * deck", one turn later.
 *
 * The picture and the deck are attachments of the previous answer - the sandbox
 * has neither, and cannot fetch anything. The model has to read the file list,
 * name both in the `files` argument, and open the deck rather than rebuild it.
 * Whether it does is prompt work, not plumbing, which is why this is a test with
 * a real model: {@see CodeSandboxConversationFilesTest} proves the machinery
 * works when the names are given, this proves the model gives them.
 *
 * Measured on staging before the file list moved into the awareness prompt:
 * jlu/qwen3.8-27b never used the argument, hunted for the picture with glob()
 * and find(), and told the user it could not find it. That run is the reason
 * this test exists.
 *
 *   docker exec hawki-dev-app php -d memory_limit=1G vendor/bin/phpunit --group e2e --filter CodeInterpreterConversationFiles --testdox
 *   E2E_HAWKI_CODE_INTERPRETER_MODEL=jlu/gemma-4-26b-it ...
 */
#[Group('e2e')]
class CodeInterpreterConversationFilesTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER = 'ki-at-jlu';

    private const DEFAULT_MODEL = 'jlu/qwen3.8-27b';

    private const PPTX = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

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

        config(['system.disable_stream_buffering' => false]);
    }

    private function modelId(): string
    {
        $configured = getenv('E2E_HAWKI_CODE_INTERPRETER_MODEL');

        return is_string($configured) && $configured !== '' ? $configured : self::DEFAULT_MODEL;
    }

    public function test_the_model_asks_for_the_picture_and_the_deck_and_returns_a_deck_with_the_picture_in_it(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $this->actingAs($user);

        // The earlier turn, for real: a deck the sandbox built and a picture
        // stored the way the image tool stores one.
        [$deckUuid, $deckName] = $this->buildDeck();
        $picture = app(AttachmentService::class)->storeGeneratedFile($this->otterSvg(), 'otter.svg', 'private', 'image/svg+xml');
        $this->assertIsArray($picture, 'The picture of the earlier turn could not be stored.');

        $chunks = $this->stream([
            ['role' => 'user', 'content' => ['text' => 'Mach mir ein kurzes Deck über Otter und ein Bild von einem Otter.']],
            ['role' => 'assistant', 'content' => [
                'text' => 'Hier ist das Deck: ['.$deckName.'](sandbox:/tmp/'.$deckName.') - und das Bild ist oben zu sehen.',
                'auxiliaries' => [
                    $this->auxiliary('container_file', $deckUuid, $deckName, self::PPTX),
                    $this->auxiliary('generated_image', $picture['uuid'], $picture['name'], 'image/svg+xml'),
                ],
            ]],
            ['role' => 'user', 'content' => ['text' => 'Setz das Otter-Bild bitte auf eine neue Folie in dieses Deck und gib mir das Deck zurück.']],
        ]);

        $text = $this->text($chunks);
        $files = $this->auxiliaries($chunks, 'container_file');

        // What the model did, in the failure message: without it a red test says
        // only "no deck", and the reason is always in what it tried instead.
        $tried = substr($text, 0, 3000);

        $this->assertNotEmpty($files, "No file came back. The model answered:\n".$tried);

        $decks = array_values(array_filter(
            $files,
            static fn (array $f): bool => str_ends_with(strtolower((string) ($f['name'] ?? '')), '.pptx')
        ));

        $this->assertNotEmpty($decks, "A file came back but no .pptx. The model answered:\n".$tried);

        $deck = end($decks);
        $bytes = app(AttachmentService::class)->retrieve(Attachment::where('uuid', $deck['uuid'])->firstOrFail());

        [$slides, $media] = $this->inspect((string) $bytes);

        $this->assertGreaterThanOrEqual(3, $slides, 'The deck it returned has fewer slides than the one it was given.');
        $this->assertNotEmpty($media, "The returned deck carries no picture at all. The model answered:\n".$tried);

        // The picture is an SVG, and a .pptx embeds bitmaps - hawki_slides
        // rasterises on the way in, so a PNG (or JPEG) has to be in there.
        fwrite(STDOUT, "\n=== KI-771 ===\n".json_encode([
            'model' => $this->model,
            'deck' => $deck['name'] ?? null,
            'slides' => $slides,
            'media' => $media,
            'used_deck_open' => str_contains($text, 'Deck.open'),
            'used_files_argument' => str_contains($text, 'otter.svg'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * The deck of the earlier turn, built through the tool so it is stored the
     * way a real one is.
     *
     * @return array{0: string, 1: string} uuid and file name
     */
    private function buildDeck(): array
    {
        $tool = app(CodeInterpreterTool::class);
        $images = app(SandboxImages::class);

        $tool->configureForRequest(['messages' => [['role' => 'user', 'content' => ['text' => 'Mach ein Deck']]]]);
        $tool->execute(['code' => <<<'PY'
            from hawki_slides import Deck
            deck = Deck(title="Otter", author="HAWKI", lang="de")
            deck.title("Otter", "Ein Steckbrief")
            deck.bullets("Fakten", ["Schwimmt gut", "Frisst Fisch"])
            deck.save("/tmp/Otter.pptx")
            print("ok")
            PY]);

        $produced = $images->produced();
        $deck = end($produced);

        $this->assertIsArray($deck, 'The deck of the earlier turn could not be built - is the sandbox the one with hawki_slides?');
        $this->assertSame('file', $deck['kind'] ?? null);

        // The turn is over: what it produced belongs to that answer now.
        $images->drain();

        return [(string) $deck['uuid'], (string) $deck['name']];
    }

    private function otterSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="240">'
            .'<rect width="400" height="240" fill="#8B5A2B"/>'
            .'<ellipse cx="200" cy="140" rx="110" ry="70" fill="#C8A165"/>'
            .'<circle cx="200" cy="70" r="46" fill="#C8A165"/>'
            .'<circle cx="184" cy="62" r="6" fill="#222"/><circle cx="216" cy="62" r="6" fill="#222"/>'
            .'<ellipse cx="200" cy="82" rx="14" ry="9" fill="#222"/></svg>';
    }

    /**
     * @return array{0: int, 1: array<int,string>} slide count and media entries
     */
    private function inspect(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ki771').'.pptx';
        file_put_contents($path, $bytes);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The delivered deck is not a readable .pptx.');

        $slides = 0;
        $media = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^ppt/slides/slide\d+\.xml$#', $name) === 1) {
                $slides++;
            }
            if (str_starts_with($name, 'ppt/media/')) {
                $media[] = $name;
            }
        }
        $zip->close();
        unlink($path);

        return [$slides, $media];
    }

    /**
     * @return array<string,string>
     */
    private function auxiliary(string $type, string $uuid, string $name, string $mime): array
    {
        return [
            'type' => $type,
            'content' => json_encode([
                'uuid' => $uuid,
                'name' => $name,
                'mime' => $mime,
                'url' => '/req/conv/attachment/view/'.$uuid,
            ]),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $messages
     * @return array<int,array<string,mixed>>
     */
    private function stream(array $messages): array
    {
        $user = User::where('username', 'admin')->firstOrFail();

        $raw = '';
        ob_start(function (string $chunk) use (&$raw): string {
            $raw .= $chunk;

            return '';
        });

        try {
            $this->actingAs($user)
                ->withoutMiddleware([SessionExpiryChecker::class, MandatorySignatureCheck::class, ChatAccess::class])
                ->postJson('/req/streamAI', [
                    'payload' => ['model' => $this->model, 'stream' => true, 'messages' => $messages],
                    'broadcast' => false,
                ]);
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
}
