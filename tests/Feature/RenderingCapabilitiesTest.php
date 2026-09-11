<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\Prompt\RenderingCapabilities;
use App\Services\AI\Value\AiRequest;
use Tests\TestCase;

/**
 * A model without an image tool draws with SVG, mermaid or draw.io markup and
 * has no idea the chat renders it - so it described the XML or apologised for
 * not being able to show pictures. It is told what the user sees.
 */
class RenderingCapabilitiesTest extends TestCase
{
    private function request(array $messages, ?string $assistantKey = null): AiRequest
    {
        return new AiRequest(payload: ['model' => 'gpt', 'stream' => true, 'messages' => $messages], assistantKey: $assistantKey);
    }

    public function test_the_note_is_appended_to_the_system_prompt(): void
    {
        $request = (new RenderingCapabilities())->apply($this->request([
            ['role' => 'system', 'content' => ['text' => 'You are HAWKI.']],
            ['role' => 'user', 'content' => ['text' => 'Draw a flowchart.']],
        ]));

        $system = $request->payload['messages'][0]['content']['text'];
        $this->assertStringStartsWith('You are HAWKI.', $system);
        $this->assertStringContainsString('```drawio', $system);
        $this->assertStringContainsString('<mxfile>', $system);
        $this->assertStringContainsString('```svg', $system);
        $this->assertStringContainsString('```mermaid', $system);
        $this->assertStringContainsString('diagram editor inside this application', $system);

        // What the model kept doing: a draw.io import tutorial and "as a text model
        // I cannot save the file". Both are ruled out in so many words.
        $this->assertStringContainsString('never has to copy code, save a file or import anything', $system);
        $this->assertStringContainsString('Never say that you are a text model', $system);
        $this->assertStringContainsString('Never explain how to open, import, paste or configure the code in draw.io', $system);

        // The user's own message is left alone.
        $this->assertSame('Draw a flowchart.', $request->payload['messages'][1]['content']['text']);
    }

    public function test_a_request_without_a_system_prompt_gets_one(): void
    {
        $request = (new RenderingCapabilities())->apply($this->request([
            ['role' => 'user', 'content' => ['text' => 'Hi']],
        ]));

        $this->assertSame('system', $request->payload['messages'][0]['role']);
        $this->assertSame(RenderingCapabilities::NOTE, $request->payload['messages'][0]['content']['text']);
        $this->assertSame('user', $request->payload['messages'][1]['role']);
    }

    public function test_the_note_is_added_once(): void
    {
        $capabilities = new RenderingCapabilities();
        $request = $capabilities->apply($capabilities->apply($this->request([
            ['role' => 'system', 'content' => ['text' => 'Be brief.']],
        ])));

        $this->assertSame(1, substr_count($request->payload['messages'][0]['content']['text'], '```drawio'));
    }

    /**
     * The title model summarises the chat with ten tokens to answer in; a
     * rendering lecture in front of the text is the last thing it needs.
     */
    public function test_utility_assistants_are_left_alone(): void
    {
        $request = (new RenderingCapabilities())->apply($this->request([
            ['role' => 'user', 'content' => ['text' => 'Name this chat.']],
        ], 'title_generator'));

        $this->assertCount(1, $request->payload['messages']);
        $this->assertSame('user', $request->payload['messages'][0]['role']);
    }

    public function test_the_ai_service_applies_it_to_chat_requests(): void
    {
        $source = file_get_contents(app_path('Services/AI/AiService.php'));

        $this->assertStringContainsString('RenderingCapabilities::class)->apply($request)', $source);
    }
}
