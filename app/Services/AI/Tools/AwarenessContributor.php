<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * A tool that has something to say in the awareness prompt which its configured
 * text cannot say, because it is different in every request.
 *
 * The configured awareness explains the tool; this adds the facts of this
 * conversation - for the code interpreter, the files it can open and the names
 * to call them by. That list cannot live in the config text, and the tool's
 * parameter description is not enough on its own: measured on staging with
 * jlu/qwen3.8-27b, a model that had the file list only in the `files` parameter
 * description searched the filesystem for the picture instead of asking for it,
 * found nothing, and gave up. The same list in the system prompt is read.
 */
interface AwarenessContributor
{
    /**
     * What to append to this tool's awareness instruction for this request,
     * or '' for nothing.
     */
    public function awarenessAddendum(): string;
}
