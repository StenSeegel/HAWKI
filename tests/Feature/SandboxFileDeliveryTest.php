<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\Providers\OpenAiHawkiTools\Request\OpenAiHawkiToolsNonStreamingRequest;
use App\Services\AI\Providers\OpenAiHawkiTools\Request\OpenAiHawkiToolsStreamingRequest;
use App\Services\AI\Tools\CodeInterpreterTool;
use App\Services\AI\Tools\SandboxImages;
use App\Services\AI\Tools\ToolCallRunner;
use App\Services\AI\Value\AiResponse;
use App\Services\Chat\Attachment\AttachmentService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A file the code sandbox built has to reach the user as a download.
 *
 * It did not: the only way out of the sandbox is stdout, a nine-slide .pptx is
 * 220,000 characters of base64, and CodeInterpreterTool caps what the model gets
 * at 8000 - so the deck arrived as truncated garbage in the model's context and
 * nowhere else. The extraction that already lifts plots out of the output now
 * lifts documents out too, stores them under their name, and announces them as
 * 'container_file' - the auxiliary the native code interpreter's files already
 * arrive as, so the frontend's download link needs no second renderer.
 */
class SandboxFileDeliveryTest extends TestCase
{
    private const PPTX_MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    /** A 1x1 PNG, for the case where a run prints a slide preview next to the deck. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /**
     * What the stubbed attachment service was asked to store.
     *
     * @var array<int,array{bytes: string, filename: string, mime: ?string}>
     */
    private array $stored = [];

    protected function setUp(): void
    {
        parent::setUp();

        $attachments = $this->createMock(AttachmentService::class);

        $attachments->method('storeGeneratedFile')->willReturnCallback(
            function (string $bytes, string $filename, string $category, ?string $mimeHint = null) {
                $this->stored[] = ['bytes' => $bytes, 'filename' => $filename, 'mime' => $mimeHint];

                return [
                    'uuid' => 'file-uuid-'.count($this->stored),
                    'url' => 'https://hawki.test/files/'.$filename,
                    'mime' => (string) $mimeHint,
                    'name' => $filename,
                ];
            }
        );

        $attachments->method('storeFromBase64')->willReturnCallback(
            static fn (string $data, string $category, string $filename) => [
                'url' => 'https://hawki.test/files/'.$filename,
                'uuid' => 'uuid-'.$filename,
                'mime' => 'image/png',
                'name' => $filename,
            ]
        );

        $this->app->instance(SandboxImages::class, new SandboxImages($attachments));
    }

    /**
     * A ZIP of the size a real deck has. A .pptx IS a ZIP, and the extraction
     * does not look inside; what matters is that 200,000 characters of base64
     * go in and a one-line note comes out.
     */
    private function deckBytes(int $kilobytes = 160): string
    {
        return "PK\x03\x04".random_bytes($kilobytes * 1024);
    }

    private function deckUri(string $bytes, ?string $name = 'deck.pptx'): string
    {
        return 'data:'.self::PPTX_MIME.($name === null ? '' : ';name='.$name).';base64,'.base64_encode($bytes);
    }

    public function test_a_printed_deck_is_stored_under_its_name_and_replaced_by_a_note(): void
    {
        $bytes = $this->deckBytes();
        $images = app(SandboxImages::class);

        $cleaned = $images->extractFromText("building...\n".$this->deckUri($bytes)."\ndone");

        $this->assertCount(1, $this->stored);
        $this->assertSame($bytes, $this->stored[0]['bytes'], 'The stored bytes are not what the program printed.');
        $this->assertSame('deck.pptx', $this->stored[0]['filename']);
        $this->assertSame(self::PPTX_MIME, $this->stored[0]['mime']);

        // The model gets a note it can act on, never the base64.
        $this->assertStringNotContainsString('base64,', $cleaned);
        $this->assertLessThan(500, strlen($cleaned), 'The note handed to the model is not short: '.strlen($cleaned).' characters.');
        $this->assertStringContainsString('"deck.pptx" was produced', $cleaned);
        $this->assertStringContainsString('[deck.pptx](sandbox:/tmp/deck.pptx)', $cleaned, 'The note does not tell the model the link the frontend resolves.');
        $this->assertStringStartsWith('building...', $cleaned);
        $this->assertStringEndsWith('done', $cleaned);

        // And the file is collected in the shape a container_file auxiliary is built from.
        $files = $images->drain();
        $this->assertCount(1, $files);
        $this->assertSame('file', $files[0]['kind']);
        $this->assertSame('deck.pptx', $files[0]['filename']);
        $this->assertSame('deck.pptx', $files[0]['name']);
        $this->assertSame('https://hawki.test/files/deck.pptx', $files[0]['url']);
        $this->assertSame('file-uuid-1', $files[0]['uuid']);
        $this->assertSame(self::PPTX_MIME, $files[0]['mime']);
    }

