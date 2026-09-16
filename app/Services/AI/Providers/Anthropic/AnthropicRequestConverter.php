<?php
declare(strict_types=1);

namespace App\Services\AI\Providers\Anthropic;

use App\Models\Attachment;
use App\Services\AI\Utils\MessageAttachmentFinder;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiRequest;
use App\Services\Chat\Attachment\AttachmentService;
use App\Services\Chat\Attachment\DocumentImageService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Log;

#[Singleton]
readonly class AnthropicRequestConverter
{
    public function __construct(
        private MessageAttachmentFinder $attachmentFinder
    )
    {
    }

    public function convertRequestToPayload(AiRequest $request): array
    {
        $rawPayload = $request->payload;
        $model = $request->model;
        $messages = $rawPayload['messages'];
        $modelId = $rawPayload['model'];

        // Load and attach attachment models if any
        $attachmentsMap = $this->attachmentFinder->findAttachmentsOfMessages($messages);

        // Extract system prompt from first message item
        $systemPrompt = null;
        if (isset($messages[0]) && $messages[0]['role'] === 'system') {
            $systemPrompt = $messages[0]['content']['text'] ?? '';
            array_shift($messages);
        }

        // Format messages for Anthropic
        $messages = DocumentImageService::markMessagesWithFigures($messages);
        $formattedMessages = [];
        foreach ($messages as $message) {
            $formattedMessages[] = $this->formatMessage($message, $attachmentsMap, $model);
        }

        // Build payload with common parameters
        $payload = [
            'model' => $modelId,
            'messages' => $formattedMessages,
            'max_tokens' => $rawPayload['max_tokens'] ?? 4096,
            'stream' => $rawPayload['stream'] && $model->hasTool('stream'),
        ];

        // Add system prompt if present
        if ($systemPrompt) {
            $payload['system'] = $systemPrompt;
        }

        // Handle web_search tool (Anthropic format)
        $availableTools = $model->getTools();
        
        if (isset($availableTools['web_search']) && $availableTools['web_search'] === true) {
            // Model supports web_search - check if frontend enabled it
            if (isset($rawPayload['tools']['web_search']) && $rawPayload['tools']['web_search'] === true) {
                // Add web_search tool to payload
                $payload['tools'] = [
                    [
                        'type' => 'web_search_20250305',
                        'name' => 'web_search',
                        'max_uses' => 5
                    ]
                ];
            }
        }

        // Add optional parameters if present in the raw payload
        if (isset($rawPayload['temperature'])) {
            $payload['temperature'] = $rawPayload['temperature'];
        }

        if (isset($rawPayload['top_p'])) {
            $payload['top_p'] = $rawPayload['top_p'];
        }

        return $payload;
    }

    private function formatMessage(array $message, array $attachmentsMap, AiModel $model): array
    {
        $content = $message['content'] ?? [];
        $formatted = [
            'role' => $message['role'],
            'content' => []
        ];

        // Add text if present
        if (!empty($content['text'])) {
            $formatted['content'][] = [
                'type' => 'text',
                'text' => $content['text'],
            ];
        }

        // Handle attachments with permission checks
        if (!empty($content['attachments'])) {
            $this->processAttachments($content['attachments'], $attachmentsMap, $model, $formatted['content'], (bool) ($message['include_figures'] ?? false));
        }

        // Anthropic requires content array, but if only text, can be string
        // Keep as array for consistency and future vision support
        return $formatted;
    }

    private function processAttachments(array $attachmentUuids, array $attachmentsMap, AiModel $model, array &$content, bool $includeFigures = false): void
    {
        $attachmentService = app(AttachmentService::class);
        $skippedAttachments = [];

        foreach ($attachmentUuids as $uuid) {
            $attachment = $attachmentsMap[$uuid] ?? null;
            if (!$attachment) {
                continue; // skip invalid
            }

            switch ($attachment->type) {
                case 'image':
                    if ($model->canProcessImage()) {
                        array_push($content, ...$this->processImageAttachment($attachment, $attachmentService));
                    } else {
                        $skippedAttachments[] = $attachment->name . ' (image not supported)';
                    }
                    break;

                case 'document':
                    if ($model->canProcessDocument()) {
                        $content[] = $this->processDocumentAttachment($attachment, $attachmentService);
                        if ($includeFigures && $model->canProcessImage()) {
                            array_push($content, ...$this->processDocumentImages($attachment));
                        }
                    } else {
                        $skippedAttachments[] = $attachment->name . ' (file upload not supported)';
                    }
                    break;

                default:
                    Log::warning('Unknown attachment type: ' . $attachment->type);
                    $skippedAttachments[] = $attachment->name . ' (unsupported type)';
                    break;
            }
        }

        // Notify about skipped attachments
        if (!empty($skippedAttachments)) {
            $content[] = [
                'type' => 'text',
                'text' => '[NOTE: The following attachments were not included because this model does not support them: ' . implode(', ', $skippedAttachments) . ']'
            ];
        }
    }

    /**
     * The parts for one picture: the line naming it - so a model asked which
     * image it got need not answer "the second one" - and the bytes themselves.
     * An SVG carries its source in that line and arrives as a rendered PNG,
     * because a model is served raster bytes only.
     *
     * @return list<array<string,mixed>>
     */
    private function processImageAttachment(Attachment $attachment, AttachmentService $attachmentService): array
    {
        $image = $attachmentService->imagePartsForModel($attachment);

        $parts = [['type' => 'text', 'text' => $image['label']]];

        if ($image['base64'] !== null) {
            // Anthropic uses base64 inline images
            $parts[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $image['mime'],
                    'data' => $image['base64'],
                ],
            ];
        }

        return $parts;
    }

    /**
     * Figures the file converter extracted from the document, for vision models.
     * Returns a note naming the images followed by one image part per figure;
     * empty when the document has no usable images or the feature is off.
     */
    private function processDocumentImages(Attachment $attachment): array
    {
        try {
            $imageService = app(DocumentImageService::class);
            $images = $imageService->collect($attachment);
            if ($images === []) {
                return [];
            }

            $parts = [['type' => 'text', 'text' => $imageService->describe($attachment, $images)]];
            foreach ($images as $image) {
                $parts[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $image['mime'],
                        'data' => base64_encode($image['data']),
                    ],
                ];
            }

            return $parts;
        } catch (\Exception $e) {
            Log::error('Failed to process document images: ' . $e->getMessage());
            return [];
        }
    }

    private function processDocumentAttachment(Attachment $attachment, AttachmentService $attachmentService): array
    {
        try {
            $fileContent = $attachmentService->retrieve($attachment, 'md');
            return [
                'type' => 'text',
                'text' => "[ATTACHED FILE: {$attachment->name}]\n---\n{$fileContent}\n---"
            ];
        } catch (\Exception $e) {
            Log::error('Failed to process document attachment: ' . $e->getMessage());
            return [
                'type' => 'text',
                'text' => '[ERROR: Could not process document attachment: ' . $attachment->name . ']'
            ];
        }
    }
}
