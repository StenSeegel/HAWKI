<?php

declare(strict_types=1);

namespace Tests\E2E;

use App\Models\Attachment;
use App\Models\User;
use App\Services\AI\Tools\CodeInterpreterTool;
use App\Services\AI\Tools\SandboxImages;
use App\Services\Chat\Attachment\AttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * KI-771, end to end against a real sandbox: a picture produced in an earlier
 * turn lands on a slide of a deck produced in an earlier turn.
 *
 * Neither reached the sandbox before. The code interpreter placed the newest
 * user message's uploads in /work and nothing else, so an image the image tool
 * generated - or a deck the sandbox itself had built - existed for the model
 * only as a note in the history. This exercises the way through: the tool
 * definition lists the files of the conversation, the model names the ones it
 * needs, HAWKI reads those out of storage into /work/<name>, and hawki_slides
 * opens the deck and puts the picture on a new slide.
 *
 * It talks to a code-exec-mcp with the document toolchain. Point it at one:
 *
 *   HAWKI_CODE_EXEC_MCP_URL=http://localhost:3012/mcp \
 *   HAWKI_CODE_EXEC_MCP_REQUIRES_SESSION=true \
 *   php -d memory_limit=1G vendor/bin/phpunit --group e2e \
 *       --filter CodeSandboxConversationFiles --testdox
 *
 * Without that URL the test skips: there is no sandbox to ask.
 */
#[Group('e2e')]
class CodeSandboxConversationFilesTest extends TestCase
{
    use RefreshDatabase;

    private const PPTX = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    /** @var array<string,string> uuid => bytes, the storage this conversation's files live in */
    private array $storage = [];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $url = (string) env('HAWKI_CODE_EXEC_MCP_URL', '');
        if ($url === '' || str_contains($url, 'api.hrz.uni-giessen.de')) {
            $this->markTestSkipped('No directly reachable code-exec-mcp - set HAWKI_CODE_EXEC_MCP_URL.');
        }