    /**
     * A bare data URI has no filename. The MIME type still says what it is, and
     * the download has to open in PowerPoint, so the extension comes from there.
     */
    public function test_a_deck_without_a_name_gets_one_with_the_right_extension(): void
    {
        app(SandboxImages::class)->extractFromText($this->deckUri($this->deckBytes(2), null));

        $this->assertCount(1, $this->stored);
        $this->assertMatchesRegularExpression('/^sandbox_\d+_0\.pptx$/', $this->stored[0]['filename']);
    }

    /**
     * Spaces and umlauts in the name, raw or percent-encoded, do not end the
     * parameter early. Recorded: a model saved "Anthropomorphisierung von
     * LLMs.pptx"; the pattern stopped at the first space, the deck never matched,
     * and 200 kB of base64 went to the model as text.
     */
    public function test_a_name_with_spaces_and_umlauts_is_taken_whole(): void
    {
        $images = app(SandboxImages::class);

        $cleaned = $images->extractFromText(
            $this->deckUri($this->deckBytes(1), 'Anthropomorphisierung von LLMs Übersicht.pptx')."\n"
            .$this->deckUri($this->deckBytes(1), 'Zweite%20Vorlage%20%C3%9C.pptx')
        );

        $this->assertStringNotContainsString('base64,', $cleaned);
        $this->assertCount(2, $this->stored);
        $this->assertSame('Anthropomorphisierung von LLMs Übersicht.pptx', $this->stored[0]['filename']);
        $this->assertSame('Zweite Vorlage Ü.pptx', $this->stored[1]['filename']);
        // The link the model is told to copy must parse as Markdown: encoded, not raw.
        $this->assertStringContainsString('[Anthropomorphisierung von LLMs Übersicht.pptx](sandbox:/tmp/Anthropomorphisierung%20von%20LLMs%20%C3%9Cbersicht.pptx)', $cleaned);
        $this->assertStringNotContainsString('(sandbox:/tmp/Anthropomorphisierung von', $cleaned);
    }

    /**
     * The name is a path segment on the way to the storage, so it is reduced to
     * a basename - and percent-decoded, because a program that has been told the
     * URI grammar may well encode the space.
     */
    public function test_a_given_name_is_decoded_and_reduced_to_its_basename(): void
    {
        app(SandboxImages::class)->extractFromText(
            $this->deckUri($this->deckBytes(1), '..%2F..%2Fetc%2FMeine%20Folien.pptx')
        );

        $this->assertSame('Meine Folien.pptx', $this->stored[0]['filename']);
    }

    /**
     * The render-back check prints a slide PNG next to the deck. Each takes its
     * own route: the picture is drawn in the chat, the deck is a download, and
     * the deck's base64 - which also contains letters - is not mistaken for a
     * second picture.
     */
    public function test_a_deck_and_a_slide_preview_in_one_output_take_their_own_routes(): void
    {
        $images = app(SandboxImages::class);

        $cleaned = $images->extractFromText(
            $this->deckUri($this->deckBytes(8))."\n".'data:image/png;base64,'.self::PNG
        );

        $this->assertStringContainsString('[file 1 "deck.pptx"', $cleaned);
        $this->assertStringContainsString('[image 1 was produced', $cleaned);

        $collected = $images->drain();
        $this->assertCount(2, $collected);
        $this->assertSame('file', $collected[0]['kind']);
        $this->assertArrayNotHasKey('kind', $collected[1]);

        // Only the deck went through the file store; the PNG went through the image store.
        $this->assertCount(1, $this->stored);
    }

