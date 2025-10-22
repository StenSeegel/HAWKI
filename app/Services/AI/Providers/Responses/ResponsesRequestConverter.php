<?php

namespace App\Services\AI\Providers\Responses;

use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiRequest;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ResponsesRequestConverter
{
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
        
        // Extract instructions (developer/system messages) and input (conversation)
        [$instructions, $input] = $this->separateInstructionsAndInput($mappedMessages);

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

        // Add reasoning configuration based on model capabilities
        // Check if model has reasoning capability enabled
        $availableTools = $model->getTools();
        if (isset($availableTools['reasoning']) && $availableTools['reasoning'] === true) {
            $reasoningConfig = [
                'effort' => $this->getReasoningEffort($model, $rawPayload),
            ];
            
            // Add reasoning summary if configured
            $summary = $this->getReasoningSummary($model, $rawPayload);
            if ($summary !== 'none') {
                $reasoningConfig['summary'] = $summary;
            }
            
            $payload['reasoning'] = $reasoningConfig;
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
                $payload['tools'] = [
                    ['type' => 'web_search']
                ];
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
    private function separateInstructionsAndInput(array $mappedMessages): array
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
        if (count($input) === 1 && $input[0]['role'] === 'user' && !isset($input[0]['auxiliaries'])) {
            $input = $input[0]['content'];
        }

        return [$instructions, $input];
    }

    /**
     * Get reasoning effort level from model settings or payload
     * Priority: 1) Frontend payload, 2) Model settings, 3) Default (medium)
     * 
     * @param AiModel $model
     * @param array $rawPayload
     * @return string 'low' | 'medium' | 'high'
     */
    private function getReasoningEffort(AiModel $model, array $rawPayload): string
    {
        // Priority 1: Check if reasoning effort is explicitly specified in payload (from frontend)
        if (isset($rawPayload['reasoning_effort'])) {
            return $rawPayload['reasoning_effort'];
        }

        // Priority 2: Use model's configured reasoning_effort from settings
        $config = $model->getProvider()->getConfig();
        $modelConfig = collect($config->getModels())->firstWhere('id', $model->getId());
        
        if (isset($modelConfig['settings']['reasoning_effort'])) {
            return $modelConfig['settings']['reasoning_effort'];
        }

        // Priority 3: Default to 'medium' (recommended by OpenAI)
        return 'medium';
    }

    /**
     * Get reasoning summary setting from model settings or payload
     * Priority: 1) Frontend payload, 2) Model settings, 3) Default (none)
     * 
     * @param AiModel $model
     * @param array $rawPayload
     * @return string 'none' | 'auto' | 'concise' | 'detailed'
     */
    private function getReasoningSummary(AiModel $model, array $rawPayload): string
    {
        // Priority 1: Check if reasoning summary is explicitly specified in payload (from frontend)
        if (isset($rawPayload['reasoning_summary'])) {
            return $rawPayload['reasoning_summary'];
        }

        // Priority 2: Use model's configured reasoning_summary from settings
        $config = $model->getProvider()->getConfig();
        $modelConfig = collect($config->getModels())->firstWhere('id', $model->getId());
        
        if (isset($modelConfig['settings']['reasoning_summary'])) {
            return $modelConfig['settings']['reasoning_summary'];
        }

        // Priority 3: Default to 'none' (no summary by default)
        return 'none';
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
