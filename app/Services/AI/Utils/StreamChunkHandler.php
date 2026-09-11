<?php
declare(strict_types=1);


namespace App\Services\AI\Utils;


/**
 * Turns the byte stream curl hands over into whole JSON events for the
 * provider parser.
 *
 * curl calls its write function with whatever bytes have arrived, which is
 * rarely a whole event: one event may straddle two writes, several may share
 * one. Providers also differ in framing. OpenAI chat completions, Anthropic
 * and the OpenAI Responses API speak SSE ("event:" and "data:" lines, blank
 * line between events); Gemini without alt=sse streams a bare, pretty-printed
 * JSON array. Both shapes may even meet in one stream, so the handler decides
 * per event, by peeking at the next non-blank character in the buffer.
 *
 * The JSON scanner tracks string literals, so a brace or "data:" inside the
 * streamed text - Java, LaTeX, YAML - is content, never structure (KI-735).
 */
class StreamChunkHandler
{
    private string $buffer = '';

    /** "data:" lines of the SSE event in progress, when one line alone was not a whole payload. */
    private string $pendingData = '';

    public function __construct(
        private readonly \Closure $onChunk
    )
    {
    }

    public function handle(string $data): void
    {
        $this->buffer .= $data;
        $this->drain();
    }

    private function drain(): void
    {
        while ($this->buffer !== '') {
            if (connection_aborted()) {
                return;
            }

            // Skip whitespace and the array framing Gemini puts between objects.
            $skip = strspn($this->buffer, " \t\r\n,[]");
            if ($skip === strlen($this->buffer)) {
                // Nothing but framing so far. A blank line ends an SSE event.
                if ($this->pendingData !== '' && str_contains($this->buffer, "\n")) {
                    $this->flushPending();
                }
                $this->buffer = '';
                return;
            }

            if ($this->buffer[$skip] === '{') {
                $end = $this->findObjectEnd($this->buffer, $skip);
                if ($end === null) {
                    return; // the object is still arriving
                }
                $this->emit(substr($this->buffer, $skip, $end - $skip + 1));
                $this->buffer = substr($this->buffer, $end + 1);
                continue;
            }

            // SSE: work line by line, and only on complete lines.
            $newline = strpos($this->buffer, "\n");
            if ($newline === false) {
                return;
            }
            $line = rtrim(substr($this->buffer, 0, $newline), "\r");
            $this->buffer = substr($this->buffer, $newline + 1);
            $this->handleSseLine($line);
        }
    }

    private function handleSseLine(string $line): void
    {
        if (trim($line) === '') {
            $this->flushPending();
            return;
        }

        if (!str_starts_with($line, 'data:')) {
            // "event:", "id:", "retry:", ": comment" - nothing the parser needs.
            return;
        }

        $payload = substr($line, 5);
        if (str_starts_with($payload, ' ')) {
            $payload = substr($payload, 1);
        }

        // Every provider we talk to puts a whole JSON object on one data line.
        if (json_validate($payload)) {
            $this->pendingData = '';
            $this->emit($payload);
            return;
        }

        // Otherwise the spec allows the payload to continue on further data
        // lines, joined by newline, until the blank line.
        $this->pendingData = $this->pendingData === '' ? $payload : $this->pendingData . "\n" . $payload;
    }

    private function flushPending(): void
    {
        if ($this->pendingData === '') {
            return;
        }
        $pending = $this->pendingData;
        $this->pendingData = '';
        // Sentinels such as "[DONE]" are not JSON and end here.
        if (json_validate($pending)) {
            $this->emit($pending);
        }
    }

    private function emit(string $json): void
    {
        if (!json_validate($json)) {
            return;
        }

        if (config('logging.triggers.formatted_stream_chunk')) {
            \Log::info('2. StreamChunkHandler - Valid JSON Chunk', [
                'chunk_size' => strlen($json),
                'chunk_data' => json_decode($json, true)
            ]);
        }

        ($this->onChunk)($json);
    }

    /**
     * Index of the brace closing the object that opens at $start, or null when
     * the buffer does not yet hold the whole object. Braces inside string
     * literals do not count, and neither does an escaped quote end a string.
     */
    private function findObjectEnd(string $buffer, int $start): ?int
    {
        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($buffer);

        for ($i = $start; $i < $length; $i++) {
            $char = $buffer[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
