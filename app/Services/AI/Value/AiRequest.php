<?php
declare(strict_types=1);


namespace App\Services\AI\Value;


readonly class AiRequest
{
    /**
     * The utility assistants HAWKI drives itself. A request that carries one of
     * these keys is not a chat turn: it names a conversation, improves a prompt
     * or summarises a chatlog, and the answer is consumed by HAWKI rather than
     * shown as a message.
     */
    public const ASSISTANT_KEYS = ['title_generator', 'prompt_improver', 'summarizer'];

    public function __construct(
        public ?AiModel $model = null,
        public ?array   $payload = null,
        /**
         * Which utility assistant this request serves, if any. Null for a chat
         * turn. Typed rather than left in the payload so it cannot travel to a
         * provider by accident.
         */
        public ?string  $assistantKey = null
    )
    {
    }
    
    public function withModel(AiModel $model): self
    {
        return new self(
            model: $model,
            payload: $this->payload,
            assistantKey: $this->assistantKey
        );
    }
    
    public function withPayload(array $payload): self
    {
        return new self(
            model: $this->model,
            payload: $payload,
            assistantKey: $this->assistantKey
        );
    }
    
    /**
     * Whether this request serves one of HAWKI's own utility assistants.
     *
     * Such a request must be answered by the model alone: it gets no tools, no
     * tool awareness instruction and no tool loop. The awareness prompt would
     * otherwise land in front of the very text the title model is asked to
     * summarise - with max_tokens 10 to answer in, the "title" then became a
     * fragment of the tool prompt - and a tool call would spend upstream
     * requests and a sandbox run on naming a chat.
     */
    public function isUtility(): bool
    {
        return $this->assistantKey !== null;
    }
}
