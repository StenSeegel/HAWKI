<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Models\Attachment;
use App\Services\AI\Utils\MessageAttachmentFinder;
use App\Services\Chat\Attachment\AttachmentService;
use App\Services\Mcp\Exception\McpException;
use App\Services\Mcp\McpClient;
use App\Services\Mcp\McpServerRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Image generation and editing served by HAWKI through an MCP server, for
 * providers that bring no image tool of their own.
 *
 * The picture never reaches the model in either direction. Outgoing, the MCP
 * server answers with a base64 PNG which is stored as an attachment here and
 * handed to the request as a 'generated_image': a megabyte of base64 in the
 * conversation would blow the context window and buy nothing, since the model
 * cannot look at the image anyway. Incoming, the image to edit is the attachment
 * of the message - the model never has its bytes and could not pass them on, so
 * HAWKI reads them out of the conversation and puts them into the call.
 */
class ImageGenerationTool implements HawkiToolInterface
{
    public const KEY = 'image_generation';

    /**
     * What the generating server accepts: Z-Image Turbo takes 256 to 2048 pixels
     * per edge in steps of 16. A request outside that is rejected by the server,
     * so the dimensions are clamped here rather than sent and lost.
     */
    private const MIN_EDGE = 256;

    private const MAX_EDGE = 2048;

    private const EDGE_STEP = 16;

    /**
     * The largest image the editing server takes, 8 MiB decoded.
     */
    private const MAX_SOURCE_BYTES = 8 * 1024 * 1024;

    /**
     * The default picture: a small square. Anything else - a larger picture, a
     * portrait, a banner - the model asks for through the width and height
     * arguments, because the user's prompt is the only place a format is named.
     */
    private const DEFAULT_EDGE = 512;

    /**
     * The aspect ratio as 'w:h' if the gallery set one for this message.
     */
    private ?string $requestedRatio = null;

    /**
     * The messages of this request, as they arrived from the frontend. They carry
     * the uuids of the attached images, which is where an edit gets its picture.
     *
     * @var array<int,array<string,mixed>>
     */
    private array $messages = [];

