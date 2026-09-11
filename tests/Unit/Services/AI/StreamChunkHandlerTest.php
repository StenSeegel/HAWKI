<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\Utils\StreamChunkHandler;
use Tests\TestCase;

/**
 * KI-735: code and LaTeX arrive at the browser with characters missing on
 * OpenAI, Gemini and Anthropic, but come through intact on the local models.
 *
 * The split runs along the wire format, not the vendor. Local models (GWDG,
 * Ollama, OpenWebUI) speak OpenAI chat-completions, where every curl write
 * starts with "data: " and StreamChunkHandler hands it straight to the
 * provider. The three broken providers did not: Gemini streamed a bare JSON
 * array (:streamGenerateContent was requested without alt=sse; it is with it
 * now, though the array shape stays supported), and Anthropic and the OpenAI
 * Responses API prefix each event with an "event: " line. All
 * three therefore fall through to normalizeDataChunk(), which reassembles
 * objects by counting { and } — while blind to the fact that a brace inside a
 * JSON string is content, not structure.
 *
 * Java and LaTeX are exactly the brace-heavy content that breaks it, which is
 * why the ticket reproduces with "java hello world" and with \acro{A}{...}.
 *
 * Each test feeds the handler a byte-for-byte realistic stream and asserts on
 * the reassembled text, so a fix is free to change the reassembly strategy.
 */
class StreamChunkHandlerTest extends TestCase
{
    /** Collects the text deltas the handler emits, per provider shape. */
    private function collect(array $writes, callable $extractText): string
    {
        $text = '';
        $handler = new StreamChunkHandler(function (string $chunk) use (&$text, $extractText) {
            $decoded = json_decode(trim($chunk), true);
            if (is_array($decoded)) {
                $text .= $extractText($decoded) ?? '';
            }
        });

        foreach ($writes as $write) {
            $handler->handle($write);
        }

        return $text;
    }

    private static function anthropicText(array $d): ?string
    {
        return ($d['type'] ?? null) === 'content_block_delta'
            ? ($d['delta']['text'] ?? null)
            : null;
    }

