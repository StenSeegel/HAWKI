<?php

namespace App\Jobs;

use App\Models\Transcription;
use App\Services\AI\AiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateTranscriptionTitle implements ShouldQueue
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
     * Generates a title for the transcription using AI.
     * Uses the same "Name Prompt" system as AI conversations.
     */
    public function handle(AiService $aiService): void
    {
        try {
            // If title already exists, skip
            if (! empty($this->transcription->title)) {
                Log::info('Transcription already has a title, skipping generation', [
                    'transcription_id' => $this->transcription->id,
                ]);

                return;
            }

            // Truncate text to prevent long processing (max 500 chars like in AI conversations)
            $text = (string) ($this->transcription->textData?->resolvedTranscriptText() ?? '');
            if ($text === '') {
                $this->transcription->load('textData');
                $text = (string) ($this->transcription->textData?->resolvedTranscriptText() ?? '');
            }

            $normalizedText = trim(preg_replace('/\s+/', ' ', $text) ?? '');

            if ($normalizedText === '') {
                Log::warning('No transcription text found, using fallback title', [
                    'transcription_id' => $this->transcription->id,
                ]);
                $fallbackTitle = 'Transkription '.$this->transcription->created_at->format('d.m.Y H:i');
                $this->transcription->update(['title' => $fallbackTitle]);

                return;
            }
            $truncatedText = mb_strlen($normalizedText) > 500
                ? mb_substr($normalizedText, 0, 500).'...'
                : $normalizedText;

            // Get the title generator model from database (AiAssistant)
            $titleGeneratorModel = null;
            $assistant = \App\Models\AiAssistant::where('key', 'title_generator')->first();

            if ($assistant && $assistant->ai_model) {
                // The ai_model column in AiAssistant contains the system_id, so we need to resolve it
                $model = \App\Models\AiModel::where('system_id', $assistant->ai_model)->first();
                if ($model && $model->is_active) {
                    $titleGeneratorModel = $model->model_id;
                }
            }

            if (empty($titleGeneratorModel)) {
                throw new \RuntimeException('Kein aktives title_generator Modell konfiguriert.');
            }

            // Get the Name Prompt (same as used for AI conversations)
            $namePrompt = $this->getNamePrompt();

            // Build the AI request
            $requestData = [
                'model' => $titleGeneratorModel,
                'stream' => false, // Non-streaming for title generation
                'max_tokens' => 10, // Limit to ~3-5 words
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => ['text' => $namePrompt],
                    ],
                    [
                        'role' => 'user',
                        'content' => ['text' => $truncatedText],
                    ],
                ],
            ];

            // Send the request via AiService
            $response = $aiService->sendRequest($requestData);

            // Extract the generated title
            $generatedTitle = $this->extractTitle($response);

            if ($this->isValidGeneratedTitle($generatedTitle)) {
                // Update transcription with generated title
                $this->transcription->update(['title' => $generatedTitle]);

                Log::info('Transcription title generated successfully', [
                    'transcription_id' => $this->transcription->id,
                    'title' => $generatedTitle,
                ]);
            } else {
                // Fallback: Use first 50 chars of transcription as title
                $fallbackTitle = mb_substr($normalizedText, 0, 50).'...';
                $this->transcription->update(['title' => $fallbackTitle]);

                Log::warning('Failed to generate title, using fallback', [
                    'transcription_id' => $this->transcription->id,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error generating transcription title', [
                'transcription_id' => $this->transcription->id,
                'error' => $e->getMessage(),
            ]);

            // Fallback title on error
            $fallbackTitle = 'Transkription '.$this->transcription->created_at->format('d.m.Y H:i');
            $this->transcription->update(['title' => $fallbackTitle]);
        }
    }

    /**
     * Get the Name Prompt from database (same as AI conversations)
     * Falls back to a hardcoded prompt if not found
     */
    protected function getNamePrompt(): string
    {
        try {
            // Use the stored user locale (captured at request time, not queue time)
            $locale = $this->transcription->user_locale ?? app()->getLocale();

            // Try to get the prompt from database
            $prompt = \App\Models\AiAssistantPrompt::where('title', 'Name Prompt')
                ->where('language', $locale)
                ->first();

            if ($prompt) {
                return $prompt->content;
            }

            // Fallback to English version
            $prompt = \App\Models\AiAssistantPrompt::where('title', 'Name Prompt')
                ->where('language', 'en_US')
                ->first();

            if ($prompt) {
                return $prompt->content;
            }
        } catch (\Exception $e) {
            Log::warning('Could not load Name Prompt from database: '.$e->getMessage());
        }

        // Ultimate fallback: hardcoded prompt
        return 'You are an assistant who assigns a three-word title to the message you receive. You only respond with the name. The naming accurately describes the message.';
    }

    /**
     * Extract title from AI response
     */
    protected function extractTitle($response): ?string
    {
        try {
            // AiService returns AiResponse object with content property
            if (is_object($response) && isset($response->content)) {
                $contentArray = $response->content;

                // Content can be either:
                // 1. Direct associative array: ['text' => 'Title']
                // 2. Array of content parts: [['text' => 'Title']]
                if (is_array($contentArray) && ! empty($contentArray)) {

                    // Case 1: Direct text field
                    if (isset($contentArray['text'])) {
                        return trim($contentArray['text']);
                    }

                    // Case 2: Array of content parts
                    $firstContent = $contentArray[0] ?? null;

                    if (is_array($firstContent)) {
                        if (isset($firstContent['text'])) {
                            return trim($firstContent['text']);
                        }
                        if (isset($firstContent['content'])) {
                            return trim($firstContent['content']);
                        }
                    }

                    if (is_string($firstContent)) {
                        return trim($firstContent);
                    }
                }
            }

            // Fallback: Handle old array format (if ever needed)
            if (is_array($response)) {
                if (isset($response['choices'][0]['message']['content'])) {
                    $content = $response['choices'][0]['message']['content'];

                    if (is_array($content) && isset($content['text'])) {
                        return trim($content['text']);
                    }

                    if (is_string($content)) {
                        return trim($content);
                    }
                }

                if (isset($response['text'])) {
                    return trim($response['text']);
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error extracting title from response', [
                'error' => $e->getMessage(),
                'response_type' => gettype($response),
                'response_dump' => json_encode($response),
            ]);

            return null;
        }
    }

    protected function isValidGeneratedTitle(?string $title): bool
    {
        if (empty($title)) {
            return false;
        }

        $normalized = trim($title);
        if ($normalized === '') {
            return false;
        }

        return ! str_starts_with(strtoupper($normalized), 'INTERNAL ERROR:');
    }
}