    public function __construct(
        private readonly McpClient $client,
        private readonly McpServerRegistry $registry,
        private readonly SandboxImages $images,
        private readonly MessageAttachmentFinder $attachmentFinder,
        private readonly AttachmentService $attachments,
    ) {}

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => self::KEY,
                'description' => (string) config(
                    'hawki_tools.tools.'.self::KEY.'.description',
                    'Generate an image from a textual description.'
                ),
                'parameters' => $this->getArgumentSchema(),
            ],
        ];
    }

    public function getArgumentSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'prompt' => [
                    'type' => 'string',
                    'description' => 'What the image shows, or - when editing - what to change and what to keep. '
                        .'Name subject, style, composition and lighting; English prompts work best. This is a '
                        .'diffusion prompt, not a chat message: describe the picture, do not ask for it.',
                ],
                /*
                 * Editing is the default whenever the message has an image
                 * attached, because that is what the picture is there for - the
                 * chat UI attaches it exactly when the user asked for a change.
                 * The flag exists so a model can say otherwise: an image in the
                 * conversation and a request for an unrelated new picture.
                 */
                'edit' => [
                    'type' => 'boolean',
                    'description' => 'Whether to change the image attached to the message instead of creating a '
                        .'new one. Defaults to true when an image is attached. Pass false to generate a new, '
                        .'unrelated image although one is attached.',
                ],
                /*
                 * The chat UI has no size buttons: the model reads the format out
                 * of the user's prompt and asks for it here. Nothing asked for is
                 * a small square, which is what most requests want and what comes
                 * back fastest.
                 */
                'width' => [
                    'type' => 'integer',
                    'description' => 'Width in pixels, 256 to 2048 in steps of 16. Leave it out for the default '
                        .'of 512x512. Set width and height together when the user asks for a bigger or a '
                        .'specific size, or for a shape: landscape, portrait, a banner, 16:9.',
                ],
                'height' => [
                    'type' => 'integer',
                    'description' => 'Height in pixels, 256 to 2048 in steps of 16. Leave it out for the default '
                        .'of 512x512; see width.',
                ],
            ],
            'required' => ['prompt'],
        ];
    }

    /**
     * What the request carries that the model does not: the aspect ratio the
     * gallery set, and the messages whose attachments hold the image an edit
     * works on.
     *
     * Handed in from {@see HawkiToolRegistry::resolveForRequest()}, because the
     * tool arguments come from the model - which never has the bytes of an
     * attached image.
     */
    public function configureForRequest(array $rawPayload): void
    {
        $ratio = trim((string) ($rawPayload['image_generation_ratio'] ?? ''));
        $this->requestedRatio = preg_match('/^\d{1,2}:\d{1,2}$/', $ratio) === 1 ? $ratio : null;

        $this->messages = is_array($rawPayload['messages'] ?? null) ? $rawPayload['messages'] : [];
    }

    public function execute(array $arguments, ?string $serverBinding = null): string
    {
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        if ($prompt === '') {
            throw new McpException('No prompt was given.');
        }

        $binding = $this->registry->binding(self::KEY, $serverBinding);
        $server = $binding['server'];

        if ($server === null) {
            throw new McpException('Image generation is not bound to a reachable MCP server.');
        }

        $source = $this->sourceImage($arguments);
        $route = $source === null ? 'generate' : 'edit';

        $mcpTool = $binding['tools'][$route] ?? null;
        if ($mcpTool === null || $mcpTool === '') {
            throw new McpException('The image generation binding has no tool for the "'.$route.'" route.');
        }

        if ($source !== null) {
            $callArguments = ['instruction' => $prompt, 'image_base64' => $source['base64']];
            $width = null;
            $height = null;
        } else {
            [$width, $height] = $this->dimensions($arguments);
            $callArguments = ['prompt' => $prompt, 'width' => $width, 'height' => $height];
        }

        Log::info('[ImageGenerationTool] Calling MCP tool', [
            'server' => $server->name,
            'tool' => $mcpTool,
            'route' => $route,
            'width' => $width,
            'height' => $height,
            'source_image' => $source['uuid'] ?? null,
            'prompt_length' => strlen($prompt),
        ]);

        $content = $this->client->callToolContent($server, $mcpTool, $callArguments);

        $stored = 0;
        foreach ($content['images'] as $image) {
            // The prompt becomes the alt text of the picture in the message.
            if ($this->images->collect($image['data'], $prompt)) {
                $stored++;
            }
        }

        if ($stored === 0) {
            /*
             * The server answers with a status line next to the image, so its text
             * is worth passing on: it says whether the image was refused, and a
             * refusal the model can read is better than a bare failure.
             */
            throw new McpException(
                'The image server returned no image.'
                .($content['text'] === '' ? '' : ' It reported: '.mb_substr($content['text'], 0, 300))
            );
        }

        if ($source !== null) {
            return '[the attached image was edited as instructed and the new version is shown to the user. '
                .'Do not describe it and do not repeat the instruction: say in one sentence what you changed, '
                .'and ask whether anything else should be different.]';
        }

        return '[the image was generated from your prompt and is shown to the user, at '
            .$width.'x'.$height.' pixels. Do not describe it and do not repeat the prompt: '
            .'say in one sentence what you made, and ask what should be changed if anything.]';
    }

    /**
     * The image this call edits, or null when it generates a new one.
     *
     * The newest attached image wins: the chat UI attaches exactly the picture the
     * user acted on - the gallery's "remove background" and "change size" tools
     * attach that image and switch image generation on before sending their
     * prompt, and a fresh generation preselects its result for the next message.
     *
     * @return array{uuid: string, base64: string}|null
     */
    private function sourceImage(array $arguments): ?array
    {
        if (($arguments['edit'] ?? true) !== true) {
            return null;
        }

        $attachment = $this->newestAttachedImage();
        if ($attachment === null) {
            return null;
        }

        try {
            $bytes = $this->attachments->retrieve($attachment);
        } catch (\Throwable $e) {
            Log::error('[ImageGenerationTool] Could not read the image to edit', [
                'uuid' => $attachment->uuid,
                'error' => $e->getMessage(),
            ]);

            throw new McpException('The image to edit could not be read, so it was not changed.');
        }

        if (! is_string($bytes) || $bytes === '') {
            throw new McpException('The image to edit could not be read, so it was not changed.');
        }

        if (strlen($bytes) > self::MAX_SOURCE_BYTES) {
            throw new McpException(
                'The attached image is too large to edit ('.round(strlen($bytes) / 1048576, 1)
                .' MB, the limit is 8 MB).'
            );
        }

        return ['uuid' => (string) $attachment->uuid, 'base64' => base64_encode($bytes)];
    }

    /**
     * The image attachment of the newest message that has one.
     */
    private function newestAttachedImage(): ?Attachment
    {
        if ($this->messages === []) {
            return null;
        }

        $attachments = $this->attachmentFinder->findAttachmentsOfMessages($this->messages);
        if ($attachments === []) {
            return null;
        }

        foreach (array_reverse($this->messages) as $message) {
            $uuids = $message['content']['attachments'] ?? null;
            if (! is_array($uuids)) {
                continue;
            }

            // Within one message the last attachment is the one added last.
            foreach (array_reverse($uuids) as $uuid) {
                $attachment = $attachments[$uuid] ?? null;

                if ($attachment !== null && $attachment->type === 'image') {
                    return $attachment;
                }
            }
        }

        return null;
    }

    /**
     * The dimensions to generate at: what the model asked for, else a small
     * square - reshaped by the gallery's ratio if one was set.
     *
     * @return array{0: int, 1: int}
     */
    private function dimensions(array $arguments): array
    {
        $modelWidth = $arguments['width'] ?? null;
        $modelHeight = $arguments['height'] ?? null;

        // A model that names one edge only means a square of that size.
        if (is_int($modelWidth) || is_int($modelHeight)) {
            $edge = is_int($modelWidth) ? $modelWidth : $modelHeight;

            return [
                $this->clampEdge(is_int($modelWidth) ? $modelWidth : $edge),
                $this->clampEdge(is_int($modelHeight) ? $modelHeight : $edge),
            ];
        }

        $width = self::DEFAULT_EDGE;
        $height = self::DEFAULT_EDGE;

        if ($this->requestedRatio !== null) {
            [$ratioWidth, $ratioHeight] = array_map('intval', explode(':', $this->requestedRatio));

            if ($ratioWidth > 0 && $ratioHeight > 0) {
                // The long edge stays the default and the short one follows from
                // the ratio, so a ratio changes the shape, not the size.
                $longEdge = self::DEFAULT_EDGE;

                if ($ratioWidth >= $ratioHeight) {
                    $width = $longEdge;
                    $height = (int) round($longEdge * $ratioHeight / $ratioWidth);
                } else {
                    $height = $longEdge;
                    $width = (int) round($longEdge * $ratioWidth / $ratioHeight);
                }
            }
        }

        return [$this->clampEdge($width), $this->clampEdge($height)];
    }

    /**
     * An edge the server accepts: inside its range and a multiple of 16.
     */
    private function clampEdge(int $edge): int
    {
        $edge = max(self::MIN_EDGE, min(self::MAX_EDGE, $edge));

        return (int) (round($edge / self::EDGE_STEP) * self::EDGE_STEP);
    }
}
