<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\VoiceChatPrompt;
use PHPUnit\Framework\TestCase;

class VoiceChatPromptTest extends TestCase
{
    private const PROMPT = 'VOICE CONVERSATION';

    public function test_appends_to_the_existing_system_prompt(): void
    {
        $messages = [
            ['role' => 'system', 'content' => ['text' => 'Du bist ein hilfreicher Assistent.']],
            ['role' => 'user', 'content' => ['text' => 'Hallo']],
        ];

        $result = VoiceChatPrompt::apply($messages, self::PROMPT);

        $this->assertCount(2, $result);
        $this->assertSame("Du bist ein hilfreicher Assistent.\n\nVOICE CONVERSATION", $result[0]['content']['text']);
        $this->assertSame($messages[1], $result[1]);
    }

    public function test_adds_a_system_message_when_there_is_none(): void
    {
        $messages = [['role' => 'user', 'content' => ['text' => 'Hallo']]];

        $result = VoiceChatPrompt::apply($messages, self::PROMPT);

        $this->assertSame(['role' => 'system', 'content' => ['text' => self::PROMPT]], $result[0]);
        $this->assertSame($messages[0], $result[1]);
    }

    public function test_fills_an_empty_system_prompt_without_leading_blank_lines(): void
    {
        $messages = [['role' => 'system', 'content' => ['text' => '  ']], ['role' => 'user', 'content' => ['text' => 'Hi']]];

        $this->assertSame(self::PROMPT, VoiceChatPrompt::apply($messages, self::PROMPT)[0]['content']['text']);
    }

    public function test_leaves_the_messages_alone_without_a_prompt(): void
    {
        $messages = [['role' => 'user', 'content' => ['text' => 'Hallo']]];

        $this->assertSame($messages, VoiceChatPrompt::apply($messages, '   '));
    }

    public function test_the_configured_prompt_asks_for_no_lists_and_no_emojis(): void
    {
        $config = require __DIR__ . '/../../../../config/hawki.php';
        $prompt = $config['voice_chat_prompt'] ?? '';

        $this->assertStringContainsString('No bullet points', $prompt);
        $this->assertStringContainsString('No emojis', $prompt);
    }
}
