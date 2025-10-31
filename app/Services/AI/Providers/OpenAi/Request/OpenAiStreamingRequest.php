<?php
declare(strict_types=1);


namespace App\Services\AI\Providers\OpenAi\Request;


use App\Services\AI\Providers\AbstractRequest;
use App\Services\AI\Value\AiModel;
use App\Services\AI\Value\AiResponse;

class OpenAiStreamingRequest extends AbstractRequest
{
    use OpenAiUsageTrait;
    
    public function __construct(
        private array    $payload,
        private \Closure $onData
    )
    {
    }
    
    public function execute(AiModel $model): void
    {
        $this->payload['stream'] = true;
        $this->payload['stream_options'] = [
            'include_usage' => true,
        ];
        
        $this->executeStreamingRequest(
            model: $model,
            payload: $this->payload,
            onData: $this->onData,
            chunkToResponse: [$this, 'chunkToResponse']
        );
    }
    
    protected function chunkToResponse(AiModel $model, string $chunk): AiResponse
    {
        $jsonChunk = json_decode($chunk, true, 512, JSON_THROW_ON_ERROR);
        
        if (isset($jsonChunk['error'])) {
            return $this->createErrorResponse($jsonChunk['error']['message'] ?? 'Unknown error');
        }
        
        $content = '';
        $isDone = false;
        $usage = null;
        
        // Check for the finish_reason flag
        if (isset($jsonChunk['choices'][0]['finish_reason']) && $jsonChunk['choices'][0]['finish_reason'] === 'stop') {
            $isDone = true;
        }
        
        // Extract usage data ONLY when the stream is done (finish_reason is set)
        // This prevents counting tokens multiple times during streaming
        // OpenAI sends usage in the final chunk when stream_options.include_usage is true
        if ($isDone && !empty($jsonChunk['usage'])) {
            $usage = $this->extractUsage($model, $jsonChunk);
            
            if (config('logging.triggers.usage') && $usage) {
                \Log::info('Token Usage - OpenAI (Final Chunk)', [
                    'model' => $model->getId(),
                    'prompt_tokens' => $jsonChunk['usage']['prompt_tokens'] ?? 0,
                    'completion_tokens' => $jsonChunk['usage']['completion_tokens'] ?? 0,
                    'total_tokens' => $jsonChunk['usage']['total_tokens'] ?? 0,
                    'finish_reason' => $jsonChunk['choices'][0]['finish_reason'] ?? null
                ]);
            }
        }
        
        // Extract content if available
        if (isset($jsonChunk['choices'][0]['delta']['content'])) {
            $content = $jsonChunk['choices'][0]['delta']['content'];
        }
        
        return new AiResponse(
            content: [
                'text' => $content,
            ],
            usage: $usage,
            isDone: $isDone
        );
    }
}