    public function test_an_undecodable_file_is_reported_and_not_collected(): void
    {
        $images = app(SandboxImages::class);

        $cleaned = $images->extractFromText('data:'.self::PPTX_MIME.';base64,!!!not-base64!!!');

        // The pattern does not match invalid base64 characters at all, so the
        // text is left as it was rather than half-replaced.
        $this->assertStringContainsString('!!!not-base64!!!', $cleaned);
        $this->assertSame([], $this->stored);
        $this->assertSame([], $images->drain());
    }

    /**
     * The regression. Through the tool, with the MCP server faked: a deck-sized
     * output has to come back to the model as the note, not as 8000 characters
     * of base64 followed by "[output truncated]".
     */
    public function test_through_the_tool_a_deck_is_not_truncated_but_stored(): void
    {
        config([
            'hawki_tools.mcp_servers' => [
                'code-exec-mcp' => ['url' => 'https://code.test/mcp', 'requires_session' => true, 'timeout' => 30],
            ],
            'hawki_tools.bindings.code_interpreter' => [
                'server' => 'code-exec-mcp',
                'tools' => ['run' => 'code_exec'],
            ],
        ]);

        $envelope = json_encode(['text' => $this->deckUri($this->deckBytes())."\n", 'meta' => ['timed_out' => false]]);

        Http::fakeSequence('https://code.test/mcp')
            ->push("data: {\"result\":{\"protocolVersion\":\"2024-11-05\"}}\n", 200, ['mcp-session-id' => 'sess-1'])
            ->push('data: {"result":{"content":[{"type":"text","text":'.json_encode($envelope).'}]}}'."\n", 200);

        $output = app(CodeInterpreterTool::class)->execute(['code' => 'print(deck)']);

        $this->assertStringNotContainsString('[output truncated', $output);
        $this->assertStringNotContainsString('base64,', $output);
        $this->assertStringContainsString('[deck.pptx](sandbox:/tmp/deck.pptx)', $output);
        $this->assertCount(1, $this->stored);
        $this->assertCount(1, app(SandboxImages::class)->drain());
    }

    /**
     * The streamed request announces the file the way the Responses provider
     * announces a container file, so the frontend resolves the model's
     * sandbox:/tmp/deck.pptx link with the renderer it already has. No 'inline',
     * no markdown in the text: a deck is not drawn, it is downloaded.
     */
    public function test_the_streaming_request_announces_a_file_as_a_container_file(): void
    {
        app(SandboxImages::class)->extractFromText($this->deckUri($this->deckBytes(4)));

        $sent = null;

        $request = new OpenAiHawkiToolsStreamingRequest(
            ['model' => 'jlu/qwen3.8-27b', 'messages' => []],
            function (AiResponse $response) use (&$sent): void {
                $sent = $response;
            },
            [],
            app(ToolCallRunner::class),
        );

        (function (): void {
            $this->emitToolImages(CodeInterpreterTool::KEY);
        })->call($request);

        $this->assertNotNull($sent, 'Nothing was sent to the client.');

        $auxiliaries = $sent->content['auxiliaries'] ?? [];
        $this->assertCount(1, $auxiliaries);
        $this->assertSame('container_file', $auxiliaries[0]['type']);

        $file = json_decode($auxiliaries[0]['content'], true);
        $this->assertSame('deck.pptx', $file['filename']);
        $this->assertSame('https://hawki.test/files/deck.pptx', $file['url']);
        $this->assertSame('file-uuid-1', $file['uuid']);
        $this->assertSame(0, $file['output_index']);
        $this->assertArrayNotHasKey('inline', $file);
        $this->assertArrayNotHasKey('kind', $file, 'The routing marker is internal and must not reach the client.');

        $this->assertSame('', $sent->content['text'] ?? '', 'A file must not be written into the message text as an image.');
    }

