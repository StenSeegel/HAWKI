<?php

namespace App\Jobs\Transcription;

use App\Models\Transcription\Transcription;
use App\Services\AI\AiService;
use App\Services\AI\Config\AiConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateTranscriptionSubtitle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $transcription;

    /**
     * Create a new job instance.
     */
    public function __construct(Transcription $transcription)
    {
        $this->transcription = $transcription;
        $this->queue = 'default'; // Use default queue
    }

    /**
     * Execute the job.
     *
     * Generates the subtitle shown under the transcript title from the first
     * part of the transcript. The subtitle has no own column, it lives in
     * metadata['subtitle']; metadata['subtitle_source'] remembers whether the
     * value came from the model or from a manual edit.
     */
    public function handle(AiService $aiService, AiConfigService $aiConfigService): void
    {
        try {
            $metadata = $this->transcription->metadata ?? [];

            // A subtitle set by the user always wins, even a deliberately empty one.
            if (($metadata['subtitle_source'] ?? null) === 'user' || ! empty($metadata['subtitle'])) {
                Log::info('Transcription already has a subtitle, skipping generation', [
                    'transcription_id' => $this->transcription->id,
                ]);

                return;
            }

            $segments = $this->transcription->textData?->segments ?? [];
            if (empty($segments)) {
                $this->transcription->load('textData');
                $segments = $this->transcription->textData?->segments ?? [];
            }

            $sample = $this->getTranscriptHead(is_array($segments) ? $segments : []);
            if ($sample === '') {
                // No segment array available: fall back to the raw text with the
                // same character budget (see getTranscriptHead()).
                $plainText = trim(preg_replace('/\s+/', ' ', (string) ($this->transcription->textData?->resolvedTranscriptText() ?? '')) ?? '');
                $sample = mb_substr($plainText, 0, 200 * 4);
            }

            if ($sample === '') {
                Log::warning('No transcription text found, skipping subtitle generation', [
                    'transcription_id' => $this->transcription->id,
                ]);

                return;
            }

            $defaultModels = $aiConfigService->getDefaultModels();
            $model = $defaultModels['default_model'] ?? null;

            if (empty($model)) {
                throw new \RuntimeException('No default text model configured.');
            }

            $requestData = [
                'model' => $model,
                'stream' => false,
                'max_tokens' => 60,
                // Same family as the title: a utility request, sent without
                // tools so no awareness prompt lands in the 60 tokens the
                // subtitle has. AiService takes this key off the payload again.
                'assistantKey' => 'title_generator',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => ['text' => 'Du formulierst eine einzelne, sehr kurze Unterzeile für ein Besprechungstranskript. Antworte ausschließlich mit dieser einen Zeile, in der Sprache des Transkripts, maximal 80 Zeichen, ohne Anführungszeichen, ohne Satzzeichen am Ende, ohne Markdown und ohne Einleitung.'],
                    ],
                    [
                        'role' => 'user',
                        'content' => ['text' => "Worum geht es in dieser Aufnahme? Formuliere eine sachliche Unterzeile, die das Thema benennt.\n\nTRANSKRIPT-ANFANG:\n".$sample],
                    ],
                ],
            ];

            $subtitle = $this->sanitizeGeneratedSubtitle($this->extractAiText($aiService->sendRequest($requestData)));

            if ($subtitle === null) {
                // No placeholder is written to the database, the UI already
                // shows one when the subtitle is missing.
                Log::warning('Subtitle generation returned unusable output', [
                    'transcription_id' => $this->transcription->id,
                ]);

                return;
            }

            // The user may have edited the subtitle while the model was running,
            // in that case the manual input stays.
            $this->transcription->refresh();
            $metadata = $this->transcription->metadata ?? [];
            if (($metadata['subtitle_source'] ?? null) === 'user' || ! empty($metadata['subtitle'])) {
                Log::info('Subtitle was set while generating, keeping the existing value', [
                    'transcription_id' => $this->transcription->id,
                ]);

                return;
            }

            $metadata['subtitle'] = $subtitle;
            $metadata['subtitle_source'] = 'ai';
            $this->transcription->metadata = $metadata;
            $this->transcription->save();

            Log::info('Transcription subtitle generated successfully', [
                'transcription_id' => $this->transcription->id,
                'model' => $model,
                'subtitle' => $subtitle,
            ]);
        } catch (\Exception $e) {
            // Fail soft: without a subtitle the UI keeps its placeholder.
            Log::error('Error generating transcription subtitle', [
                'transcription_id' => $this->transcription->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Returns only the beginning of a transcript as prompt context.
     *
     * There is no tokenizer in this project, so 4 characters per token are
     * assumed (rough average of common BPE tokenizers for German/English text).
     * 200 tokens therefore correspond to 800 characters.
     */
    protected function getTranscriptHead(array $segments, int $maxTokens = 200): string
    {
        $maxChars = $maxTokens * 4;

        $head = '';
        foreach ($segments as $segment) {
            $speaker = $segment['speaker'] ?? 'Unbekannt';
            $text = trim((string) ($segment['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $head .= "{$speaker}: {$text}\n";
            if (mb_strlen($head) >= $maxChars) {
                break;
            }
        }

        return trim(mb_substr($head, 0, $maxChars));
    }

    /**
     * Reads the text out of an AiService response (AiResponse object or array).
     */
    protected function extractAiText($response): ?string
    {
        if (is_object($response) && isset($response->content)) {
            $content = $response->content;

            if (is_array($content)) {
                if (isset($content['text'])) {
                    return trim((string) $content['text']);
                }

                $first = $content[0] ?? null;
                if (is_array($first)) {
                    if (isset($first['text'])) {
                        return trim((string) $first['text']);
                    }
                    if (isset($first['content'])) {
                        return trim((string) $first['content']);
                    }
                }
                if (is_string($first)) {
                    return trim($first);
                }
            } elseif (is_string($content)) {
                return trim($content);
            }
        }

        if (is_array($response)) {
            if (isset($response['choices'][0]['message']['content'])) {
                $content = $response['choices'][0]['message']['content'];
                if (is_array($content) && isset($content['text'])) {
                    return trim((string) $content['text']);
                }
                if (is_string($content)) {
                    return trim($content);
                }
            }
            if (isset($response['text'])) {
                return trim((string) $response['text']);
            }
        }

        return null;
    }

    /**
     * Normalizes the model output into a single-line subtitle.
     * Returns null if the output is unusable.
     */
    protected function sanitizeGeneratedSubtitle(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        // Find the first usable line: models occasionally return an
        // introduction ("Hier die Unterzeile:") or several suggestions. Lines
        // ending in a colon are therefore skipped as long as another line follows.
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                $lines[] = $candidate;
            }
        }

        $line = '';
        foreach ($lines as $candidate) {
            $line = $candidate;
            if (! str_ends_with($candidate, ':')) {
                break;
            }
        }

        // Remove markdown, wrapping characters and trailing punctuation.
        $line = (string) preg_replace('/[*_`#>]+/u', '', $line);
        $line = (string) preg_replace('/\s+/u', ' ', $line);
        $line = (string) preg_replace('/^[\s"\'«»„“”‚‘’:\-–—]+|[\s"\'«»„“”‚‘’:\-–—]+$/u', '', $line);
        $line = (string) preg_replace('/[\s.,;:!]+$/u', '', $line);

        if ($line === '' || mb_strlen($line) > 200 || str_starts_with(strtoupper($line), 'INTERNAL ERROR:')) {
            return null;
        }

        // The target is 80 characters at most; cut longer output at a word boundary.
        if (mb_strlen($line) > 80) {
            $line = mb_substr($line, 0, 80);
            $lastSpace = mb_strrpos($line, ' ');
            if ($lastSpace !== false && $lastSpace > 40) {
                $line = mb_substr($line, 0, $lastSpace);
            }
            $line = (string) preg_replace('/[\s.,;:!\-–—]+$/u', '', $line);
        }

        return $line !== '' ? $line : null;
    }
}
