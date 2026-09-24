<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Voice chat: the answer is read aloud, so the model is asked for spoken
 * style - no lists, Markdown or emojis (config hawki.voice_chat_prompt).
 *
 * Works on HAWKI's own message format (role + content.text), before any
 * provider conversion, so every provider and the group chat get the same
 * instruction.
 */
final class VoiceChatPrompt
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    public static function apply(array $messages, string $prompt): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return $messages;
        }

        foreach ($messages as $index => $message) {
            if (($message['role'] ?? null) !== 'system') {
                continue;
            }

            $text = trim((string) ($message['content']['text'] ?? ''));
            $messages[$index]['content']['text'] = $text === '' ? $prompt : $text . "\n\n" . $prompt;

            return $messages;
        }

        array_unshift($messages, [
            'role' => 'system',
            'content' => ['text' => $prompt],
        ]);

        return $messages;
    }
}
