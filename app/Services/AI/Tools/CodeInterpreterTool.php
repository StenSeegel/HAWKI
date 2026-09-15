<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Models\Attachment;
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
class CodeInterpreterTool implements AwarenessContributor, HawkiToolInterface, RequestAwareTool
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

    /** The execution server's cap on the number of files in one call. */
    private const MAX_FILES = 20;

    /**
     * The messages of this request, as they arrived from the frontend. Their
     * attachments and the files their assistant turns produced - a .potx to
     * build the deck on, a CSV to analyse, the picture generated two turns ago
     * - are what the sandbox can get as /work/<name>; see ConversationFiles.
     *
     * @var array<int,array<string,mixed>>
     */
    private array $messages = [];

    public function __construct(
        private readonly McpClient $client,
        private readonly McpServerRegistry $registry,
        private readonly SandboxImages $images,
        private readonly AttachmentService $attachments,
    ) {}

    public function configureForRequest(array $rawPayload): void
    {
        $this->messages = is_array($rawPayload['messages'] ?? null) ? $rawPayload['messages'] : [];

        // A new request: what the tools produce from here on is what a later
        // call of this request can ask for as /work/<name>.
        $this->images->forgetProduced();
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
                        .'Files of this conversation are at /work/<name> - the uploads of the newest user message by themselves, everything else '
                        .'(an image you generated, a deck built earlier, a plot) only when its name is listed in the files argument of the same call. '
                        .'For PowerPoint decks use the hawki_slides module: from hawki_slides import Deck; '
                        .'deck = Deck(title="...", author="HAWKI", lang="de"|"en"); deck.title("...", "..."); '
                        .'deck.bullets("...", ["...", {"text": "...", "sub": ["..."]}], sources=["..."]); deck.cards("...", [{"heading": "...", "text": "..."}]); '
                        .'deck.two_columns("...", {"heading": "...", "items": ["..."]}, {"heading": "...", "items": ["..."]}); deck.quote("...", "..."); '
                        .'deck.image("...", "/work/<image>.png|.svg", caption="..."); '
                        .'deck.closing("..."); deck.save("/tmp/deck.pptx") - those are all the methods; save() needs a /tmp path. '
                        .'To add to a deck built earlier: deck = Deck.open("/work/<name>.pptx") keeps its slides, then add and save under /tmp again. '
                        .'The default look is the JLU template in the deck language; an attached .potx/.pptx is used with template="attached"; '
                        .'style="purple" (blue, green, red, slate) only when a non-JLU look is asked for. '
                        .'On a failing subprocess print(e): the message carries its stderr. '
                        .'Check the layout before returning it: soffice --headless --convert-to pdf, then pdftoppm -png -r 60, '
                        .'and print every PNG from sorted(glob.glob("/tmp/slide*.png")) as a data:image/png;base64 URI so all slides are seen. Everything must happen in ONE call: '
                        .'/tmp is emptied between calls.',
                ],
                'files' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => $this->filesDescription(),
                ],
            ],
            'required' => ['code'],
        ];
    }

    /**
     * The files of this conversation, for the awareness prompt.
     *
     * The same list the `files` argument carries, repeated in the system prompt
     * because that is where models actually read it: on staging, qwen3.8-27b
     * with the list only in the parameter description went looking for the
     * picture with glob() and find(), found an empty /work, and gave up.
     */
    public function awarenessAddendum(): string
    {
        $manifest = $this->conversationFiles()->manifest();

        if ($manifest === '') {
            return '';
        }

        return implode("\n", [
            'FILES OF THIS CONVERSATION, and the name to call each by:',
            $manifest,
            '',
            'They are NOT in the sandbox until you ask for them: put the names you need in the `files` argument of the '
                .'code_interpreter call - files: ["otter.png", "deck.pptx"] - and they appear at /work/<name> for that run. '
                .'Never search the filesystem for a file of this conversation and never tell the user a file is unavailable: '
                .'name it in `files` instead. The uploads of the newest user message are already there without asking.',
            '',
            'USE a file at /work, never print it. Pass its path where it belongs - deck.image("<slide title>", "/work/<name>") '
                .'for a picture on a slide - and say nothing about its bytes. Printing a file that is already in /work blows '
                .'the output limit and loses the whole run; only a figure your code DRAWS is printed as a data URI. '
                .'The user already sees every picture of this conversation.',
        ]);
    }

    /**
     * What the files argument does, and the names it takes: the files of this
     * conversation, listed per request. The definition is built per request
     * (RequestAwareTool), so the list is current for the turn the model is in.
     */
    private function filesDescription(): string
    {
        $text = 'Names of files of this conversation to place read-only at /work/<name> for this run: '
            .'an image you generated, a deck your code built earlier, a plot, an upload from an earlier turn. '
            .'Only the files named here exist in /work, plus the uploads of the newest user message, which come along by themselves. '
            .'Copy the names exactly as listed. ';

        $manifest = $this->conversationFiles()->manifest();

        return $manifest === ''
            ? $text.'This conversation has no such files yet.'
            : $text."Files of this conversation:\n".$manifest;
    }

    /**
     * The files of this conversation as of now: the payload's, and what the
     * tools of this request have produced so far. Rebuilt on every use because
     * an earlier tool round of the same request may have added a picture.
     */
    private function conversationFiles(): ConversationFiles
    {
        return new ConversationFiles($this->messages, $this->images->produced());
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

        $conversation = $this->conversationFiles();
        $notes = [];

        $files = $this->filesFor($conversation, $this->requestedNames($arguments), $notes);
        $arguments = ['code' => $code];
        if ($files !== []) {
            $arguments['files'] = $files;
        }

        $producedBefore = count($this->images->produced());

        try {
            $output = $this->unwrap($this->client->callTool($server, $mcpTool, $arguments), $code);
        } catch (McpException $e) {
            // A model that sends JavaScript to a Python tool gets a SyntaxError
            // on line 1 and no idea why. Recorded: `const fs = require('fs')` as
            // the whole program. The hint names the way that does work.
            $message = $this->condenseError($e->getMessage());

            $hint = $this->wrongLanguageHint($code, $message)
                // A program that opened a file it had not asked for exits non-zero,
                // so this is the path the missing file hint is needed on.
                ?? $this->missingFileHint($message, $conversation)
                ?? $this->printedFileHint($e->getMessage());

            $tail = array_filter([...$notes, $hint]);

            if ($tail !== []) {
                return $message."\n\n".implode("\n", $tail);
            }

            if ($message !== $e->getMessage()) {
                return $message;
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
            $output = mb_substr($output, 0, self::MAX_OUTPUT_CHARS)
                ."\n\n[output truncated after ".self::MAX_OUTPUT_CHARS.' characters]';
        }

        // What this run produced is what the next call may ask for - said here,
        // after the cap, so the names never fall victim to the truncation.
        $produced = array_slice($this->images->produced(), $producedBefore);
        if ($produced !== []) {
            $note = $this->producedNote($produced);
            if ($note !== null) {
                $notes[] = $note;
            }
        }

        $hint = $this->missingFileHint($output, $conversation);
        if ($hint !== null) {
            $notes[] = $hint;
        }

        // The execution server cuts stdout at its limit and says so. Everything
        // after that point is lost, a printed file included, so the model has to
        // know rather than assume its deck was delivered.
        if (str_contains($output, '[truncated]')) {
            $notes[] = '[the output was cut off at the sandbox limit - anything your program printed after that point is lost. '
                .'Print less: a document left in /tmp is delivered by itself, so never print it, and print at most a few small previews.]';
        }

        return $notes === [] ? $output : $output."\n\n".implode("\n", $notes);
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
     * The names the model listed in the files argument. Tolerant about the
     * shape: a list of strings is the schema, but a single string and a list of
     * {name: ...} objects have both been seen from models and both mean the
     * same thing.
     *
     * @return array<int,string>
     */
    private function requestedNames(array $arguments): array
    {
        $listed = $arguments['files'] ?? [];
        if (is_string($listed)) {
            $listed = [$listed];
        }
        if (! is_array($listed)) {
            return [];
        }

        $names = [];
        foreach ($listed as $item) {
            if (is_array($item)) {
                $item = $item['name'] ?? ($item['file'] ?? null);
            }
            if (is_string($item) && trim($item) !== '') {
                $names[] = trim($item);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The files that travel with the call, as the execution server takes them:
     * name plus base64, to appear at /work/<name>.
     *
     * The uploads of the newest user message come along by themselves - a
     * template attached to the question is meant for it. Everything else the
     * model has to name, and only the named files of the conversation are read
     * from storage: shipping every deck and slide preview of the chat on every
     * call would weigh the call down with files nobody asked for. A name that
     * is not a file of this conversation, and a file past the count or size
     * budget, are left out and reported in the result rather than failing the
     * run - the model can act on that, and the run may not have needed the
     * file at all.
     *
     * @param  array<int,string>  $requested
     * @param  array<int,string>  $notes  filled with what the model should know about left out files
     * @return array<int,array{name: string, content_base64: string}>
     */
    private function filesFor(ConversationFiles $conversation, array $requested, array &$notes): array
    {
        $names = $conversation->newestUploads();
        $unknown = [];

        foreach ($requested as $name) {
            $attachment = $conversation->resolve($name);
            if ($attachment === null) {
                $unknown[] = $name;

                continue;
            }
            $names[] = $conversation->nameOf((string) $attachment->uuid);
        }

        $names = array_values(array_unique(array_filter($names, 'is_string')));

        if ($unknown !== []) {
            $available = $conversation->names();
            $notes[] = '[not a file of this conversation, so not in /work: '.implode(', ', $unknown).'. '
                .($available === []
                    ? 'This conversation has no files to list.]'
                    : 'The files you can list are: '.implode(', ', $available).'.]');
        }

        $files = [];
        $total = 0;
        $leftOut = [];

        foreach ($names as $name) {
            $attachment = $conversation->resolve($name);
            if (! $attachment instanceof Attachment) {
                continue;
            }

            if (count($files) >= self::MAX_FILES) {
                $leftOut[] = $name.' (more than '.self::MAX_FILES.' files)';

                continue;
            }

            try {
                $bytes = $this->attachments->retrieve($attachment);
            } catch (\Throwable $e) {
                Log::warning('[CodeInterpreterTool] Could not read an attachment for the sandbox', ['uuid' => $attachment->uuid, 'error' => $e->getMessage()]);
                $leftOut[] = $name.' (could not be read)';

                continue;
            }

            if (! is_string($bytes) || $bytes === '') {
                $leftOut[] = $name.' (empty)';

                continue;
            }

            if ($total + strlen($bytes) > self::MAX_FILES_BYTES) {
                Log::warning('[CodeInterpreterTool] An attachment is left out of the sandbox, the files budget is spent', ['name' => $name, 'bytes' => strlen($bytes)]);
                $leftOut[] = $name.' (the '.(self::MAX_FILES_BYTES / 1024 / 1024).' MB budget for one call is spent)';

                continue;
            }

            $total += strlen($bytes);
            $files[] = ['name' => $name, 'content_base64' => base64_encode($bytes)];
        }

        if ($leftOut !== []) {
            $notes[] = '[not placed in /work for this run: '.implode('; ', $leftOut).']';
        }

        return $files;
    }

    /**
     * The line that tells the model the /work names of what this run produced,
     * so the next call can list them. Under the names the conversation knows
     * them by - which is the stored name, unless it collides with an earlier
     * file.
     *
     * @param  array<int,array<string,mixed>>  $produced
     */
    private function producedNote(array $produced): ?string
    {
        $conversation = $this->conversationFiles();
        $names = [];

        foreach ($produced as $stored) {
            $name = $conversation->nameOf((string) ($stored['uuid'] ?? ''));
            if ($name !== null) {
                $names[] = $name;
            }
        }

        if ($names === []) {
            return null;
        }

        return '[for a later code_interpreter call, the files this run produced are available at /work/<name> '
            .'when listed in the files argument: '.implode(', ', $names).']';
    }

    /**
     * When the program tried to open a file under /work that was not there,
     * the fix is almost always a missing entry in the files argument, not a
     * missing file - and Python's error does not say so.
     */
    private function missingFileHint(string $output, ConversationFiles $conversation): ?string
    {
        // The missing path itself has to be under /work. Every failed run quotes
        // the docker command, which mentions /work whatever went wrong - without
        // this, a missing /tmp/slide-5.png was answered with "list it in files".
        if (preg_match('#(?:FileNotFoundError|No such file or directory)[^\n]*?/work/#', $output) !== 1) {
            return null;
        }

        if (str_contains($output, 'listed in the `files` argument')) {
            return null; // hawki_slides already said it
        }

        $available = $conversation->names();

        return '[a file of this conversation is only at /work/<name> when its name is listed in the files argument of the call. '
            .($available === [] ? 'This conversation has no files to list.]' : 'The files you can list are: '.implode(', ', $available).'.]');
    }

    /**
     * A failed run's message, made safe to hand back to the model.
     *
     * The execution server puts the program's output in the error it reports,
     * and a program that printed a file's bytes prints megabytes: recorded on
     * 2026-09-15, a model read a generated PNG out of /work and printed it, the
     * run died on the stdout limit, and the error - half a megabyte of base64 -
     * went back into the conversation uncapped. The next request to the model
     * never came back ("INTERNAL ERROR: Empty reply from server"), so the turn
     * ended with no deck and no explanation. The success path has had a cap all
     * along; this is the same cap for the path that actually produces the
     * enormous texts.
     */
    private function condenseError(string $message): string
    {
        // A base64 run is unreadable to the model and is what makes these
        // messages enormous. What it needs to know is that it happened.
        $condensed = (string) preg_replace(
            '/(?:data:[a-z0-9.+\/;=-]*base64,)?[A-Za-z0-9+\/]{200,}={0,2}/i',
            '[... base64 of a file, left out ...]',
            $message
        );

        if (mb_strlen($condensed) > self::MAX_OUTPUT_CHARS) {
            $condensed = mb_substr($condensed, 0, self::MAX_OUTPUT_CHARS)
                ."\n\n[error message truncated after ".self::MAX_OUTPUT_CHARS.' characters]';
        }

        return $condensed;
    }

    /**
     * The hint for a run that died printing a file.
     *
     * Only the pictures a program draws are meant to be printed. A file that is
     * already in /work has to be used, not echoed - and a model that does echo
     * it loses the whole turn to the stdout limit.
     */
    private function printedFileHint(string $message): ?string
    {
        $tooMuch = str_contains($message, 'maxBuffer')
            || str_contains($message, 'stdout maxBuffer length exceeded')
            || str_contains($message, '[truncated]');

        if (! $tooMuch) {
            return null;
        }

        return '[the program printed too much - almost always a file read from /work and printed back. '
            .'Never print a file that is already in /work: pass its path where it is needed, '
            .'e.g. deck.image("<title>", "/work/<name>"). Only a figure your code DRAWS is printed as a data URI.]';
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
