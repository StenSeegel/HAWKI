<?php

namespace App\Services\AI\Providers\Responses;

use App\Models\Attachment;
use App\Services\AI\Utils\MessageAttachmentFinder;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiRequest;
use App\Services\Chat\Attachment\AttachmentService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Log;

#[Singleton]
readonly class ResponsesRequestConverter
{
    public function __construct(
        private MessageAttachmentFinder $attachmentFinder
    )
    {
    }

    /**
     * Convert HAWKI internal request to Responses API payload
     */
    public function convertRequestToPayload(AiRequest $request): array
    {
        $rawPayload = $request->payload;
        $model = $request->model;
        $messages = $rawPayload['messages'];
        $modelId = $rawPayload['model'];

        // Map messages and separate instructions from input
        $mappedMessages = $this->mapMessages($messages);

        // Extract previous_response_id from last assistant message's auxiliaries
        $previousResponseId = $this->extractPreviousResponseId($mappedMessages);

        // Load the attachment models referenced by the messages. Generated images
        // travel as ordinary attachments, so nothing special is needed here.
        $attachmentsMap = $this->attachmentFinder->findAttachmentsOfMessages($messages);

        // Extract instructions (developer/system messages) and input (conversation)
        [$instructions, $input] = $this->separateInstructionsAndInput($mappedMessages, $attachmentsMap, $model);

        // Build base payload
        $payload = [
            'model' => $modelId,
            'input' => $input,
            'store' => false, // Privacy: don't store conversations
        ];

        // Add instructions if present
        if ($instructions !== null) {
            $payload['instructions'] = $instructions;
        }

        // Get available tools from model (used for reasoning and web_search)
        $availableTools = $model->getTools();

        // Add reasoning configuration if:
        // 1. Model supports reasoning
        // 2. User explicitly requested reasoning via reasoning_effort
        if (isset($availableTools['reasoning']) && $availableTools['reasoning'] === true) {
            $reasoningEffort = $this->getReasoningEffort($modelId, $rawPayload);

            // Only add reasoning if explicitly requested
            if ($reasoningEffort !== null) {
                $payload['reasoning'] = [
                    'effort' => $reasoningEffort,
                    'summary' => 'auto', // Enable reasoning summaries
                ];
            }
        }

        // Add text format for structured outputs if specified
        if (isset($rawPayload['response_format'])) {
            $payload['text'] = [
                'format' => $rawPayload['response_format'],
            ];
        }

        // Add previous_response_id for multi-turn conversations
        // Priority: 1) Extracted from auxiliaries, 2) Explicitly provided in rawPayload
        if ($previousResponseId) {
            $payload['previous_response_id'] = $previousResponseId;
        } elseif (isset($rawPayload['previous_response_id'])) {
            $payload['previous_response_id'] = $rawPayload['previous_response_id'];
        }

        // Handle web_search tool (following GoogleRequestConverter pattern)
        // Check if model supports web_search AND frontend has enabled it
        if (isset($availableTools['web_search']) && $availableTools['web_search'] === true) {
            // Model supports web_search - check if frontend enabled it
            if (isset($rawPayload['tools']['web_search']) && $rawPayload['tools']['web_search'] === true) {
                // Add web_search tool to payload
                if (!isset($payload['tools'])) {
                    $payload['tools'] = [];
                }
                $payload['tools'][] = ['type' => 'web_search'];
            }
        }

        // Handle image_generation tool
        // Check if model supports image output AND frontend has enabled it
        if (isset($availableTools['image_gen']) && $availableTools['image_gen'] === true) {
            // Model supports image generation - check if frontend enabled it
            if (isset($rawPayload['tools']['image_generation']) && $rawPayload['tools']['image_generation'] === true) {
                // Add image_generation tool to payload
                if (!isset($payload['tools'])) {
                    $payload['tools'] = [];
                }
                $selectedImageSize = $this->getSelectedImageGenerationSize($rawPayload);
                $selectedRatio = $this->getSelectedImageGenerationRatio($rawPayload);
                // The S/M/L preset sets the base resolution, the ratio the shape.
                $imageSize = $this->getImageGenerationApiSize($selectedImageSize, $selectedRatio);

                // Internal-only fields used after generation to resize persisted files to UI-selected dimensions.
                $payload['_hawki_image_generation_size'] = $selectedImageSize;
                if ($selectedRatio !== null) {
                    $payload['_hawki_image_generation_ratio'] = $selectedRatio;
                }
                $payload['tools'][] = ['type' => 'image_generation', 'partial_images' => 0, 'size' => $imageSize, 'quality' => 'low'];
            }
        }

        // Optional parameters
        if (isset($rawPayload['temperature'])) {
            $payload['temperature'] = $rawPayload['temperature'];
        }

        if (isset($rawPayload['top_p'])) {
            $payload['top_p'] = $rawPayload['top_p'];
        }

        return $payload;
    }

    /**
     * Map messages for Responses API format
     * Converts 'system' role to 'developer' and handles auxiliaries
     */
    private function mapMessages(array $messages): array
    {
        $mapped = [];

        foreach ($messages as $message) {
            $role = $message['role'];

            // Responses API uses 'developer' instead of 'system'
            if ($role === 'system') {
                $role = 'developer';
            }

            $content = $message['content'] ?? [];
            $contentText = is_array($content) ? ($content['text'] ?? '') : $content;

            $mappedMessage = [
                'role' => $role,
                'content' => $contentText,
                'attachments' => is_array($content) && is_array($content['attachments'] ?? null)
                    ? $content['attachments']
                    : [],
            ];

            // Handle auxiliaries from content (client-side encrypted, now decrypted)
            // Similar to how Google handles groundingMetadata
            if (is_array($content) && isset($content['auxiliaries']) && !empty($content['auxiliaries'])) {
                $mappedMessage['auxiliaries'] = $content['auxiliaries'];
            }

            $mapped[] = $mappedMessage;
        }

        return $mapped;
    }

    /**
     * Separate instructions (developer messages) from input (conversation)
     * Returns [instructions, input]
     */
    private function separateInstructionsAndInput(array $mappedMessages, array $attachmentsMap, AiModel $model): array
    {
        $instructions = null;
        $input = [];

        foreach ($mappedMessages as $message) {
            // Developer messages become instructions
            if ($message['role'] === 'developer') {
                if ($instructions === null) {
                    $instructions = $message['content'];
                } else {
                    $instructions .= "\n\n" . $message['content'];
                }
                continue;
            }

            // All other messages go into input
            $inputMessage = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];

            // Attachments are turned into content parts. The Responses API only
            // accepts output_text under the assistant role, so they are limited
            // to the other roles.
            if ($message['role'] !== 'assistant' && !empty($message['attachments'])) {
                $parts = [];

                if ($message['content'] !== '' && $message['content'] !== null) {
                    $parts[] = [
                        'type' => 'input_text',
                        'text' => $message['content'],
                    ];
                }

                $this->processAttachments($message['attachments'], $attachmentsMap, $model, $parts);

                if (!empty($parts)) {
                    $inputMessage['content'] = $parts;
                }
            }

            // Include auxiliaries (e.g., reasoning from previous responses)
            if (isset($message['auxiliaries'])) {
                foreach ($message['auxiliaries'] as $auxiliary) {
                    if ($auxiliary['type'] === 'responsesReasoning') {
                        // Extract reasoning items from previous responses
                        $reasoningData = json_decode($auxiliary['content'], true);
                        if (isset($reasoningData['reasoning'])) {
                            foreach ($reasoningData['reasoning'] as $reasoningItem) {
                                $input[] = $reasoningItem;
                            }
                        }
                    }
                }
            }

            $input[] = $inputMessage;
        }

        // If only one user message and no auxiliaries, use string format for simplicity
        // Content parts have to stay in item form - a bare list of parts is not
        // a valid input array.
        if (count($input) === 1
            && $input[0]['role'] === 'user'
            && is_string($input[0]['content'])
            && !isset($input[0]['auxiliaries'])) {
            $input = $input[0]['content'];
        }

        return [$instructions, $input];
    }

    /**
     * Append the attachments as Responses API content parts, skipping the ones the
     * model cannot handle.
     */
    private function processAttachments(array $attachmentUuids, array $attachmentsMap, AiModel $model, array &$content): void
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
                        $content[] = $this->processImageAttachment($attachment, $attachmentService);
                    } else {
                        $skippedAttachments[] = $attachment->name . ' (image not supported)';
                    }
                    break;

                case 'document':
                    if ($model->canProcessDocument()) {
                        $content[] = $this->processDocumentAttachment($attachment, $attachmentService);
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
                'type' => 'input_text',
                'text' => '[NOTE: The following attachments were not included because this model does not support them: ' . implode(', ', $skippedAttachments) . ']'
            ];
        }
    }

    private function processImageAttachment(Attachment $attachment, AttachmentService $attachmentService): array
    {
        try {
            $file = $attachmentService->retrieve($attachment);
            $imageData = base64_encode($file);

            // The Responses API takes image_url as a plain string, unlike the
            // object shape the chat completions API expects.
            return [
                'type' => 'input_image',
                'image_url' => "data:{$attachment->mime};base64,{$imageData}",
            ];
        } catch (\Exception $e) {
            Log::error('Failed to process image attachment: ' . $e->getMessage());

            return [
                'type' => 'input_text',
                'text' => '[ERROR: Could not process image attachment: ' . $attachment->name . ']'
            ];
        }
    }

    private function processDocumentAttachment(Attachment $attachment, AttachmentService $attachmentService): array
    {
        try {
            $fileContent = $attachmentService->retrieve($attachment, 'md');
            $html_safe = htmlspecialchars($fileContent, ENT_QUOTES, 'UTF-8');

            return [
                'type' => 'input_text',
                'text' => "[ATTACHED FILE: {$attachment->name}]\n---\n{$html_safe}\n---"
            ];
        } catch (\Exception $e) {
            Log::error('Failed to process document attachment: ' . $e->getMessage());

            return [
                'type' => 'input_text',
                'text' => '[ERROR: Could not process document attachment: ' . $attachment->name . ']'
            ];
        }
    }

    /**
     * Get reasoning effort level based on model and payload
     * Only use reasoning if explicitly enabled via payload
     */
    private function getReasoningEffort(string $modelId, array $rawPayload): ?string
    {
        // Check if reasoning effort is specified in payload
        if (isset($rawPayload['reasoning_effort'])) {
            return $rawPayload['reasoning_effort'];
        }

        // No reasoning if not explicitly requested
        return null;
    }

    /**
     * Normalize UI image size selection.
     */
    private function getSelectedImageGenerationSize(array $rawPayload): string
    {
        $selectedSize = strtolower((string)($rawPayload['image_generation_size'] ?? 'medium'));

        return match ($selectedSize) {
            'small', 'medium', 'big' => $selectedSize,
            default => 'medium',
        };
    }

    /**
     * The requested aspect ratio as "w:h", or null when none was selected.
     */
    private function getSelectedImageGenerationRatio(array $rawPayload): ?string
    {
        $ratio = trim((string)($rawPayload['image_generation_ratio'] ?? ''));

        return preg_match('/^\d{1,2}:\d{1,2}$/', $ratio) === 1 ? $ratio : null;
    }

    /**
     * Responses API supports only a limited set of image sizes, so the ratio can
     * only pick the orientation here. The exact dimensions are applied to the
     * generated file afterwards, from the S/M/L preset and the same ratio.
     */
    private function getImageGenerationApiSize(string $selectedSize, ?string $selectedRatio): string
    {
        if ($selectedRatio === null) {
            // No ratio picked, so the preset alone decides as it always has.
            return match ($selectedSize) {
                'big' => '1536x1024',
                default => '1024x1024',
            };
        }

        [$width, $height] = array_map('intval', explode(':', $selectedRatio));

        if ($width === 0 || $height === 0 || $width === $height) {
            return '1024x1024';
        }

        return $width > $height ? '1536x1024' : '1024x1536';
    }

    /**
     * Extract previous_response_id from the last assistant message's auxiliaries
     * This enables conversation continuity across multiple turns
     *
     * Note: auxiliaries are stored at message level after mapMessages() processing
     */
    private function extractPreviousResponseId(array $mappedMessages): ?string
    {
        // Search backwards through messages for the last assistant message
        for ($i = count($mappedMessages) - 1; $i >= 0; $i--) {
            $message = $mappedMessages[$i];

            if ($message['role'] !== 'assistant') {
                continue;
            }

            // Auxiliaries are at message level (added by mapMessages)
            if (!isset($message['auxiliaries']) || !is_array($message['auxiliaries'])) {
                continue;
            }

            foreach ($message['auxiliaries'] as $auxiliary) {
                if (($auxiliary['type'] ?? '') === 'responsesMetadata') {
                    $metadata = json_decode($auxiliary['content'], true);
                    if (isset($metadata['response_id'])) {
                        return $metadata['response_id'];
                    }
                }
            }
        }

        return null;
    }
}
