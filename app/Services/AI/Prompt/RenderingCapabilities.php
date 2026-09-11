<?php

declare(strict_types=1);

namespace App\Services\AI\Prompt;

use App\Services\AI\Value\AiRequest;

/**
 * Tells the model what the chat draws for the user.
 *
 * A model without an image tool answers a request for a diagram with SVG,
 * mermaid or draw.io markup - and, not knowing what happens to it, describes
 * the XML, apologises for not being able to show pictures, or splits the
 * drawing over several blocks. The chat renders these blocks as pictures with
 * a download, and a draw.io diagram opens in an editor inside HAWKI. Told so,
 * the model writes one complete block and leaves it at that.
 *
 * Added in code rather than in the admin-editable prompts: the system prompt
 * is the user's per conversation, the tool awareness prompts are per tool, and
 * neither knows what the frontend can draw. Utility assistants (title,
 * summary) are left alone, as they are for tool awareness.
 */
final class RenderingCapabilities
{
    public const NOTE = "Rendering in this chat: the user sees fenced code blocks of these kinds as pictures, not as code - "
        ."```svg (a complete <svg> drawing), ```mermaid (a mermaid diagram) and ```drawio (a draw.io diagram as <mxfile> or <mxGraphModel> XML). "
        ."Each is shown with a download button; a draw.io diagram can also be opened in a diagram editor inside this application. "
        ."When a picture or diagram is asked for and you have no image tool, answer with one of these blocks: write the complete markup in one block, "
        ."and do not describe the XML or explain that you cannot display images - the user sees the drawing.";

    public function apply(AiRequest $request): AiRequest
    {
        if ($request->isUtility()) {
            return $request;
        }

        $payload = $request->payload ?? [];
        $messages = $payload['messages'] ?? null;

        // No messages, no conversation to draw in: a bare payload stays as it is.
        if (! is_array($messages) || $messages === []) {
            return $request;
        }

        if (isset($messages[0]) && ($messages[0]['role'] ?? '') === 'system') {
            $existing = trim((string) ($messages[0]['content']['text'] ?? ''));
            if (str_contains($existing, self::NOTE)) {
                return $request;
            }
            $messages[0]['content']['text'] = $existing === '' ? self::NOTE : $existing."\n\n".self::NOTE;
        } else {
            array_unshift($messages, [
                'role' => 'system',
                'content' => ['text' => self::NOTE],
            ]);
        }

        $payload['messages'] = $messages;

        return $request->withPayload($payload);
    }
}
