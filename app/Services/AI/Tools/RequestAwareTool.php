<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * A tool that needs something from the request the model cannot put into its
 * arguments: the attached files, a setting picked in the chat UI. The registry
 * hands the raw payload over once, before the tool is offered to the model.
 */
interface RequestAwareTool
{
    /**
     * @param  array<string,mixed>  $rawPayload  the request payload as it arrived from the frontend
     */
    public function configureForRequest(array $rawPayload): void;
}