    /**
     * The frontend's half of the contract. A reloaded message restores its
     * status log and leaves updateAiStatusIndicator early; the container file
     * registration used to sit behind that exit, so on a reload no file was ever
     * remembered - and syncInlinePlots, meeting the model's sandbox: link with
     * nothing to point it at, reduced it to plain text for good. The
     * registration has to come before that return, and the reduction has to
     * stay behind the registration.
     */
    public function test_the_frontend_remembers_container_files_before_it_can_return_early(): void
    {
        $script = file_get_contents(public_path('js/syntax_modifier.js'));

        $function = strpos($script, 'function updateAiStatusIndicator(');
        $this->assertNotFalse($function);

        $remember = strpos($script, 'rememberContainerFiles(messageElement, auxiliaries)', $function);
        $this->assertNotFalse($remember, 'updateAiStatusIndicator does not register the container files at all.');

        $firstReturn = strpos($script, 'return;', $function);
        $this->assertNotFalse($firstReturn);

        $this->assertLessThan(
            $firstReturn,
            $remember,
            'Container files are registered after the first early return of updateAiStatusIndicator, '
            .'so a message loaded from the database never remembers them and its sandbox: link dies.'
        );

        // The registration resolves the links itself - a pass behind the early
        // return would never run for a reloaded message.
        $this->assertStringContainsString('if (rememberContainerFiles(messageElement, auxiliaries) > 0) {', $script);

        // And the resolver still keys by the name the auxiliary carries.
        $this->assertStringContainsString('rememberContainerFile(messageElement, filename || name, url)', $script);

        // An unresolved link is parked, not destroyed: the reference survives on
        // a span until the file is announced, then becomes a link again.
        $this->assertStringNotContainsString('link.replaceWith(document.createTextNode(link.textContent))', $script);
        $this->assertStringContainsString("placeholder.className = 'sandbox-file-pending'", $script);
        $this->assertStringContainsString("span.sandbox-file-pending[data-sandbox-ref]", $script);
    }

    /**
     * The frontend's safety net for a model that writes the name raw anyway:
     * spaces in a sandbox: destination are encoded before markdown-it parses,
     * and the resolver decodes the file name when it points the link at the file.
     */
    public function test_the_frontend_encodes_spaces_in_sandbox_link_destinations_before_rendering(): void
    {
        $script = file_get_contents(public_path('js/syntax_modifier.js'));

        $this->assertStringContainsString('function encodeSandboxLinkDestinations(text)', $script);
        $this->assertStringContainsString('preprocessContent(encodeSandboxLinkDestinations(contentToProcess))', $script);
        // ...and the resolver still decodes the name it looks up.
        $this->assertStringContainsString('return decodeURIComponent(path.split(\'/\').pop()', $script);
    }

    /**
     * A regenerated answer arrives on the same message element. The server
     * drops the previous generation's files from the message; the strip of file
     * chips above the message has to follow - it used to keep the old deck next
     * to the new answer's link until the page was reloaded.
     */
    public function test_the_frontend_rebuilds_the_attachment_strip_on_update_and_clears_it_on_regenerate(): void
    {
        $script = file_get_contents(public_path('js/message_functions.js'));

        $this->assertStringContainsString('function renderAttachmentStrip(messageElement, attachments, generatedImageAttachmentUuids)', $script);
        // Rebuilt, not appended.
        $this->assertStringContainsString("attachmentContainer.innerHTML = '';", $script);

        $update = strpos($script, 'function updateMessageElement(');
        $this->assertNotFalse($update);
        $this->assertStringContainsString(
            'renderAttachmentStrip(messageElement, messageObj.content.attachments || [], extractGeneratedImageUuids(finalAuxiliaries))',
            substr($script, $update),
            'updateMessageElement does not rebuild the strip from the server\'s list.'
        );

        // The load path uses the same renderer, so the two cannot drift apart.
        $this->assertSame(2, substr_count($script, 'renderAttachmentStrip(messageElement, messageObj.content.attachments || []'), 'Load and update have to share the strip renderer.');

        // And the regenerate cleanup empties the strip along with the plots and files it forgets.
        $cleanup = strpos($script, "delete messageElement.dataset.containerFiles;");
        $this->assertNotFalse($cleanup);
        $this->assertStringContainsString("staleStrip.innerHTML = '';", substr($script, $cleanup, 600));
    }

    public function test_the_non_streaming_request_describes_a_file_without_inline(): void
    {
        app(SandboxImages::class)->extractFromText($this->deckUri($this->deckBytes(4)));

        $request = new OpenAiHawkiToolsNonStreamingRequest(
            ['model' => 'jlu/qwen3.8-27b', 'messages' => []],
            [],
            app(ToolCallRunner::class),
        );

        $described = (function (): array {
            return $this->collectToolImages(CodeInterpreterTool::KEY);
        })->call($request);

        $this->assertCount(1, $described);
        $this->assertSame('file', $described[0]['kind']);
        $this->assertArrayNotHasKey('inline', $described[0]);
        $this->assertSame(0, $described[0]['output_index']);
    }
}