        config([
            'hawki_tools.mcp_servers.code-exec-mcp' => [
                'url' => $url,
                'api_key_provider' => null,
                'gateway_server' => null,
                'requires_session' => (bool) env('HAWKI_CODE_EXEC_MCP_REQUIRES_SESSION', true),
                'timeout' => 180,
            ],
            'hawki_tools.bindings.code_interpreter' => [
                'server' => 'code-exec-mcp',
                'tools' => ['run' => 'code_exec'],
            ],
        ]);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        // Storage is faked, the sandbox is not: what matters here is that the
        // bytes of an attachment of this conversation arrive at /work/<name>.
        $attachments = $this->createMock(AttachmentService::class);
        $attachments->method('retrieve')->willReturnCallback(
            fn (Attachment $a) => $this->storage[(string) $a->uuid] ?? ''
        );
        $attachments->method('storeGeneratedFile')->willReturnCallback(
            function (string $bytes, string $filename): array {
                $uuid = 'stored-'.count($this->storage);
                // The real service writes the row too, and that row is what the
                // file of a later turn is found by.
                $this->file($uuid, $filename, self::PPTX, 'document', $bytes);

                return ['uuid' => $uuid, 'url' => 'https://hawki.test/view/'.$uuid, 'mime' => self::PPTX, 'name' => $filename];
            }
        );
        $this->app->instance(AttachmentService::class, $attachments);
    }

    private function file(string $uuid, string $name, string $mime, string $type, string $bytes): void
    {
        Attachment::create([
            'uuid' => $uuid, 'name' => $name, 'category' => 'private', 'type' => $type, 'mime' => $mime, 'user_id' => $this->user->id,
        ]);
        $this->storage[$uuid] = $bytes;
    }

    public function test_a_picture_and_a_deck_from_earlier_turns_meet_on_a_new_slide(): void
    {
        $tool = app(CodeInterpreterTool::class);

        // Turn 1: the sandbox builds the deck. Nothing is named, nothing is in
        // /work - this is the state the conversation starts from.
        $built = $tool->execute(['code' => <<<'PY'
            from hawki_slides import Deck
            deck = Deck(title="Otter", author="HAWKI", lang="de")
            deck.title("Otter", "Ein Test")
            deck.bullets("Fakten", ["Schwimmt", "Frisst Fisch"])
            deck.save("/tmp/Otter.pptx")
            print("built")
            PY]);

        $this->assertStringContainsString('built', $built);
        $this->assertStringContainsString('"Otter.pptx" was produced', $built, 'The deck has to be delivered.');
        $this->assertStringContainsString('files argument: Otter.pptx', $built, 'and named as /work/Otter.pptx for the next call.');

        $deck = app(SandboxImages::class)->produced();
        $deckUuid = (string) end($deck)['uuid'];
        $this->assertNotSame('', $this->storage[$deckUuid] ?? '', 'The stored deck is what the next turn reopens.');

        // The turn is over: the deck is an attachment of the answer, and an SVG
        // the image tool generated is an attachment of the same answer.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="200">'
            .'<rect width="400" height="200" fill="#156082"/><circle cx="200" cy="100" r="60" fill="white"/></svg>';
        $this->file('otter-uuid', 'otter.svg', 'image/svg+xml', 'image', $svg);

        $aux = static fn (string $type, string $uuid, string $name, string $mime): array => [
            'type' => $type,
            'content' => json_encode(['uuid' => $uuid, 'name' => $name, 'mime' => $mime, 'url' => 'https://hawki.test/view/'.$uuid]),
        ];

        $tool->configureForRequest(['messages' => [
            ['role' => 'user', 'content' => ['text' => 'Mach ein Deck und ein Bild']],
            ['role' => 'assistant', 'content' => [
                'text' => 'Hier',
                'auxiliaries' => [
                    $aux('container_file', $deckUuid, 'Otter.pptx', self::PPTX),
                    $aux('generated_image', 'otter-uuid', 'otter.svg', 'image/svg+xml'),
                ],
            ]],
            ['role' => 'user', 'content' => ['text' => 'Setz das Bild auf eine neue Folie']],
        ]]);

        // Turn 2: both files are in the definition the model reads.
        $listed = $tool->getDefinition()['function']['parameters']['properties']['files']['description'];
        $this->assertStringContainsString('/work/Otter.pptx', $listed);
        $this->assertStringContainsString('/work/otter.svg', $listed);

        // ... and the model names them.
        $output = $tool->execute([
            'files' => ['Otter.pptx', 'otter.svg'],
            'code' => <<<'PY'
                import os
                from hawki_slides import Deck
                print("in /work:", sorted(n for n in os.listdir("/work") if n != "code.py"))
                deck = Deck.open("/work/Otter.pptx")
                print("reopened with", deck.slide_count, "slides")
                deck.image("Der Otter", "/work/otter.svg", caption="Generiert mit HAWKI")
                deck.closing("Danke")
                deck.save("/tmp/Otter.pptx")
                print("now", len(deck.prs.slides), "slides")
                PY,
        ]);

        $this->assertStringContainsString("in /work: ['Otter.pptx', 'otter.svg']", $output, 'Both named files have to be in /work.');
        $this->assertStringContainsString('reopened with 2 slides', $output, 'Deck.open() keeps the slides of the earlier deck.');
        $this->assertStringContainsString('now 4 slides', $output);
        $this->assertStringContainsString('"Otter.pptx" was produced', $output, 'The continued deck is delivered again.');

        // Its /work name is not the one the first deck has - two files, two
        // names, so a later call can name either.
        $this->assertMatchesRegularExpression('/files argument: [\w-]+_Otter\.pptx/', $output);

        // The deck that came back has the picture in it - an SVG rasterised on
        // the way, because a .pptx embeds bitmaps only.
        $produced = app(SandboxImages::class)->produced();
        $bytes = $this->storage[(string) end($produced)['uuid']] ?? '';
        $this->assertNotSame('', $bytes);

        $path = tempnam(sys_get_temp_dir(), 'deck').'.pptx';
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The delivered deck has to be a readable .pptx.');
        $media = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, 'ppt/media/')) {
                $media[] = $name;
            }
        }
        $slides = count(array_filter(array_map(
            fn (int $i): string => (string) $zip->getNameIndex($i),
            range(0, $zip->numFiles - 1)
        ), static fn (string $n): bool => preg_match('#^ppt/slides/slide\d+\.xml$#', $n) === 1));
        $zip->close();
        unlink($path);

        $this->assertSame(4, $slides);
        $this->assertNotEmpty(
            array_filter($media, static fn (string $n): bool => str_ends_with($n, '.png')),
            'The SVG has to reach the deck as a PNG: ppt/media holds '.implode(', ', $media)
        );
    }

    public function test_a_file_that_was_not_named_is_absent_and_the_result_says_why(): void
    {
        $this->file('csv-uuid', 'daten.csv', 'text/csv', 'document', "a,b\n1,2\n");

        $tool = app(CodeInterpreterTool::class);
        $tool->configureForRequest(['messages' => [
            ['role' => 'user', 'content' => ['text' => 'analysiere das', 'attachments' => ['csv-uuid']]],
            ['role' => 'assistant', 'content' => ['text' => 'ok']],
            ['role' => 'user', 'content' => ['text' => 'und jetzt noch einmal']],
        ]]);

        $output = $tool->execute(['code' => 'print(open("/work/daten.csv").read())']);

        $this->assertStringContainsString('FileNotFoundError', $output, 'An upload of an earlier turn does not come along by itself.');
        $this->assertStringContainsString('listed in the files argument', $output, 'and the model is told how to get it.');
        $this->assertStringContainsString('daten.csv', $output);

        $named = $tool->execute(['code' => 'print(open("/work/daten.csv").read())', 'files' => ['daten.csv']]);
        $this->assertStringContainsString('1,2', $named);
    }
}
