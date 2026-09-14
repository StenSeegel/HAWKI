<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\Tools\CodeInterpreterHistory;
use PHPUnit\Framework\TestCase;

class CodeInterpreterHistoryTest extends TestCase
{
    private const CODE = "from hawki_slides import Deck\ndeck = Deck(title=\"LLMs\", author=\"HAWKI\")\ndeck.save(\"/tmp/LLMs.pptx\")";

    private const OUTPUT = "[image 1 was produced and is shown to the user]\n"
        .'[file 1 "LLMs.pptx" was produced and is offered to the user as a download. '
        .'Link it in your answer exactly as [LLMs.pptx](sandbox:/tmp/LLMs.pptx) - HAWKI points that link at the stored file.]';

    /**
     * The assistant turn the way the streaming request wrote it: the echo, the
     * inline plot, then the model's own words.
     */
    private function echoedTurn(): array
    {
        return [
            'role' => 'assistant',
            'content' => [[
                'type' => 'text',
                'text' => "\n\n```python\n".self::CODE."\n```\n\n```output\n".self::OUTPUT."\n```\n\n"
                    ."![Plot](https://app.hawki.dev/req/conv/attachment/view/725c5321-9a58-458e-91e3-54aeccc37291)\n\n"
                    ."Hier ist das Deck: [LLMs.pptx](sandbox:/tmp/LLMs.pptx)",
            ]],
        ];
    }

    public function test_the_echo_becomes_a_tool_call_and_a_tool_result(): void
    {
        $messages = (new CodeInterpreterHistory())->rewrite([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Mach ein Deck']]],
            $this->echoedTurn(),
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Noch einmal']]],
        ], true);

        $this->assertSame(['user', 'assistant', 'tool', 'assistant', 'user'], array_column($messages, 'role'));

        $call = $messages[1];
        $this->assertNull($call['content']);
        $this->assertSame('code_interpreter', $call['tool_calls'][0]['function']['name']);
        $this->assertSame(['code' => self::CODE], json_decode($call['tool_calls'][0]['function']['arguments'], true));

        $result = $messages[2];
        $this->assertSame($call['tool_calls'][0]['id'], $result['tool_call_id']);
        $this->assertSame('code_interpreter', $result['name']);

        // The model's own words stay the assistant's turn, without the echo and
        // without the inline plot HAWKI wrote under it - the tool result already
        // says an image was produced, and a picture link in the history is a
        // pattern the model copies with made up uuids.
        $answer = $messages[3]['content'][0]['text'];
        $this->assertStringNotContainsString('```', $answer);
        $this->assertStringNotContainsString('![Plot]', $answer);
        $this->assertStringNotContainsString('/attachment/view/', $answer);
        $this->assertSame('Hier ist das Deck: [LLMs.pptx](sandbox:/tmp/LLMs.pptx)', $answer);
    }

    public function test_the_delivery_note_of_an_earlier_run_no_longer_dictates_a_link(): void
    {
        $messages = (new CodeInterpreterHistory())->rewrite([$this->echoedTurn()], true);

        $result = $messages[1]['content'];
        $this->assertStringContainsString('[image 1 was produced and is shown to the user]', $result);
        $this->assertStringContainsString('"LLMs.pptx" was produced by this run and delivered to the user with that answer', $result);
        $this->assertStringContainsString('a new file needs a new tool call', $result);
        $this->assertStringNotContainsString('Link it in your answer', $result);
        $this->assertStringNotContainsString('sandbox:/tmp/', $result);
    }

    public function test_two_calls_in_one_turn_become_two_exchanges_in_order(): void
    {
        $text = "```python\nprint(1)\n```\n\n```output\n1\n```\n\nFirst.\n\n```python\nprint(2)\n```\n\n```output\n2\n```\n\nSecond.";

        $messages = (new CodeInterpreterHistory())->rewrite([
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $text]]],
        ], true);

        $this->assertSame(['assistant', 'tool', 'assistant', 'tool', 'assistant'], array_column($messages, 'role'));
        $this->assertSame(['code' => 'print(1)'], json_decode($messages[0]['tool_calls'][0]['function']['arguments'], true));
        $this->assertSame('1', $messages[1]['content']);
        $this->assertSame(['code' => 'print(2)'], json_decode($messages[2]['tool_calls'][0]['function']['arguments'], true));
        $this->assertSame('2', $messages[3]['content']);
        $this->assertNotSame($messages[0]['tool_calls'][0]['id'], $messages[2]['tool_calls'][0]['id']);
        $this->assertSame("First.\n\nSecond.", $messages[4]['content'][0]['text']);
    }

    public function test_a_turn_that_is_only_the_echo_leaves_no_empty_assistant_message(): void
    {
        $messages = (new CodeInterpreterHistory())->rewrite([
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => "```python\nprint(1)\n```\n\n```output\n1\n```\n\n"]]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'ok']]],
        ], true);

        $this->assertSame(['assistant', 'tool', 'user'], array_column($messages, 'role'));
    }

    public function test_a_code_block_the_model_wrote_without_an_output_block_is_left_alone(): void
    {
        $turn = ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => "So geht es:\n\n```python\nprint(1)\n```\n\nFertig."]]];

        $this->assertSame([$turn], (new CodeInterpreterHistory())->rewrite([$turn], true));
    }

    public function test_an_output_block_containing_a_fence_marker_is_kept_whole(): void
    {
        $output = "line\n```\nstill output";
        $text = "```python\nprint(1)\n```\n\n```output\n".$output."\n```\n\nDone.";

        $messages = (new CodeInterpreterHistory())->rewrite([
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $text]]],
        ], true);

        $this->assertSame($output, $messages[1]['content']);
        $this->assertSame('Done.', $messages[2]['content'][0]['text']);
    }

    public function test_without_the_tool_the_echo_collapses_to_a_note(): void
    {
        $messages = (new CodeInterpreterHistory())->rewrite([$this->echoedTurn()], false);

        $this->assertCount(1, $messages);
        $this->assertSame('assistant', $messages[0]['role']);

        $text = $messages[0]['content'][0]['text'];
        $this->assertStringNotContainsString('```', $text);
        $this->assertStringNotContainsString('Link it in your answer', $text);
        $this->assertStringContainsString('Nothing from that run exists anymore', $text);
        $this->assertStringContainsString('Hier ist das Deck', $text);
    }

    public function test_user_and_system_messages_and_plain_string_content_pass_through(): void
    {
        $messages = [
            ['role' => 'system', 'content' => "```python\nx\n```\n\n```output\ny\n```"],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]],
            ['role' => 'assistant', 'content' => 'plain answer'],
        ];

        $this->assertSame($messages, (new CodeInterpreterHistory())->rewrite($messages, true));
    }

    public function test_plain_string_assistant_content_is_rewritten_too(): void
    {
        $messages = (new CodeInterpreterHistory())->rewrite([
            ['role' => 'assistant', 'content' => "```python\nprint(1)\n```\n\n```output\n1\n```\n\nDone."],
        ], true);

        $this->assertSame(['assistant', 'tool', 'assistant'], array_column($messages, 'role'));
        $this->assertSame('Done.', $messages[2]['content']);
    }
}
