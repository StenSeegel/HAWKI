<?php
declare(strict_types=1);

namespace App\Services\AI\Providers\Responses\Request;

use App\Services\AI\Providers\AbstractRequest;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiResponse;

class ResponsesStreamingRequest extends AbstractRequest
{
    use ResponsesUsageTrait;

    private array $reasoningItems = [];
    private array $citations = [];

    public function __construct(
        private array    $payload,
        private \Closure $onData
    )
    {
    }

    public function execute(AiModel $model): void
    {
        $this->payload['stream'] = true;

        $this->executeStreamingRequest(
            model: $model,
            payload: $this->payload,
            onData: $this->onData,
            chunkToResponse: [$this, 'chunkToResponse']
        );
    }

    /**
     * Convert streaming chunk to AiResponse
     * Handles all Responses API event types
     */
    protected function chunkToResponse(AiModel $model, string $chunk): AiResponse
    {
        $jsonChunk = json_decode($chunk, true, 512, JSON_THROW_ON_ERROR);

        if (!$jsonChunk) {
            return $this->createErrorResponse('Invalid JSON chunk received.');
        }

        // Handle errors
        if (isset($jsonChunk['error'])) {
            $errorMessage = $jsonChunk['error']['message'] ?? 'Unknown error';
            
            // Log critical error for previous_response_id issues (known OpenAI Beta limitation)
            //if (str_contains($errorMessage, 'Previous response') && str_contains($errorMessage, 'not found')) {
            //    \Log::warning('Responses API: previous_response_id not found', [
            //        'error' => $errorMessage
            //    ]);
            //}
            
            return $this->createErrorResponse($errorMessage);
        }

        $type = $jsonChunk['type'] ?? '';
        
        $content = '';
        $isDone = false;
        $usage = null;
        $auxiliaries = [];

        switch ($type) {
            // Main streaming text chunks
            case 'response.output_text.delta':
                $content = $jsonChunk['delta'] ?? '';
                break;

            // Complete text output - DON'T send content again (causes duplicates)
            // The text was already sent via delta events
            case 'response.output_text.done':
                // Just a completion signal, no content to send
                break;

            // Progress status - metadata event (no user-facing status needed)
            case 'response.in_progress':
                // No status update needed - actual status comes from reasoning/web_search events
                break;

            // Reasoning chunks (streaming)
            case 'response.reasoning.delta':
                $this->handleReasoningDelta($jsonChunk);
                // Send status update
                $auxiliaries[] = [
                    'type' => 'status',
                    'content' => json_encode([
                        'status' => 'reasoning',
                        'message' => 'Model is reasoning...'
                    ])
                ];
                break;

            // Complete reasoning output
            case 'response.reasoning.done':
                $this->handleReasoningDone($jsonChunk);
                break;

            // MCP tool call initiated
            case 'response.mcp_call_tool':
                // Tool calls are handled internally by OpenAI
                // We just log for debugging if needed
                break;

            // Web search call initiated
            case 'response.web_search_call':
                // Extract web search metadata and send status
                $this->handleWebSearchCall($jsonChunk);
                $auxiliaries[] = [
                    'type' => 'status',
                    'content' => json_encode([
                        'status' => 'web_search',
                        'message' => 'Searching the web...'
                    ])
                ];
                $content = ''; // Ensure message element is created/updated
                break;

            // Web search in progress
            case 'response.web_search_call.searching':
                \Log::info('[RESPONSES] Event Type: response.web_search_call.searching');
                $auxiliaries[] = [
                    'type' => 'status',
                    'content' => json_encode([
                        'status' => 'web_search',
                        'message' => 'Searching the web...'
                    ])
                ];
                $content = ''; // Ensure status is sent
                break;

            // Web search completed
            case 'response.web_search_call.completed':
                \Log::info('[RESPONSES] Event Type: response.web_search_call.completed');
                $auxiliaries[] = [
                    'type' => 'status',
                    'content' => json_encode([
                        'status' => 'web_search_complete',
                        'message' => 'Web search completed'
                    ])
                ];
                $content = ''; // Ensure status is sent
                break;

            // Web search in progress (metadata event)
            case 'response.web_search_call.in_progress':
                \Log::info('[RESPONSES] Event Type: response.web_search_call.in_progress');
                $auxiliaries[] = [
                    'type' => 'status',
                    'content' => json_encode([
                        'status' => 'web_search',
                        'message' => 'Searching the web...'
                    ])
                ];
                $content = ''; // Ensure status is sent
                break;

            // Response completed with final data
            case 'response.completed':
                \Log::info('[RESPONSES] Event Type: response.completed');
                $isDone = true;
                
                // Extract usage from final response
                if (!empty($jsonChunk['response']['usage'])) {
                    $usage = $this->extractUsage($model, $jsonChunk['response']);
                }

                // Extract response ID for multi-turn conversation continuity
                $responseId = $jsonChunk['response']['id'] ?? null;
                if ($responseId) {
                    $auxiliaries[] = [
                        'type' => 'responsesMetadata',
                        'content' => json_encode([
                            'response_id' => $responseId
                        ])
                    ];
                }

                // Include reasoning items as auxiliaries
                if (!empty($this->reasoningItems)) {
                    $auxiliaries[] = [
                        'type' => 'responsesReasoning',
                        'content' => json_encode([
                            'reasoning' => $this->reasoningItems
                        ])
                    ];
                }

                // Include citations as auxiliaries
                if (!empty($this->citations)) {
                    $auxiliaries[] = [
                        'type' => 'responsesCitations',
                        'content' => json_encode([
                            'citations' => $this->citations
                        ])
                    ];
                }
                break;

            // Response failed
            case 'response.failed':
                $error = $jsonChunk['error'] ?? $jsonChunk['response']['error'] ?? [];
                $errorMessage = $error['message'] ?? 'Response failed';
                return $this->createErrorResponse($errorMessage);

            // Output item done - may contain citations/annotations
            case 'response.output_item.done':
                $this->handleOutputItemDone($jsonChunk);
                
                // Check if this is a reasoning item completion
                $item = $jsonChunk['item'] ?? [];
                $itemType = $item['type'] ?? null;
                
                if ($itemType === 'reasoning') {
                    // Reasoning completed - send status update
                    \Log::info('[RESPONSES] Event Type: response.output_item.done (reasoning)');
                    $auxiliaries[] = [
                        'type' => 'status',
                        'content' => json_encode([
                            'status' => 'reasoning_complete',
                            'message' => 'Reasoning completed'
                        ])
                    ];
                    $content = '';
                } elseif ($itemType === 'web_search_call') {
                    // Web search completed - extract query and send status
                    $action = $item['action'] ?? [];
                    $query = $action['query'] ?? null;
                    
                    \Log::info('[RESPONSES] Event Type: response.output_item.done (web_search_call)', [
                        'query' => $query
                    ]);
                    
                    $auxiliaries[] = [
                        'type' => 'status',
                        'content' => json_encode([
                            'status' => 'web_search_complete',
                            'message' => 'Web search completed',
                            'query' => $query
                        ])
                    ];
                    $content = '';
                } else {
                    // Generic output_item.done (e.g., message)
                    \Log::info('[RESPONSES] Event Type: response.output_item.done');
                }
                break;

            // Response created - initial event, send status to create message element
            case 'response.created':
                \Log::info('[RESPONSES] Event Type: response.created');
                // Send backend microtime as auxiliary for lag measurement
                $auxiliaries[] = [
                    'type' => 'debug_timestamp',
                    'content' => json_encode([
                        'backend_microtime' => microtime(true),
                        'backend_timestamp' => now()->toIso8601String()
                    ])
                ];
                // No user-facing status needed - actual status comes from reasoning/web_search events
                break;

            // Output item added - check if it's reasoning or web search
            case 'response.output_item.added':
                $item = $jsonChunk['item'] ?? [];
                $itemType = $item['type'] ?? null;
                
                if ($itemType === 'reasoning') {
                    // Reasoning started - send status update
                    \Log::info('[RESPONSES] Event Type: response.output_item.added (reasoning)');
                    $auxiliaries[] = [
                        'type' => 'status',
                        'content' => json_encode([
                            'status' => 'reasoning',
                            'message' => 'Model is reasoning...'
                        ])
                    ];
                    $content = '';
                } elseif ($itemType === 'web_search_call') {
                    // Web search started - send status update
                    \Log::info('[RESPONSES] Event Type: response.output_item.added (web_search_call)');
                    $auxiliaries[] = [
                        'type' => 'status',
                        'content' => json_encode([
                            'status' => 'web_search',
                            'message' => 'Searching the web...'
                        ])
                    ];
                    $content = '';
                } else {
                    // Generic output_item.added (e.g., message)
                    \Log::info('[RESPONSES] Event Type: response.output_item.added');
                }
                break;

            // Metadata events (no action needed)
            case 'response.content_part.added':
            case 'response.content_part.done':
            case 'response.output_text.annotation.added':
            case 'response.refusal.delta':
            case 'response.refusal.done':
            case 'response.function_call_arguments.delta':
            case 'response.function_call_arguments.done':
            case 'response.file_search_call.in_progress':
            case 'response.file_search_call.searching':
            case 'response.file_search_call.completed':
            case 'response.code_interpreter_call.in_progress':
            case 'response.code_interpreter_call.completed':
            case 'response.code_interpreter_code.delta':
            case 'response.code_interpreter_code.done':
            case 'response.reasoning_summary_part.added':
            case 'response.reasoning_summary_part.done':
            case 'response.reasoning_summary_text.delta':
            case 'response.reasoning_summary_text.done':
            case 'response.reasoning_text.delta':
            case 'response.reasoning_text.done':
            case 'response.mcp_list_tools.in_progress':
            case 'response.mcp_list_tools.completed':
            case 'response.mcp_call.in_progress':
            case 'response.mcp_call.completed':
            case 'response.mcp_call_arguments.delta':
            case 'response.mcp_call.arguments.done':
            case 'response.mcp_call_arguments.done':
            case 'response.image_generation_call.completed':
            case 'response.image_generation_call.generating':
            case 'response.image_generation_call.in_progress':
            case 'response.image_generation_call.partial_image':
            case 'response.incomplete':
            case 'error':
                // Ignore metadata events (status already handled above)
                break;

            default:
                // Unknown event type - log for debugging
                // Note: Don't use Log::debug in production
                break;
        }

        // Skip empty responses for metadata events (prevents duplicate messages)
        // BUT send responses with status auxiliaries (for user feedback)
        //if (empty($content) && !$isDone && empty($auxiliaries)) {
        //    \Log::info('[RESPONSES DEBUG] Skipping empty response (no content, no auxiliaries)');
        //    return new AiResponse(
        //        content: ['text' => ''],
        //        isDone: false
        //    );
        //}

        // Build content array (like Google does with groundingMetadata)
        $responseContent = ['text' => $content];

        // Add auxiliaries to content (will be encrypted client-side with text)
        if (!empty($auxiliaries)) {
            $responseContent['auxiliaries'] = $auxiliaries;
        }

        return new AiResponse(
            content: $responseContent,
            usage: $usage,
            isDone: $isDone
        );
    }