    private static function googleText(array $d): ?string
    {
        return $d['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }

    private static function openAiChatText(array $d): ?string
    {
        return $d['choices'][0]['delta']['content'] ?? null;
    }

    /** One Anthropic SSE event, exactly as the API frames it. */
    private static function anthropicEvent(string $textDelta): string
    {
        return "event: content_block_delta\n"
            . 'data: ' . json_encode([
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => $textDelta],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n\n";
    }

    /** One element of Gemini's streamed JSON array (no alt=sse, so no "data: "). */
    private static function googleElement(string $textDelta, bool $first): string
    {
        return ($first ? '[' : ',') . "\n" . json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => $textDelta]], 'role' => 'model'],
            ]],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The ticket's example 1: "java hello world nur code" on Anthropic.
     * Every brace in the Java source is a brace the reassembler miscounts.
     */
    public function test_anthropic_streams_java_source_without_losing_characters(): void
    {
        $java = "public class HelloWorld {\n"
            . "    public static void main(String[] args) {\n"
            . "        System.out.println(\"Hello, World!\");\n"
            . "    }\n"
            . "}\n";

        // The model emits it the way models do: a few characters per event.
        $deltas = str_split($java, 7);
        $writes = array_map(fn($d) => self::anthropicEvent($d), $deltas);

        $received = $this->collect($writes, [self::class, 'anthropicText']);

        $this->assertSame($java, $received, 'Anthropic dropped part of the Java source.');
    }

    /**
     * The ticket's example 2: a LaTeX acronym list on Gemini. \acro{A}{adenosine}
     * packs two brace pairs into every line.
     */
    public function test_google_streams_latex_acronyms_without_losing_characters(): void
    {
        $latex = "\\acro{A}{adenosine}\n"
            . "\\acro{C}{cytidine}\n"
            . "\\acro{bp}{base pairs}\n"
            . "\\acroplural{tRNAs}[tRNA]{transfer RNAs}\n";

        $deltas = str_split($latex, 9);
        $writes = [];
        foreach ($deltas as $i => $delta) {
            $writes[] = self::googleElement($delta, $i === 0);
        }
        $writes[] = "\n]";

        $received = $this->collect($writes, [self::class, 'googleText']);

        $this->assertSame($latex, $received, 'Gemini dropped part of the LaTeX block.');
    }

    /**
     * The narrowest statement of the defect: a single brace of content, framed
     * in one complete, well-formed event. Nothing here is truncated or split —
     * the reassembler simply cannot tell a brace in a string from a brace in
     * the structure.
     */
    public function test_a_single_brace_of_content_survives_reassembly(): void
    {
        $received = $this->collect(
            [self::anthropicEvent('{')],
            [self::class, 'anthropicText']
        );

        $this->assertSame('{', $received, 'A lone "{" of content was swallowed.');
    }

    /**
     * The control: the same brace-heavy Java over the local-model wire format.
     * This is the path GWDG/Ollama/OpenWebUI take, and the ticket reports it
     * working — so it must stay working through any fix.
     */
    public function test_local_model_chat_completions_format_still_works(): void
    {
        $java = "class A { void f() { } }";
        $deltas = str_split($java, 5);

        $writes = array_map(
            fn($d) => 'data: ' . json_encode([
                'choices' => [['delta' => ['content' => $d], 'finish_reason' => null]],
            ], JSON_UNESCAPED_SLASHES) . "\n\n",
            $deltas
        );

        $received = $this->collect($writes, [self::class, 'openAiChatText']);

        $this->assertSame($java, $received, 'The local-model path regressed.');
    }

    /**
     * Independent of braces: curl hands over whatever bytes arrived, so a single
     * SSE event can straddle two write callbacks. The second half does not start
     * with "data: ", so the handler must hold the first half rather than drop it.
     */
    public function test_an_event_split_across_two_curl_writes_is_not_dropped(): void
    {
        $event = 'data: ' . json_encode([
            'choices' => [['delta' => ['content' => 'Hello, World!'], 'finish_reason' => null]],
        ], JSON_UNESCAPED_SLASHES) . "\n\n";

        $cut = (int) (strlen($event) * 0.6);
        $writes = [substr($event, 0, $cut), substr($event, $cut)];

        $received = $this->collect($writes, [self::class, 'openAiChatText']);

        $this->assertSame('Hello, World!', $received, 'A split SSE event lost its payload.');
    }

    /**
     * The handler separates events with explode("data: "), which matches that
     * string anywhere — including inside the content. YAML, CSS and JS snippets
     * all contain "data: " legitimately.
     */
    public function test_content_containing_the_literal_sse_delimiter_survives(): void
    {
        $yaml = "spec:\n  data: value\n";

        $event = 'data: ' . json_encode([
            'choices' => [['delta' => ['content' => $yaml], 'finish_reason' => null]],
        ], JSON_UNESCAPED_SLASHES) . "\n\n";

        $received = $this->collect([$event], [self::class, 'openAiChatText']);

        $this->assertSame($yaml, $received, 'Content containing "data: " was split apart.');
    }
    /**
     * The OpenAI leg: the Responses API frames deltas the same way Anthropic
     * does, with an "event: " line ahead of the payload, so it lands in the
     * same brace-counting reassembler.
     */
    public function test_openai_responses_streams_braced_code_without_losing_characters(): void
    {
        $code = "function f() { return {a: 1}; }";
        $deltas = str_split($code, 6);

        $writes = array_map(
            fn($d) => "event: response.output_text.delta\n"
                . 'data: ' . json_encode([
                    'type' => 'response.output_text.delta',
                    'delta' => $d,
                ], JSON_UNESCAPED_SLASHES) . "\n\n",
            $deltas
        );

        $received = $this->collect($writes, fn(array $d) => ($d['type'] ?? null) === 'response.output_text.delta'
            ? ($d['delta'] ?? null)
            : null);

        $this->assertSame($code, $received, 'OpenAI Responses dropped part of the code block.');
    }
    /**
     * OpenAI chat completions end with "data: [DONE]", which is not JSON. It
     * must be swallowed quietly, and must not poison the next event.
     */
    public function test_done_sentinel_is_ignored_and_does_not_block_later_events(): void
    {
        $event = fn(string $t) => 'data: ' . json_encode([
            'choices' => [['delta' => ['content' => $t], 'finish_reason' => null]],
        ], JSON_UNESCAPED_SLASHES) . "\n\n";

        $received = $this->collect(
            [$event('a'), "data: [DONE]\n\n", $event('b')],
            [self::class, 'openAiChatText']
        );

        $this->assertSame('ab', $received);
    }

    /**
     * SSE over CRLF, an escaped quote and a backslash inside the content, and
     * the whole thing delivered one byte per curl write - the worst case for
     * any reassembly strategy.
     */
    public function test_crlf_stream_with_escaped_quotes_delivered_byte_by_byte(): void
    {
        $text = "say \"hi\" \\ done {}";
        $event = "event: content_block_delta\r\n"
            . 'data: ' . json_encode([
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => $text],
            ], JSON_UNESCAPED_SLASHES) . "\r\n\r\n";

        $received = $this->collect(str_split($event, 1), [self::class, 'anthropicText']);

        $this->assertSame($text, $received);
    }
    /**
     * Gemini as it streams since KI-735, with alt=sse: one object per "data:"
     * line, CRLF-terminated, no "event:" line.
     */
    public function test_google_sse_stream_with_alt_sse_keeps_braced_content(): void
    {
        $latex = "\\acro{A}{adenosine}\n\\acro{C}{cytidine}\n";
        $writes = array_map(
            fn($d) => 'data: ' . json_encode([
                'candidates' => [[
                    'content' => ['parts' => [['text' => $d]], 'role' => 'model'],
                ]],
            ], JSON_UNESCAPED_SLASHES) . "\r\n\r\n",
            str_split($latex, 5)
        );

        $received = $this->collect($writes, [self::class, 'googleText']);

        $this->assertSame($latex, $received);
    }
}
