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
    private string $reasoningSummary = '';
    private array $allReasoningSummaries = [];
    private array $reasoningSummaryTitles = []; // Map output_index => title
    private array $reasoningSummaryContent = []; // Map output_index => summary content
    private array $webSearchQueries = [];
    private array $statusLog = []; // Collect all status updates for persistence
    private bool $isDoneSent = false; // Track if isDone=true has been sent (fallback flag)
    private array $generatedImages = []; // Store generated images with URLs

    /**
     * Code the native code interpreter ran, accumulated from the code deltas
     * and keyed by output_index. The finished item carries the whole code as
     * well, so this is the fallback for the case where it does not.
     */
    private array $codeInterpreterCode = [];

    private int $codeInterpreterCalls = 0;

    /**
     * Finished image_generation_call items, for the usage record. Counted when
     * the item completes, not when its picture is stored: the provider bills the
     * call either way.
     */
    private int $imageGenerationCalls = 0;

    /**
     * Fetches the files the code interpreter wrote, once per file, for the
     * `container_file` auxiliaries. Created on first use, per response.
     */
    private ?\App\Services\AI\Providers\Responses\ContainerFiles $containerFiles = null;
    private string $selectedImageSize = 'original'; // original|small|medium|big, set by the request converter

    private ?string $selectedImageRatio = null; // "w:h" from frontend, null when unset

    public function __construct(
        private array    $payload,
        private \Closure $onData
    )
    {
        $selectedSize = strtolower((string)($this->payload['_hawki_image_generation_size'] ?? 'original'));
        if (in_array($selectedSize, ['original', 'small', 'medium', 'big'], true)) {
            $this->selectedImageSize = $selectedSize;
        }

        $selectedRatio = trim((string)($this->payload['_hawki_image_generation_ratio'] ?? ''));
        if (preg_match('/^\d{1,2}:\d{1,2}$/', $selectedRatio) === 1) {
            $this->selectedImageRatio = $selectedRatio;
        }

        // Internal-only fields; remove before sending payload to external API.
        unset($this->payload['_hawki_image_generation_size'], $this->payload['_hawki_image_generation_ratio']);
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
        $isDone = $this->isDoneSent; // Preserve isDone if already sent (fallback flag)
        $usage = null;
        $auxiliaries = [];

        switch ($type) {
            // Main streaming text chunks
            case 'response.output_text.delta':
                $content = $jsonChunk['delta'] ?? '';
                break;

            // Complete text output - DON'T send to avoid overwriting collected deltas
            // Text completion (metadata event)
            case 'response.output_text.done':
                // Just a completion signal, no content to send (deltas are already collected in frontend)
                break;

            // Content part completion (metadata event)
            case 'response.content_part.done':
                // \Log::info('[RESPONSES] Event Type: response.content_part.done');
                // Just a completion signal, no content to send
                break;

            // Progress status - metadata event (no user-facing status needed)
            case 'response.in_progress':
                // \Log::info('[RESPONSES] Event Type: response.in_progress');
                // No status update needed - actual status comes from reasoning/web_search events
                break;

            // Reasoning chunks (streaming)
            case 'response.reasoning.delta':
                $this->handleReasoningDelta($jsonChunk);
                // No status update needed - status already sent by response.output_item.added
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
                // Extract output_index and web search metadata
                $outputIndex = $jsonChunk['output_index'] ?? null;
                //// \Log::info('[RESPONSES] Event Type: response.web_search_call', [
                //    'output_index' => $outputIndex
                //]);

                $this->handleWebSearchCall($jsonChunk);

                // DON'T collect in_progress status - will be replaced by completed state

                $auxiliaries[] = [
                    'type' => 'status',
                    'content' => json_encode([
                        'status' => 'web_search',
                        'output_index' => $outputIndex
                    ])
                ];
                $content = ''; // Ensure message element is created/updated
                break;

            // Web search in progress
            case 'response.web_search_call.searching':
                $outputIndex = $jsonChunk['output_index'] ?? null;
                //// \Log::info('[RESPONSES] Event Type: response.web_search_call.searching', [
                //    'output_index' => $outputIndex
                //]);

                // DON'T collect in_progress status - will be replaced by completed state

                $auxiliaries[] = [
                    'type' => 'status',
                    'content' => json_encode([
                        'status' => 'web_search',
                        'output_index' => $outputIndex
                    ])
                ];
                $content = ''; // Ensure status is sent
                break;

            // Web search completed
            case 'response.web_search_call.completed':
                // Don't send status here - wait for response.output_item.done which contains the query
                // This event comes BEFORE output_item.done, so we don't have the query yet
                $content = '';
                break;

            // Web search in progress (metadata event)
            case 'response.web_search_call.in_progress':
                $outputIndex = $jsonChunk['output_index'] ?? null;
                //// \Log::info('[RESPONSES] Event Type: response.web_search_call.in_progress', [
                //    'output_index' => $outputIndex
                //]);
                $auxiliaries[] = [
                    'type' => 'status',
                    'content' => json_encode([
                        'status' => 'web_search',
                        'output_index' => $outputIndex
                    ])
                ];
                $content = ''; // Ensure status is sent
                break;

            // Response completed with final data
            case 'response.completed':
                $isDone = true;
                $this->isDoneSent = true; // Mark that isDone has been sent
                
                // Collect final "processing completed" status for persistence in status_log
                // Note: This uses output_index 0 since we don't have multiple outputs in Responses API context
                // We DON'T send it as a separate status auxiliary because it's included in status_log
                // and would be overwritten when status_log is processed
                $this->addStatusToLog('processing', 'completed', null, 0);

                // Send final processing completed status WITHOUT message (Frontend derives label)
                $auxiliaries[] = [
                    'type' => 'status',
                    'content' => json_encode([
                        'status' => 'completed'
                    ])
                ];
                // Extract usage from final response
                if (!empty($jsonChunk['response']['usage'])) {
                    $usage = $this->extractUsage($model, $jsonChunk['response']);

                    // The provider side tool calls of this response, for the usage record.
                    $usage = $this->withServerToolUse($usage, [
                        'web_search' => count($this->webSearchQueries),
                        'code_interpreter' => $this->codeInterpreterCalls,
                        'image_generation' => $this->imageGenerationCalls,
                    ]);
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

                // Include ALL collected reasoning summaries as individual auxiliaries
                // This ensures they are stored in the database for later retrieval
                if (!empty($this->allReasoningSummaries)) {
                    ksort($this->allReasoningSummaries);
                    foreach ($this->allReasoningSummaries as $index => $summaryData) {
                        $summaryText = is_array($summaryData) ? $summaryData['text'] : $summaryData;
                        $outputIndex = is_array($summaryData) ? ($summaryData['output_index'] ?? null) : null;

                        // Extract title from markdown header
                        $title = 'Reasoning';
                        if (preg_match('/^\*\*(.+?)\*\*/', $summaryText, $matches)) {
                            $title = trim($matches[1]);
                            // Remove title from summary text
                            $summaryText = preg_replace('/^\*\*(.+?)\*\*\s*\n*/', '', $summaryText);
                            $summaryText = trim($summaryText);
                        }

                        $auxContent = [
                            'index' => $index,
                            'title' => $title,
                            'summary' => $summaryText
                        ];

                        if ($outputIndex !== null) {
                            $auxContent['output_index'] = $outputIndex;
                        }

                        $auxiliaries[] = [
                            'type' => 'reasoning_summary_item',
                            'content' => json_encode($auxContent)
                        ];
                    }

                    //// \Log::info('[RESPONSES] Added reasoning summaries to final response', [
                    //    'total_summaries' => count($this->allReasoningSummaries)
                    //]);
                }

                // Include web search queries as individual auxiliaries
                // This ensures they are stored in the database for later retrieval
                if (!empty($this->webSearchQueries)) {
                    foreach ($this->webSearchQueries as $index => $queryData) {
                        $query = is_array($queryData) ? $queryData['query'] : $queryData;
                        $outputIndex = is_array($queryData) ? ($queryData['output_index'] ?? null) : null;

                        // Ensure query is a string (handle nested arrays/objects)
                        if (is_array($query) || is_object($query)) {
                            $query = json_encode($query);
                        }

                        $auxContent = [
                            'index' => $index,
                            'query' => $query
                        ];

                        if ($outputIndex !== null) {
                            $auxContent['output_index'] = $outputIndex;
                        }

                        $auxiliaries[] = [
                            'type' => 'web_search_query',
                            'content' => json_encode($auxContent)
                        ];
                    }

                    //// \Log::info('[RESPONSES] Added web search queries to final response', [
                    //    'total_queries' => count($this->webSearchQueries)
                    //]);
                }

                // Forward already stored generated images for persistence/final render
                if (!empty($this->generatedImages)) {
                    foreach ($this->generatedImages as $imageData) {
                        $outputIndex = $imageData['output_index'] ?? null;
                        $url = $imageData['url'] ?? null;
                        $uuid = $imageData['uuid'] ?? null;
                        $mime = $imageData['mime'] ?? null;
                        $name = $imageData['name'] ?? null;
                        $prompt = $imageData['prompt'] ?? 'Generated Image';

                        if ($url && $uuid) {
                            $auxiliaries[] = [
                                'type' => 'generated_image',
                                'content' => json_encode([
                                    'output_index' => $outputIndex,
                                    'url' => $url,
                                    'uuid' => $uuid,
                                    'mime' => $mime,
                                    'name' => $name,
                                    'prompt' => $prompt
                                ])
                            ];

                            // Persist URL (not base64) in message history markdown.
                            $content .= "\n\n![{$prompt}]({$url})";
                        }
                    }
                }

                // Note: Reasoning summaries and web search queries are also sent individually
                // AND included here in final response for database persistence


                // Add final status log as auxiliary for persistence
                if (!empty($this->statusLog)) {
                    // Update reasoning step labels and summaries before saving
                    foreach ($this->statusLog as &$entry) {
                        if ($entry['type'] === 'reasoning' && isset($entry['output_index'])) {
                            $outputIndex = $entry['output_index'];

                            // Add title if available
                            if (isset($this->reasoningSummaryTitles[$outputIndex])) {
                                $entry['message'] = $this->reasoningSummaryTitles[$outputIndex];
                                //// \Log::info('[RESPONSES] Updated reasoning step with title', [
                                //    'output_index' => $outputIndex,
                                //    'title' => $this->reasoningSummaryTitles[$outputIndex]
                                //]);
                            }

                            // Add summary content if available
                            if (isset($this->reasoningSummaryContent[$outputIndex])) {
                                $entry['summary'] = $this->reasoningSummaryContent[$outputIndex];
                                //// \Log::info('[RESPONSES] Updated reasoning step with summary content', [
                                //    'output_index' => $outputIndex,
                                //    'summary_length' => strlen($this->reasoningSummaryContent[$outputIndex])
                                //]);
                            } else {
                                //\Log::warning('[RESPONSES] No summary content found for reasoning step', [
                                //    'output_index' => $outputIndex,
                                //    'available_summaries' => array_keys($this->reasoningSummaryContent)
                                //]);
                            }
                        }
                    }
                    unset($entry); // Break reference

                    $auxiliaries[] = [
                        'type' => 'status_log',
                        'content' => json_encode(['log' => $this->statusLog])
                    ];
                    //// \Log::info('[RESPONSES] Added status log to final response', [
                    //    'total_entries' => count($this->statusLog),
                    //    'reasoning_titles_updated' => count($this->reasoningSummaryTitles),
                    //    'reasoning_summaries_added' => count($this->reasoningSummaryContent)
                    //]);
                }

                // Include citations as auxiliaries
                if (!empty($this->citations)) {
                    $auxiliaries[] = [
                        'type' => 'responsesCitations',
                        'content' => json_encode([
                            'citations' => $this->citations
                        ])
                    ];
                    //// \Log::info('[RESPONSES] Added citations to final response', [
                    //    'total_citations' => count($this->citations)
                    //]);
                }
                
                break;

            // Response failed
            case 'response.failed':
                $error = $jsonChunk['error'] ?? $jsonChunk['response']['error'] ?? [];
                $errorMessage = $error['message'] ?? 'Response failed';
                $errorCode = $error['code'] ?? null;

                \Log::error('[RESPONSES] Response failed', [
                    'error_message' => $errorMessage,
                    'error_code' => $errorCode
                ]);

                // Collect error status for persistence WITHOUT message (Frontend derives label)
                $this->addStatusToLog('processing', 'error', null);

                // Send error status to frontend WITHOUT message
                $auxiliaries[] = [
                    'type' => 'status',
                    'content' => json_encode([
                        'status' => 'error',
                        'error_code' => $errorCode
                    ])
                ];

                // Add error status log as auxiliary for persistence
                if (!empty($this->statusLog)) {
                    $auxiliaries[] = [
                        'type' => 'status_log',
                        'content' => json_encode([
                            'log' => $this->statusLog
                        ])
                    ];
                }

                // Return error response with auxiliaries (detailed error for debugging)
                return new AiResponse(
                    content: [
                        'text' => '',
                        'auxiliaries' => $auxiliaries
                    ],
                    usage: null,
                    isDone: true,
                    error: $errorMessage // Keep detailed error for logs/debugging
                );

            // Output item done - may contain citations/annotations
            case 'response.output_item.done':
                $this->handleOutputItemDone($jsonChunk);

                // Check if this is a reasoning item completion
                $item = $jsonChunk['item'] ?? [];
                $itemType = $item['type'] ?? null;
                $itemStatus = $item['status'] ?? null;
                $outputIndex = $jsonChunk['output_index'] ?? null;

                if ($itemType === 'reasoning') {
                    // Reasoning completed - send status update
                    // \Log::info('[RESPONSES] Event Type: response.output_item.done', [
                    //    'item_type' => $itemType,
                    //    'output_index' => $outputIndex
                    //]);

                    // Use summary title if available (custom content), otherwise NO message (Frontend derives label)
                    $label = $this->reasoningSummaryTitles[$outputIndex] ?? null;

                    // Collect status for persistence
                    $this->addStatusToLog('reasoning', 'completed', $label, $outputIndex);

                    $statusContent = [
                        'status' => 'reasoning_complete',
                        'output_index' => $outputIndex
                    ];

                    // Only add message if it's a custom summary title
                    if ($label !== null) {
                        $statusContent['message'] = $label;
                    }

                    $auxiliaries[] = [
                        'type' => 'status',
                        'content' => json_encode($statusContent)
                    ];
                    $content = '';
                } elseif ($itemType === 'code_interpreter_call') {
                    // The finished call is the only event carrying both the code and
                    // its output, so this is where the run becomes visible. It arrives
                    // after 'response.code_interpreter_call.completed' has already
                    // logged the completed step, so nothing is added to the status log
                    // here - doing so appended a stale 'in_progress' entry behind the
                    // completed one and the reloaded message showed the steps in the
                    // wrong order.
                    $this->codeInterpreterCalls++;

                    $content = $this->renderCodeInterpreterCall($item, $outputIndex);

                    $auxiliaries[] = [
                        'type' => 'code_interpreter_call',
                        'content' => json_encode([
                            'output_index' => $outputIndex,
                            'code' => $this->codeInterpreterCodeFor($item, $outputIndex),
                            'output' => $this->codeInterpreterOutput($item),
                            'container_id' => $item['container_id'] ?? null,
                        ]),
                    ];

                    /*
                     * The plot is stored and announced, but NOT written into the
                     * text: OpenAI's models place the picture themselves, as a
                     * sandbox:/mnt/data reference in their answer, and the frontend
                     * points that reference at the stored file (syncInlinePlots in
                     * syntax_modifier.js). A plot the model does not mention is
                     * drawn by the frontend under the code box instead. Writing the
                     * markdown here as well showed the picture twice.
                     *
                     * The 'generated_image' auxiliary is what links the stored file
                     * to the message and moves it out of temp storage, and its url
                     * is what the frontend resolves to. It carries 'inline' so the
                     * frontend does not draw its image container: that container is
                     * inserted before .message-content, which for a plot means
                     * above the code and above the answer.
                     *
                     * Deliberately not added to $this->generatedImages either - that
                     * list appends its own markdown at response.completed.
                     */
                    foreach ($this->collectCodeInterpreterImages($item, $outputIndex) as $image) {
                        $image['inline'] = true;

                        $auxiliaries[] = [
                            'type' => 'generated_image',
                            'content' => json_encode($image),
                        ];
                    }
                } elseif ($itemType === 'web_search_call') {
                    // Web search completed - extract query and send status
                    $action = $item['action'] ?? [];
                    $query = $action['query'] ?? $item['query'] ?? null;
                    $outputIndex = $jsonChunk['output_index'] ?? null;

                    // Log the full item structure if query is null for debugging
                    if ($query === null) {
                        //\Log::warning('[RESPONSES] Web search query is null, full item:', [
                        //    'item' => $item,
                        //    'output_index' => $outputIndex
                        //]);
                    }

                    //\Log::info('[RESPONSES] Event Type: response.output_item.done', [
                    //    'item_type' => $itemType,
                    //    'query' => $query,
                    //    'output_index' => $outputIndex
                    //]);

                    // Only process and send status if query is available
                    if ($query) {
                        // Store query for final response (as array with output_index)
                        // Check if query already exists
                        $exists = false;
                        foreach ($this->webSearchQueries as $existingQuery) {
                            $existingQueryText = is_array($existingQuery) ? $existingQuery['query'] : $existingQuery;
                            if ($existingQueryText === $query) {
                                $exists = true;
                                break;
                            }
                        }

                        if (!$exists) {
                            $this->webSearchQueries[] = [
                                'query' => $query,
                                'output_index' => $outputIndex
                            ];
                            //\Log::info('[RESPONSES] Web search query stored from output_item.done', [
                            //    'query' => $query,
                            //    'output_index' => $outputIndex,
                            //    'total_queries' => count($this->webSearchQueries)
                            //]);
                        }

                        // Collect COMPLETED status with query for persistence
                        $this->addStatusToLog('web_search', 'completed', 'Searched for: ' . $query, $outputIndex);

                        // Send web_search_complete status WITH query (Frontend uses query for label)
                        $auxiliaries[] = [
                            'type' => 'status',
                            'content' => json_encode([
                                'status' => 'web_search_complete',
                                'query' => $query,
                                'output_index' => $outputIndex
                            ])
                        ];
                    } else {
                        // No query available - still collect status but without query
                        $this->addStatusToLog('web_search', 'completed', null, $outputIndex);

                        // Send web_search_complete WITHOUT query (Frontend uses fallback label)
                        // Frontend will remove the temporary status item
                        $auxiliaries[] = [
                            'type' => 'status',
                            'content' => json_encode([
                                'status' => 'web_search_complete',
                                'query' => null,
                                'output_index' => $outputIndex
                            ])
                        ];
                        // \Log::info('[RESPONSES] Sending web_search_complete without query (will be removed in frontend)');
                    }

                    $content = '';
                } elseif ($itemType === 'message') {
                    // Message item completed (normal conversation output)
                    // This is a FALLBACK for when response.completed is not received
                    // (Some API instances don't send it reliably on long responses)
                    $isDone = true;
                    $this->isDoneSent = true; // Mark that isDone has been sent
                } elseif ($itemType == "image_generation_call") {
                    $this->imageGenerationCalls++;
                    $imageData = $item['result'] ?? null;

                    if ($imageData && $outputIndex !== null) {
                        // Immediately persist generated image and stream only filesystem URL to the client.
                        $attachmentService = app(\App\Services\Chat\Attachment\AttachmentService::class);
                        $prompt = $item['revised_prompt'] ?? 'Generated Image';
                        $category = 'private';
                        $storedImage = $attachmentService->storeFromBase64(
                            $imageData,
                            $category,
                            'generated_' . time() . '_' . $outputIndex . '.png',
                            $this->selectedImageSize,
                            $this->selectedImageRatio
                        );

                        if ($storedImage) {
                            $this->generatedImages[] = [
                                'output_index' => $outputIndex,
                                'url' => $storedImage['url'],
                                'uuid' => $storedImage['uuid'],
                                'mime' => $storedImage['mime'],
                                'name' => $storedImage['name'],
                                'prompt' => $prompt
                            ];

                            $auxiliaries[] = [
                                'type' => 'generated_image',
                                'content' => json_encode([
                                    'output_index' => $outputIndex,
                                    'url' => $storedImage['url'],
                                    'uuid' => $storedImage['uuid'],
                                    'mime' => $storedImage['mime'],
                                    'name' => $storedImage['name'],
                                    'prompt' => $prompt
                                ])
                            ];
                        } else {
                            \Log::error('[RESPONSES] Failed to store generated image during streaming', [
                                'output_index' => $outputIndex
                            ]);
                        }

                        // Send completion status
                        $this->addStatusToLog('image_generation', 'completed', null, $outputIndex);

                        $auxiliaries[] = [
                            'type' => 'status',
                            'content' => json_encode([
                                'status' => 'image_generation_complete',
                                'output_index' => $outputIndex
                            ])
                        ];
                        $content = '';
                    }
                } else {
                    // Other output_item types
                    //\Log::info('[RESPONSES] Event Type: response.output_item.done', [
                    //    'item_type' => $itemType ?? 'unknown',
                    //    'output_index' => $outputIndex
                    //]);
                }
                break;

            // Response created - initial event, send status to create message element
            case 'response.created':
                // \Log::info('[RESPONSES] Event Type: response.created');
                // Send backend microtime as auxiliary for lag measurement
                $auxiliaries[] = [
                    'type' => 'debug_timestamp',
                    'content' => json_encode([
                        'backend_microtime' => microtime(true),
                        'backend_timestamp' => now()->toIso8601String()
                    ])
                ];

                // Collect initial status for persistence
                $this->addStatusToLog('processing', 'in_progress', null);

                // Send initial processing status WITHOUT message (Frontend derives label from status)
                $auxiliaries[] = [
                    'type' => 'status',
                    'content' => json_encode([
                        'status' => 'in_progress'
                    ])
                ];
                $content = '';
                break;

            // Output item added - check if it's reasoning or web search
            case 'response.output_item.added':
                $item = $jsonChunk['item'] ?? [];
                $itemType = $item['type'] ?? null;
                $outputIndex = $jsonChunk['output_index'] ?? null;

                if ($itemType === 'reasoning') {
                    // Reasoning started - send status update
                    //\Log::info('[RESPONSES] Event Type: response.output_item.added', [
                    //    'item_type' => $itemType,
                    //    'output_index' => $outputIndex
                    //]);

                    // DON'T collect in_progress status - will be replaced by completed state

                    $auxiliaries[] = [
                        'type' => 'status',
                        'content' => json_encode([
                            'status' => 'reasoning',
                            'output_index' => $outputIndex
                        ])
                    ];
                    $content = '';
                } elseif ($itemType === 'web_search_call') {
                    // Web search initiated - send initial status update
                    //\Log::info('[RESPONSES] Event Type: response.output_item.added', [
                    //    'item_type' => $itemType,
                    //    'output_index' => $outputIndex
                    //]);

                    // Collect initial web_search status for persistence
                    $this->addStatusToLog('web_search', 'initiated', null, $outputIndex);

                    $auxiliaries[] = [
                        'type' => 'status',
                        'content' => json_encode([
                            'status' => 'web_search_initiated',
                            'output_index' => $outputIndex
                        ])
                    ];
                    $content = '';
                } elseif ($itemType === 'code_interpreter_call') {
                    // The sandbox is starting. Logged as well as sent, so a reloaded
                    // message keeps the "Running code..." step in front of the
                    // completed one instead of only showing the result.
                    $this->addStatusToLog('code_interpreter', 'in_progress', null, $outputIndex);

                    $auxiliaries[] = [
                        'type' => 'status',
                        'content' => json_encode([
                            'status' => 'in_progress',
                            'type' => 'code_interpreter',
                            'output_index' => $outputIndex,
                        ]),
                    ];
                    $content = '';
                } else {
                    // Generic output_item.added (e.g., message)
                    //\Log::info('[RESPONSES] Event Type: response.output_item.added', [
                    //    'item_type' => $itemType ?? 'unknown',
                    //    'output_index' => $outputIndex
                    //]);
                }
                break;

            // Metadata events (no action needed)

            case 'response.output_text.annotation.added':
                /*
                 * URL citations are read from the finished message. A container
                 * file citation is acted on here and now: it names a file the code
                 * interpreter wrote, which the model links as sandbox:/mnt/data/…
                 * and which only exists while the container lives. It is fetched
                 * into HAWKI's storage and announced as a 'container_file'
                 * auxiliary, which the frontend resolves the link to.
                 */
                $annotation = $jsonChunk['annotation'] ?? [];
                if (is_array($annotation) && \App\Services\AI\Providers\Responses\ContainerFiles::isCitation($annotation)) {
                    $this->containerFiles ??= app(\App\Services\AI\Providers\Responses\ContainerFiles::class);
                    $file = $this->containerFiles->fetch($model, $annotation, $jsonChunk['output_index'] ?? null);

                    if ($file !== null && $file['repeated'] === false) {
                        unset($file['repeated']);
                        $auxiliaries[] = [
                            'type' => 'container_file',
                            'content' => json_encode($file),
                        ];
                    }
                }
                break;

            case 'response.refusal.delta':
            case 'response.refusal.done':
            case 'response.function_call_arguments.delta':
            case 'response.function_call_arguments.done':
            case 'response.file_search_call.in_progress':
            case 'response.file_search_call.searching':
            case 'response.file_search_call.completed':
                // Ignore metadata events (status already handled above)
                break;

            // The model started running code in the API's own sandbox. There is no
            // chat button for this tool, so the status step is the only thing telling
            // the user why the answer is taking a moment.
            case 'response.code_interpreter_call.in_progress':
            case 'response.code_interpreter_call.interpreting':
                $outputIndex = $jsonChunk['output_index'] ?? null;

                $auxiliaries[] = [
                    'type' => 'status',
                    'content' => json_encode([
                        'status' => 'in_progress',
                        'type' => 'code_interpreter',
                        'output_index' => $outputIndex,
                    ]),
                ];
                $content = ''; // Ensure the message element is created/updated
                break;

            case 'response.code_interpreter_call.completed':
                $outputIndex = $jsonChunk['output_index'] ?? null;

                $this->addStatusToLog('code_interpreter', 'completed', null, $outputIndex);

                $auxiliaries[] = [
                    'type' => 'status',
                    'content' => json_encode([
                        'status' => 'completed',
                        'type' => 'code_interpreter',
                        'output_index' => $outputIndex,
                    ]),
                ];
                $content = '';
                break;

            // The code the sandbox is about to run, streamed character by character.
            // The event is named '..._call_code', not '..._code' - the latter spelling
            // matched nothing the API sends, so the code was silently dropped.
            case 'response.code_interpreter_call_code.delta':
                $outputIndex = $jsonChunk['output_index'] ?? 0;
                $this->codeInterpreterCode[$outputIndex] =
                    ($this->codeInterpreterCode[$outputIndex] ?? '').($jsonChunk['delta'] ?? '');
                break;

            case 'response.code_interpreter_call_code.done':
                $outputIndex = $jsonChunk['output_index'] ?? 0;
                if (isset($jsonChunk['code'])) {
                    $this->codeInterpreterCode[$outputIndex] = $jsonChunk['code'];
                }
                break;

            // Reasoning summary events - collect summary text for display
            case 'response.reasoning_summary_part.added':
                // Reasoning summary started - initialize buffer
                $this->reasoningSummary = '';
                //\Log::info('[RESPONSES] Event Type: response.reasoning_summary_part.added', [
                //    'summary_index' => $jsonChunk['summary_index'] ?? null,
                //    'output_index' => $jsonChunk['output_index'] ?? null
                //]);
                break;

            case 'response.reasoning_summary_text.delta':
                // Accumulate reasoning summary chunks (for streaming display if needed)
                $delta = $jsonChunk['delta'] ?? '';
                $this->reasoningSummary .= $delta;
                //\Log::info('[RESPONSES] Event Type: response.reasoning_summary_text.delta', [
                //    'delta_length' => strlen($delta)
                //]);
                break;

            case 'response.reasoning_summary_text.done':
                // One summary part completed - store it but don't send yet (wait for part.done)
                $summaryText = $jsonChunk['text'] ?? '';
                $this->reasoningSummary = trim($summaryText);
                //\Log::info('[RESPONSES] Event Type: response.reasoning_summary_text.done', [
                //    'text_length' => strlen($this->reasoningSummary)
                //]);
                break;

            case 'response.reasoning_summary_part.done':
                // Summary part fully completed - send as individual auxiliary
                if (!empty($this->reasoningSummary)) {
                    $summaryIndex = $jsonChunk['summary_index'] ?? count($this->allReasoningSummaries);
                    $outputIndex = $jsonChunk['output_index'] ?? null;

                    // Extract title from markdown header (e.g., **Title**)
                    $title = 'Reasoning';
                    $summaryText = $this->reasoningSummary;
                    if (preg_match('/^\*\*(.+?)\*\*/', $summaryText, $matches)) {
                        $title = trim($matches[1]);
                        // Remove the title line from the summary text
                        $summaryText = preg_replace('/^\*\*(.+?)\*\*\s*\n*/', '', $summaryText);
                        $summaryText = trim($summaryText);
                    }

                    // Store title for status log update
                    if ($outputIndex !== null) {
                        $this->reasoningSummaryTitles[$outputIndex] = $title;
                        $this->reasoningSummaryContent[$outputIndex] = $summaryText;

                        //\Log::info('[RESPONSES] Stored reasoning summary for persistence', [
                        //    'output_index' => $outputIndex,
                        //    'title' => $title,
                        //    'summary_length' => strlen($summaryText)
                        //]);
                    }

                    //\Log::info('[RESPONSES] Sending reasoning summary as auxiliary', [
                    //    'summary_index' => $summaryIndex,
                    //    'output_index' => $outputIndex,
                    //    'title' => $title,
                    //    'text_preview' => substr($summaryText, 0, 50) . '...'
                    //]);
                    // Collect reasoning completed status for persistence
                    $this->addStatusToLog('reasoning', 'completed', $title, $outputIndex);
                    
                    // Send reasoning_complete status to frontend to end reasoning indicator
                    $statusContent = [
                        'status' => 'reasoning_complete',
                        'output_index' => $outputIndex
                    ];
                    
                    // Only add message if it's a custom summary title
                    if ($title !== 'Reasoning') {
                        $statusContent['message'] = $title;
                    }
                    
                    $auxiliaries[] = [
                        'type' => 'status',
                        'content' => json_encode($statusContent)
                    ];
                    // Send summary immediately as auxiliary
                    $auxiliaries[] = [
                        'type' => 'reasoning_summary_item',
                        'content' => json_encode([
                            'index' => $summaryIndex,
                            'output_index' => $outputIndex,
                            'title' => $title,
                            'summary' => $summaryText
                        ])
                    ];

                    // Also store for final combined summary (with output_index)
                    $this->allReasoningSummaries[$summaryIndex] = [
                        'text' => $this->reasoningSummary,
                        'output_index' => $outputIndex
                    ];

                    // Reset buffer
                    $this->reasoningSummary = '';
                    $content = ''; // Force sending auxiliary
                }
                break;

            case 'response.reasoning_text.delta':
            case 'response.reasoning_text.done':
                // Raw reasoning text events (not used when summary is enabled)
                break;

            case 'response.mcp_list_tools.in_progress':
            case 'response.mcp_list_tools.completed':
            case 'response.mcp_call.in_progress':
            case 'response.mcp_call.completed':
            case 'response.mcp_call_arguments.delta':
            case 'response.mcp_call.arguments.done':
            case 'response.mcp_call_arguments.done':
                // Ignore metadata events (status already handled above)
                break;

            // Image Generation events
            case 'response.image_generation_call.in_progress':
                // Image generation initiated
                $outputIndex = $jsonChunk['output_index'] ?? null;

                // Collect initial status for persistence
                $this->addStatusToLog('image_generation', 'initiated', null, $outputIndex);

                $auxiliaries[] = [
                    'type' => 'status',
                    'content' => json_encode([
                        'status' => 'image_generation_initiated',
                        'output_index' => $outputIndex
                    ])
                ];
                $content = '';
                break;

            case 'response.image_generation_call.generating':
                // Image generation in progress
                $outputIndex = $jsonChunk['output_index'] ?? null;

                $auxiliaries[] = [
                    'type' => 'status',
                    'content' => json_encode([
                        'status' => 'image_generation',
                        'output_index' => $outputIndex
                    ])
                ];
                $content = '';
                break;

            case 'response.image_generation_call.partial_image':
                // Ignore base64 preview frames to avoid exposing/storing base64 blobs in chat content.
                break;

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

        //\Log::info('[RESPONSES] Web search call event', [
        //    'search_id' => $searchId,
        //    'status' => $status,
        //    'action_type' => $actionType,
        //    'action' => $action
        //]);

        // Extract and store query when status is 'completed'
        if ($status === 'completed' && $actionType === 'search') {
            $query = $action['query'] ?? null;
            if ($query) {
                $this->webSearchQueries[] = $query;
                //\Log::info('[RESPONSES] Web search query captured', [
                //    'query' => $query,
                //    'search_id' => $searchId,
                //    'total_queries' => count($this->webSearchQueries)
                //]);
            } else {
                //\Log::warning('[RESPONSES] Web search completed but no query found', [
                //    'action' => $action
                //]);
            }
        }

        // Optional: Extract additional details for future use
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

        //\Log::info('[RESPONSES] Processing message output_item.done for citations');

        // Extract content array
        $content = $item['content'] ?? [];
        if (empty($content)) {
            //\Log::info('[RESPONSES] No content in message item');
            return;
        }

        // Parse each content part for annotations
        foreach ($content as $contentPart) {
            if (($contentPart['type'] ?? '') === 'output_text') {
                $annotations = $contentPart['annotations'] ?? [];

                //\Log::info('[RESPONSES] Found output_text with annotations', [
                //    'annotation_count' => count($annotations)
                //]);

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

                        //\Log::info('[RESPONSES] Stored citation', [
                        //    'url' => $annotation['url'] ?? '',
                        //    'title' => $annotation['title'] ?? ''
                        //]);
                    }
                }
            }
        }

        //\Log::info('[RESPONSES] Total citations collected so far', [
        //    'total' => count($this->citations)
        //]);
    }

    /**
     * Add status update to log for persistence
     * Only call this for completed/final states
     */
    /**
     * The code of a finished code interpreter call.
     *
     * The item carries the whole code, so that is preferred; the accumulated
     * deltas are the fallback for a call whose item arrives without it.
     */
    private function codeInterpreterCodeFor(array $item, ?int $outputIndex): string
    {
        $code = $item['code'] ?? null;

        if (! is_string($code) || trim($code) === '') {
            $code = $this->codeInterpreterCode[$outputIndex ?? 0] ?? '';
        }

        return rtrim((string) $code);
    }

    /**
     * What the sandbox printed. 'outputs' is null whenever the code returned a
     * value instead of printing one, which is the common case - the model reads
     * the value itself and only stdout is reported back here.
     */
    private function codeInterpreterOutput(array $item): string
    {
        $outputs = $item['outputs'] ?? null;

        if (! is_array($outputs)) {
            return '';
        }

        $logs = [];
        foreach ($outputs as $output) {
            if (! is_array($output)) {
                continue;
            }

            // Only 'logs' is stdout. An 'image' output is a plot, handled by
            // collectCodeInterpreterImages() - it must not be turned into text.
            if (($output['type'] ?? null) === 'logs' && isset($output['logs'])) {
                $logs[] = rtrim((string) $output['logs']);
            }
        }

        return implode("\n", array_filter($logs, static fn ($line) => $line !== ''));
    }

    /**
     * Stores the plots a code interpreter call produced.
     *
     * They arrive as an 'image' output whose url is a complete `data:` URI, so
     * nothing has to be fetched from the container - but the base64 must not go
     * into the message either. It is stored as an attachment, exactly like a
     * generated image, and the message gets the URL.
     *
     * Without this the model announces a chart it has drawn and the message shows
     * none, which is what the plots were doing.
     *
     * @return array<int,array<string,mixed>> The stored attachments.
     */
    private function collectCodeInterpreterImages(array $item, ?int $outputIndex): array
    {
        $outputs = $item['outputs'] ?? null;

        if (! is_array($outputs)) {
            return [];
        }

        $images = app(\App\Services\AI\Tools\SandboxImages::class);
        $stored = [];

        foreach ($outputs as $output) {
            if (! is_array($output) || ($output['type'] ?? null) !== 'image') {
                continue;
            }

            $url = (string) ($output['url'] ?? '');

            if (! str_contains($url, 'base64,')) {
                // A container file reference rather than inline data. Fetching it
                // needs the files endpoint, which HAWKI does not call yet.
                \Log::warning('[RESPONSES] A code interpreter image was not inline and was skipped', [
                    'output_index' => $outputIndex,
                    'container_id' => $item['container_id'] ?? null,
                ]);

                continue;
            }

            $attachment = $images->store($url, 'Plot');

            if ($attachment !== null) {
                $attachment['output_index'] = $outputIndex;
                $stored[] = $attachment;
            }
        }

        return $stored;
    }

    /**
     * The executed code as message content.
     *
     * Written into the message itself rather than only into the status step, for
     * two reasons: the chat code box renders a fenced python block with a run
     * button, so the user can read and re-run exactly what ran; and the block is
     * persisted with the message, so the next turn carries the code and its
     * output back to the model.
     */
    private function renderCodeInterpreterCall(array $item, ?int $outputIndex): string
    {
        $code = $this->codeInterpreterCodeFor($item, $outputIndex);

        if ($code === '') {
            return '';
        }

        $block = "\n\n```python\n".$code."\n```";

        $output = $this->codeInterpreterOutput($item);
        if ($output !== '') {
            $block .= "\n\n```output\n".$output."\n```";
        }

        return $block."\n\n";
    }

    private function addStatusToLog(string $type, string $status, ?string $message, ?int $outputIndex = null): void
    {
        $statusEntry = [
            'type' => $type,
            'status' => $status,
            'timestamp' => microtime(true)
        ];

        // Only add message if provided (for custom content like Reasoning Summary Titles)
        if ($message !== null) {
            $statusEntry['message'] = $message;
        }

        if ($outputIndex !== null) {
            $statusEntry['output_index'] = $outputIndex;
        }

        $this->statusLog[] = $statusEntry;
    }
}