    /**
     * Handle streaming reasoning delta
     */
    private function handleReasoningDelta(array $chunk): void
    {
        // Store reasoning chunk for later assembly
        $itemId = $chunk['item_id'] ?? null;
        $delta = $chunk['delta'] ?? '';

        if ($itemId) {
            if (!isset($this->reasoningItems[$itemId])) {
                $this->reasoningItems[$itemId] = [
                    'id' => $itemId,
                    'type' => 'reasoning',
                    'content' => ''
                ];
            }
            $this->reasoningItems[$itemId]['content'] .= $delta;
        }
    }

    /**
     * Handle complete reasoning output
     */
    private function handleReasoningDone(array $chunk): void
    {
        $itemId = $chunk['item_id'] ?? null;
        $content = $chunk['content'] ?? $chunk['text'] ?? '';

        if ($itemId) {
            $this->reasoningItems[$itemId] = [
                'id' => $itemId,
                'type' => 'reasoning',
                'content' => $content
            ];
        }
    }

    /**
     * Handle web search call event
     * Extracts search metadata for debugging/analytics
     */
    private function handleWebSearchCall(array $chunk): void
    {
        // Extract web search call metadata
        $searchId = $chunk['id'] ?? null;
        $status = $chunk['status'] ?? 'unknown';
        
        // Extract action details if available
        $action = $chunk['action'] ?? [];
        $actionType = $action['type'] ?? null; // 'search', 'open_page', 'find_in_page'
        
        // Store metadata for potential future use
        // For now, we just acknowledge the search call
        // In future: could track search queries, domains, sources for analytics
        
        // Optional: Extract additional details
        // $query = $action['query'] ?? null;
        // $domains = $action['domains'] ?? [];
        // $sources = $action['sources'] ?? [];
    }

    /**
     * Handle output item done event
     * Extracts URL citations from message annotations
     */
    private function handleOutputItemDone(array $chunk): void
    {
        // Check if this is a message output item with content
        $item = $chunk['item'] ?? [];
        if (($item['type'] ?? '') !== 'message') {
            return;
        }

        // Extract content array
        $content = $item['content'] ?? [];
        if (empty($content)) {
            return;
        }

        // Parse each content part for annotations
        foreach ($content as $contentPart) {
            if (($contentPart['type'] ?? '') === 'output_text') {
                $annotations = $contentPart['annotations'] ?? [];
                
                foreach ($annotations as $annotation) {
                    if (($annotation['type'] ?? '') === 'url_citation') {
                        // Store citation for later use
                        $this->citations[] = [
                            'type' => 'url_citation',
                            'url' => $annotation['url'] ?? '',
                            'title' => $annotation['title'] ?? '',
                            'start_index' => $annotation['start_index'] ?? 0,
                            'end_index' => $annotation['end_index'] ?? 0,
                        ];
                    }
                }
            }
        }
    }
}
