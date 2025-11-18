<?php
declare(strict_types=1);


namespace App\Services\AI\Value;


readonly class TokenUsage implements \JsonSerializable
{
    public int $totalTokens;
    
    public function __construct(
        public AiModel $model,
        public int     $promptTokens,
        public int     $completionTokens,
        ?int           $totalTokens = null,
    )
    {
        // If totalTokens is explicitly provided, use it (e.g., from Google API)
        // Otherwise calculate it as the sum of prompt and completion tokens
        $this->totalTokens = $totalTokens ?? ($promptTokens + $completionTokens);
    }
    
    public function toArray(): array
    {
        return [
            'model' => $this->model->getId(),
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens,
        ];
    }
    
    /**
     * @inheritDoc
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
    
}
