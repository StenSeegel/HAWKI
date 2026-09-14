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
 * Python execution served by HAWKI through an MCP server, for providers that
 * bring no code interpreter of their own.
 *
 * The server behind it is the same one the translation module has been using
 * through CodeExecutionService; the difference is that the model calls it here
 * instead of a hardcoded flow.
 */
class CodeInterpreterTool implements HawkiToolInterface, RequestAwareTool
{
    public const KEY = 'code_interpreter';

    /**
     * Only stdout comes back, so an output cap keeps a runaway loop from filling
     * the model's context with its own print statements.
     */
    private const MAX_OUTPUT_CHARS = 8000;

    /**
     * The most the attached files may weigh together. The execution server caps
     * its `files` argument at 25 MB; staying under it here turns "too large" into
     * a skipped file with a log line instead of a failed call.
     */
    private const MAX_FILES_BYTES = 24 * 1024 * 1024;

    /**
     * The messages of this request, as they arrived from the frontend. Their
     * attachments - a .potx to build the deck on, a CSV to analyse - are what
     * the sandbox gets as /work/<name>.
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

    public function configureForRequest(array $rawPayload): void
    {
        $this->messages = is_array($rawPayload['messages'] ?? null) ? $rawPayload['messages'] : [];
    }

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
                    'Execute Python code and return its output.'
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
                'code' => [
                    'type' => 'string',
                    /*
                     * Measured against the gVisor sandbox behind code-exec-mcp:
                     * the working directory is read-only, only /tmp can be
                     * written, plt.show() produces nothing, and a printed base64
                     * data URI is the one way an image gets out.
                     *
                     * This belongs here rather than in the awareness prompt: the
                     * prompt is editable in the admin UI and an installation that
                     * has customised it would never learn the convention, and
                     * without it a model reaches for savefig('plot.png') and the
                     * run fails on a read-only directory.
                     */
                    'description' => 'The Python code to run. Print whatever should be returned; only stdout is reported back. '
                        .'Write temporary files to /tmp, which is writable; the working directory is not. '
                        .'Every call is a fresh process - nothing from an earlier call (imports, variables, files) exists in the next one, so re-import everything each time. '
                        .'Images and plots are supported: keep the figure in memory and print it as a data URI, e.g. '
                        .'buf = io.BytesIO(); fig.savefig(buf, format="png"); '
                        .'print("data:image/png;base64," + base64.b64encode(buf.getvalue()).decode()) - '
                        .'that renders as a picture in the chat. Use it instead of plt.show() or savefig() to a filename. '
                        .'Vector graphics work the same way: print the SVG markup itself, or a data:image/svg+xml;base64 URI. '
                        .'Documents are delivered by leaving them in /tmp: every .pptx, .docx, .xlsx, .csv or .pdf there is stored '
                        .'when the run ends and offered to the user as a download; the result names each one. Other file types: print '
                        .'"data:<mime>;name=<filename>;base64,<base64 of the file>" yourself. '
                        .'Files the user attached are at /work/<their name>. '
                        .'For PowerPoint decks use the hawki_slides module: from hawki_slides import Deck; '
                        .'deck = Deck(title="...", author="HAWKI", lang="de"|"en"); deck.title("...", "..."); '
                        .'deck.bullets("...", ["...", {"text": "...", "sub": ["..."]}], sources=["..."]); deck.cards("...", [{"heading": "...", "text": "..."}]); '
                        .'deck.two_columns("...", {"heading": "...", "items": ["..."]}, {"heading": "...", "items": ["..."]}); deck.quote("...", "..."); '
                        .'deck.closing("..."); deck.save("/tmp/deck.pptx") - those are all the methods; save() needs a /tmp path. '
                        .'The default look is the JLU template in the deck language; an attached .potx/.pptx is used with template="attached"; '
                        .'style="purple" (blue, green, red, slate) only when a non-JLU look is asked for. '
                        .'On a failing subprocess print(e): the message carries its stderr. '
                        .'Check the layout before returning it: soffice --headless --convert-to pdf, then pdftoppm -png -r 60, '
                        .'and print every PNG from sorted(glob.glob("/tmp/slide*.png")) as a data:image/png;base64 URI so all slides are seen. Everything must happen in ONE call: '
                        .'/tmp is emptied between calls.',
                ],
            ],
            'required' => ['code'],
        ];
    }

    public function execute(array $arguments, ?string $serverBinding = null): string
    {
        $code = (string) ($arguments['code'] ?? '');
        if (trim($code) === '') {
            throw new McpException('No code was given.');
        }

        $binding = $this->registry->binding(self::KEY, $serverBinding);
        $server = $binding['server'];

        if ($server === null) {
            throw new McpException('The code interpreter is not bound to a reachable MCP server.');
        }

        $mcpTool = $binding['tools']['run'] ?? null;
        if ($mcpTool === null || $mcpTool === '') {
            throw new McpException('The code interpreter binding has no tool for the "run" route.');
        }

        Log::info('[CodeInterpreterTool] Calling MCP tool', [
            'server' => $server->name,
            'tool' => $mcpTool,
            'code_length' => strlen($code),
        ]);

        $arguments = ['code' => $code];
        $files = $this->attachedFiles();
        if ($files !== []) {
            $arguments['files'] = $files;
        }

        try {
            $output = $this->unwrap($this->client->callTool($server, $mcpTool, $arguments), $code);
        } catch (McpException $e) {
            // A model that sends JavaScript to a Python tool gets a SyntaxError
            // on line 1 and no idea why. Recorded: `const fs = require('fs')` as
            // the whole program. The hint names the way that does work.
            $hint = $this->wrongLanguageHint($code, $e->getMessage());
            if ($hint !== null) {
                return $e->getMessage()."\n\n".$hint;
            }

            throw $e;
        }

        /*
         * A plot comes back as base64 written into the printed output. It has to
         * come out here, before the cap below: a PNG runs to tens of thousands of
         * characters, so truncation would both corrupt the image and spend the
         * model's context on base64. The image is stored and the request that
         * called this tool picks it up from SandboxImages.
         */
        $output = $this->images->extractFromText($output);

        if (mb_strlen($output) > self::MAX_OUTPUT_CHARS) {
            return mb_substr($output, 0, self::MAX_OUTPUT_CHARS)
                ."\n\n[output truncated after ".self::MAX_OUTPUT_CHARS.' characters]';
        }

        return $output;
    }

    /**
     * The execution server answers with an envelope around the output:
     * {"text": "...", "meta": {"timed_out": false, "stderr_len": 0, ...}}.
     * The model should read the program output, not the envelope, so it is
     * unwrapped here and only the parts it can act on are kept.
     */
    private function unwrap(string $raw, string $code = ''): string
    {
        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! array_key_exists('text', $decoded)) {
            // A server that returns plain output needs no unwrapping.
            return $raw;
        }

        $text = trim((string) $decoded['text']);
        $meta = is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [];

        if (($meta['timed_out'] ?? false) === true) {
            return ($text === '' ? '' : $text."\n\n")
                .'[the code was stopped because it ran too long - make it finish faster]';
        }

        if ($text === '') {
            return $this->emptyOutputHint($code);
        }

        return $text;
    }

    /**
     * The files attached to the newest message that has any, as the execution
     * server takes them: name plus base64, to appear at /work/<name>.
     *
     * The newest message, not the whole conversation: a template attached to
     * the current request is what the deck is to be built on; a file from ten
     * turns ago is not, and would only weigh the call down. Files past the size
     * budget are left out and logged rather than failing the run.
     *
     * @return array<int,array{name: string, content_base64: string}>
     */
    private function attachedFiles(): array
    {
        if ($this->messages === []) {
            return [];
        }

        $known = $this->attachmentFinder->findAttachmentsOfMessages($this->messages);
        if ($known === []) {
            return [];
        }

        $uuids = [];
        foreach (array_reverse($this->messages) as $message) {
            $listed = $message['content']['attachments'] ?? [];
            if (is_array($listed) && $listed !== []) {
                $uuids = array_values(array_filter($listed, 'is_string'));
                break;
            }
        }

        $files = [];
        $total = 0;
        $seen = [];

        foreach ($uuids as $uuid) {
            $attachment = $known[$uuid] ?? null;
            if (! $attachment instanceof Attachment) {
                continue;
            }

            $name = basename(trim((string) $attachment->name));
            if ($name === '' || $name === '.' || $name === '..' || str_starts_with($name, '.') || $name === 'code.py') {
                $name = 'attachment_'.substr((string) $attachment->uuid, 0, 8);
            }
            // Two attachments with one name: the second gets its uuid in front.
            if (isset($seen[$name])) {
                $name = substr((string) $attachment->uuid, 0, 8).'_'.$name;
            }

            try {
                $bytes = $this->attachments->retrieve($attachment);
            } catch (\Throwable $e) {
                Log::warning('[CodeInterpreterTool] Could not read an attachment for the sandbox', ['uuid' => $uuid, 'error' => $e->getMessage()]);
                continue;
            }

            if (! is_string($bytes) || $bytes === '') {
                continue;
            }

            if ($total + strlen($bytes) > self::MAX_FILES_BYTES) {
                Log::warning('[CodeInterpreterTool] An attachment is left out of the sandbox, the files budget is spent', ['name' => $name, 'bytes' => strlen($bytes)]);
                continue;
            }

            $total += strlen($bytes);
            $seen[$name] = true;
            $files[] = ['name' => $name, 'content_base64' => base64_encode($bytes)];
        }

        return $files;
    }

    /**
     * The one-line diagnosis when the program was not Python at all.
     *
     * Python's parser stops at the first token it cannot read, so JavaScript
     * fails on line 1 with a bare "invalid syntax" - which a model reads as a
     * typo, not as "wrong language", and retries in JavaScript. The hint is
     * given only when the code actually looks like JavaScript.
     */
    public function wrongLanguageHint(string $code, string $error): ?string
    {
        if (! str_contains($error, 'SyntaxError')) {
            return null;
        }

        $looksLikeJavaScript = preg_match(
            '/^\s*(?:const|let|var)\s+\w+\s*=|\brequire\([\'"]|^\s*import\s+.*\s+from\s+[\'"]|=>\s*\{|\bconsole\.log\(/m',
            $code
        ) === 1;

        if (! $looksLikeJavaScript) {
            return null;
        }

        return '[this tool runs PYTHON, and the program you sent is JavaScript. Send Python: put the JavaScript '
            .'in a Python string, write it with open("/tmp/deck.js", "w").write(js), then run it with '
            .'subprocess.run(["node", "/tmp/deck.js"], check=True, capture_output=True, text=True).]';
    }

    /**
     * What to tell the model when its code printed nothing.
     *
     * Worth tailoring, because the generic "print what should be returned" sent
     * models down the wrong path: qwen3-coder-next answered it by retrying the
     * same bare expression, and a model that had just called plt.show() got no
     * hint that an image needs the data URI route at all - it simply retried and
     * burned its rounds. Naming the actual way out turns a wasted round into a
     * useful one.
     */
    private function emptyOutputHint(string $code): string
    {
        if (preg_match('/\b(?:plt|pyplot|matplotlib|savefig|imshow)\b/i', $code) === 1) {
            return '[the code produced no output - a figure is only returned if you print it as a data URI. '
                .'Keep it in memory and print it: buf = io.BytesIO(); fig.savefig(buf, format="png"); '
                .'print("data:image/png;base64," + base64.b64encode(buf.getvalue()).decode()). '
                .'plt.show() and savefig() to a filename return the figure to nobody.]';
        }

        return '[the code produced no output - only what you print comes back, so wrap the result in print(), '
            .'e.g. print(result). A bare expression on the last line returns nothing.]';
    }
}
