<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiConv;
use App\Models\Attachment;
use App\Models\User;
use App\Services\Storage\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * A diagram edited in HAWKI is saved on the message it came from - as a file
 * next to the encrypted text, named after its code block - and the box shows it
 * in place of the model's original. One file per block: a save replaces the
 * previous one.
 */
class AttachMessageDiagramTest extends TestCase
{
    use RefreshDatabase;

    private const XML = '<mxfile host="hawki"><diagram name="Page-1"><mxGraphModel><root><mxCell id="0"/></root></mxGraphModel></diagram></mxfile>';

    private User $owner;

    private AiConv $conv;

    /** @var array<int,array{uuid: string, category: string}> */
    private array $deleted = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->conv = AiConv::create(['slug' => 'conv-1', 'user_id' => $this->owner->id, 'conv_name' => 'x']);
        $this->conv->messages()->create([
            'user_id' => $this->owner->id, 'message_id' => '2.000', 'message_role' => 'assistant', 'model' => 'gpt',
            'iv' => 'iv', 'tag' => 'tag', 'content' => 'c', 'completion' => true,
        ]);

        $storage = $this->createMock(FileStorageService::class);
        $storage->method('store')->willReturn(true);
        $storage->method('moveFileToPersistentFolder')->willReturn(true);
        $storage->method('delete')->willReturnCallback(function (string $uuid, string $category): bool {
            $this->deleted[] = compact('uuid', 'category');

            return true;
        });
        $this->app->instance(FileStorageService::class, $storage);
    }

    private function save(User $as, int $block = 0, string $messageId = '2.000', string $slug = 'conv-1')
    {
        return $this->actingAs($as)->withoutMiddleware()->post('/req/conv/message/attachment/'.$slug, [
            'message_id' => $messageId,
            'block' => $block,
            'file' => UploadedFile::fake()->createWithContent('drawio-block-'.$block.'.drawio', self::XML),
        ], ['Accept' => 'application/json']);
    }

    public function test_the_owner_saves_a_diagram_on_a_message_of_their_chat(): void
    {
        $response = $this->save($this->owner, 1);

        $response->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('fileData.name', 'drawio-block-1.drawio')
            ->assertJsonPath('fileData.block', 1)
            ->assertJsonPath('fileData.mime', 'application/vnd.jgraph.mxfile');

        $message = $this->conv->messages()->first();
        $attachment = $message->attachments()->first();
        $this->assertNotNull($attachment);
        $this->assertSame('drawio-block-1.drawio', $attachment->name);
        $this->assertSame('document', $attachment->type);
        $this->assertStringEndsWith('/req/conv/attachment/view/'.$attachment->uuid, $response->json('fileData.url'));
    }

    public function test_a_second_save_replaces_the_first_for_the_same_block(): void
    {
        $first = $this->save($this->owner, 0)->json('fileData.uuid');
        $this->save($this->owner, 0)->assertOk();
        $this->save($this->owner, 1)->assertOk();

        $names = $this->conv->messages()->first()->attachments()->pluck('name')->sort()->values()->all();
        $this->assertSame(['drawio-block-0.drawio', 'drawio-block-1.drawio'], $names);
        $this->assertNull(Attachment::where('uuid', $first)->first());
        $this->assertSame([['uuid' => $first, 'category' => 'private']], $this->deleted);
    }

    public function test_somebody_else_cannot_save_on_the_message(): void
    {
        $this->save(User::factory()->create())->assertForbidden();
        $this->assertSame(0, Attachment::count());
    }

    public function test_an_unknown_message_or_a_bad_block_is_refused(): void
    {
        $this->save($this->owner, 0, '9.000')->assertNotFound();
        $this->save($this->owner, -1)->assertStatus(422);
        $this->save($this->owner, 0, '2.000', 'no-such-conv')->assertNotFound();
    }

    public function test_the_chat_shows_the_saved_version_and_hides_it_from_the_file_list(): void
    {
        $js = file_get_contents(public_path('js/syntax_modifier.js'));
        $this->assertStringContainsString('const saved = savedDiagramFor(context);', $js);
        $this->assertStringContainsString("wrapper.classList.toggle('diagram-edited', edited);", $js);
        $this->assertStringContainsString("openDrawioEditor(preview.dataset.source || block.textContent, {", $js);

        $messages = file_get_contents(public_path('js/message_functions.js'));
        $this->assertStringContainsString('const SAVED_DIAGRAM_NAME = /^drawio-block-(\\d+)\\.drawio$/;', $messages);
        $this->assertStringContainsString('.filter(attachment => savedDiagramBlock(attachment?.fileData?.name) === null)', $messages);

        $editor = file_get_contents(public_path('js/drawio_functions.js'));
        $this->assertStringContainsString("post({ action: 'load', xml: String(xml), autosave: 1, title: name });", $editor);
        $this->assertStringContainsString("} else if (message.event === 'autosave') {\n      dirty = true;", $editor);
        $this->assertStringContainsString('if (dirty && !(await confirmDiscard())) {', $editor);
        $this->assertStringContainsString("fetch(`/req/conv/message/attachment/\${encodeURIComponent(target.slug)}`", $editor);
        $this->assertStringContainsString('class="closeButton drawio-editor-close"', $editor);

        // The confirm modal (.modal, z-index 99) must open above the editor.
        $css = file_get_contents(public_path('css/hljs_custom.css'));
        $this->assertMatchesRegularExpression('/\.drawio-editor-modal \{[^}]*z-index: 98;/', $css);

        foreach (['en_US', 'de_DE'] as $language) {
            $texts = json_decode(file_get_contents(resource_path("language/{$language}.json")), true);
            foreach (['SaveToMessage', 'UnsavedDiagramChanges', 'DiagramSaveFailed', 'DiagramEdited'] as $key) {
                $this->assertNotEmpty($texts[$key] ?? '', $language.' is missing '.$key);
            }
        }
    }
}
