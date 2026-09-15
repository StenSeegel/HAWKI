<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use Illuminate\Container\Attributes\Singleton;

/**
 * Turns the code interpreter's echo in earlier assistant turns back into the tool
 * exchange it came from, before the conversation is sent to the model again.
 *
 * A finished call is written into the message as a fenced python block with the
 * code and a fenced output block with what the sandbox printed - for the user,
 * who sees the evidence the way the native code interpreter shows it. The stored
 * message text is also what the next request carries as the assistant's turn,
 * and there the echo does harm: to the model it reads as text it wrote itself.
 * Asked to regenerate, gemma reproduced the previous turn verbatim - the python
 * block, the output block including the "[file 1 ... was produced]" note, and
 * the sandbox: link - without calling the tool. No file existed behind the link.
 *
 * So the echo is rebuilt as what actually happened: an assistant message with a
 * tool_call and a tool message with the result, followed by the assistant's own
 * words. The shape the model is trained on says "a result comes from a call",
 * and the earlier turn no longer offers a text pattern to copy. The file note is
 * condensed to say the file was delivered back then and is gone from the
 * sandbox, so a stale link is not something the history invites either.
 *
 * Without the tool in the request the roles would be unusual for the chat
 * template (a tool message with no tools declared), so the echo is collapsed to
 * a short note instead.
 */
#[Singleton]
final class CodeInterpreterHistory
{
    public const TOOL = 'code_interpreter';

    /**
     * One echoed call: the python block and the output block right after it. The
     * closing fence of the output has to be followed by a blank line or the end
     * of the text - the emitter always leaves one - so a "```" line inside the
     * output does not cut the block short.
     */
    private const ECHO_PATTERN = '/```python\n(.*?)\n```(?:[ \t]*\n)+```output\n(.*?)\n```(?=[ \t]*(?:\n[ \t]*\n|\n?$))/s';

    /**
     * The inline plot HAWKI wrote under the echo: "![Plot](<attachment url>)".
     * Left in the history, gemma answered the next turn with three of its own,
     * pointing at attachment uuids it made up - broken pictures in the message,
     * and the citation collector listed them as sources.
     */
    private const INLINE_IMAGE_PATTERN = '/!\[[^\]\n]*\]\([^)\s]*\/attachment\/[^)\s]*\)[ \t]*\n?/';

    private const FILE_NOTE_PATTERN = '/\[file \d+ "([^"]+)" was produced and is offered to the user as a download\..*?HAWKI points that link at the stored file\.\]/s';

    private const COLLAPSED_NOTE = '[HAWKI ran code for this answer in that earlier turn and showed the user the program and its output. '
        .'Nothing from that run exists anymore.]';

    /**
     * @param  array<int,array<string,mixed>>  $messages  the payload messages in OpenAI chat shape
     * @return array<int,array<string,mixed>>
     */
    public function rewrite(array $messages, bool $toolOffered): array
    {
        $rewritten = [];
        $callNumber = 0;

        foreach ($messages as $message) {
            if (($message['role'] ?? '') !== 'assistant') {
                $rewritten[] = $message;

                continue;
            }

            $text = $this->textOf($message);
            if ($text === null || preg_match_all(self::ECHO_PATTERN, $text, $echoes, PREG_SET_ORDER) === 0) {
                $rewritten[] = $message;

                continue;
            }

            if (! $toolOffered) {
                $rewritten[] = $this->withText($message, preg_replace(self::ECHO_PATTERN, self::COLLAPSED_NOTE, $text));

                continue;
            }

            foreach ($echoes as $echo) {
                $callNumber++;
                $id = 'call_hawki_history_'.$callNumber;

                $rewritten[] = [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => $id,
                        'type' => 'function',
                        'function' => [
                            'name' => self::TOOL,
                            'arguments' => json_encode(['code' => $echo[1]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ],
                    ]],
                ];

                $rewritten[] = [
                    'role' => 'tool',
                    'tool_call_id' => $id,
                    'name' => self::TOOL,
                    'content' => $this->condenseResult($echo[2]),
                ];
            }

            $remaining = (string) preg_replace(self::ECHO_PATTERN, '', $text);
            $remaining = (string) preg_replace(self::INLINE_IMAGE_PATTERN, '', $remaining);
            $remaining = (string) preg_replace("/\n{3,}/", "\n\n", trim($remaining));

            if ($remaining !== '') {
                $rewritten[] = $this->withText($message, $remaining);
            }
        }

        return $rewritten;
    }

    /**
     * The result of an earlier run, with the delivery note rewritten: the link it
     * asked the model to write belonged to that answer, and the file is gone from
     * the sandbox.
     */
    private function condenseResult(string $result): string
    {
        return (string) preg_replace_callback(
            self::FILE_NOTE_PATTERN,
            fn (array $m): string => '[file "'.$m[1].'" was produced by this run and delivered to the user with that answer. '
                .'The sandbox is empty again; to work with it in a new call list "'.$m[1].'" in the files argument and open /work/'.$m[1].'.]',
            $result
        );
    }

    /**
     * The message text, whether it is a plain string or the first text part.
     */
    private function textOf(array $message): ?string
    {
        $content = $message['content'] ?? null;

        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            foreach ($content as $part) {
                if (($part['type'] ?? '') === 'text' && is_string($part['text'] ?? null)) {
                    return $part['text'];
                }
            }
        }

        return null;
    }

    private function withText(array $message, string $text): array
    {
        if (is_string($message['content'])) {
            $message['content'] = $text;

            return $message;
        }

        foreach ($message['content'] as $i => $part) {
            if (($part['type'] ?? '') === 'text') {
                $message['content'][$i]['text'] = $text;

                break;
            }
        }

        return $message;
    }
}
