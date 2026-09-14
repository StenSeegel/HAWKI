<?php
namespace App\Services\Chat\Attachment\Handlers;

use App\Models\Attachment;
use App\Services\Chat\Attachment\AttachmentService;

use App\Services\Chat\Attachment\DocumentImageService;
use App\Services\FileConverter\FileConverterFactory;
use App\Services\PageRender\PageRenderer;
use Illuminate\Support\Str;

use App\Services\Storage\FileStorageService;
use App\Services\Chat\Attachment\Interfaces\AttachmentInterface;

use Exception;
use Illuminate\Support\Facades\Log;

class AtchDocumentHandler implements AttachmentInterface
{
    public function __construct(
        protected FileStorageService $storageService
    ){
    }

    public function store($file, string $category): array
    {
        // Generate a unique filename to prevent overwriting
        $uuid = Str::uuid();
        $originalName = $file->getClientOriginalName();

//        $stored = $this->storageService->store($file, $originalName, $uuid, $category, true);
        $stored = $this->storageService->store($file, $originalName, $uuid, $category, true);
        if (!$stored) {
            return [
                'success' => false,
                'message'=> 'Failed to store file.'
            ];
            // throw new \Exception('Failed to store file.');
        }
//        $url = $this->storageService->getUrl($uuid, $category);
        $results = AttachmentService::isTextNativeMime(AttachmentService::mimeOfUpload($file))
            ? self::textNativeResults(file_get_contents($file->getRealPath()) ?: '', $originalName)
            : $this->extractFileContent($file);

        $results = $this->addPageRenders($results, $file, $originalName);

        if (!$results && self::isBinaryDeliverable($originalName)) {
            // A PowerPoint template has no text worth extracting, and a .potx
            // is more than the converter reads. The file is stored all the
            // same - the code interpreter gets it at /work/<name> - and the
            // model is told what it is instead of what is in it.
            $results = self::binaryResults($originalName);
        }

        if (!$results) {
            return [
                'success' => false,
                'uuid' => $uuid,
                'message'=> 'Failed to extract text from file'
            ];
            // throw new \Exception('Failed to store file.');
        }

        foreach($this->optimizeOutputs($results) as $relativePath => $content){
            $this->storageService->store($content, basename($relativePath), $uuid, $category, true, '/output');
        }

        return [
            'success' => true,
            'uuid' => $uuid,
//            'url'=> $url
        ];
    }

    /**
     * Drops decorative and duplicate figures and re-encodes the rest as lossy
     * webp before anything is written, see DocumentImageService::optimizeForStorage().
     */
    protected function optimizeOutputs(array $results): array
    {
        try {
            return app(DocumentImageService::class)->optimizeForStorage($results);
        } catch (Exception $e) {
            Log::warning('[AtchDocumentHandler] Could not optimize converter output, storing as is: ' . $e->getMessage());
            return $results;
        }
    }

    /**
     * @param  string|null  $filename  needed when $file is raw bytes: the
     *         converter validates by extension and refuses an unnamed payload.
     */
    public function extractFileContent($file, ?string $filename = null): ?array{
        try{
            $converter = FileConverterFactory::create();
            return $converter->convert($file, $filename);
        }
        catch(Exception $e){
            return null;
        }
    }

    public function retrieveContext(string $uuid, string $category, $fileType = 'md'): string{
        $attachment = Attachment::where('uuid', $uuid)->first();
        // A text file's context is its own bytes in a fenced block; escaping
        // them would hand the model &lt;mxfile&gt; instead of the XML it should
        // work on.
        $escape = ! ($attachment !== null && AttachmentService::isTextNativeMime((string) $attachment->mime));

        $files = $this->storageService->retrieveOutputFilesByType($uuid, $category, $fileType);
        if($files || count($files) > 0){
            return $this->mergeOutputFiles($files, $escape, $attachment?->name, $this->hasPageRenders($uuid, $category));
        }

        try{

            // No converter output next to the stored file: extract again. The
            // attachment is already persistent at this point, so the output is
            // written to the persistent folder (not temp) and returned directly
            // instead of re-reading it, which would recurse forever if the
            // write landed somewhere retrieveOutputFilesByType does not look.
            $file = $this->storageService->retrieve($uuid, $category);
            if ($file === null) {
                // The stored file is gone - nothing to extract from, and
                // handing null to the converter is a TypeError, not an
                // Exception, so the catch below would not see it.
                Log::warning('[AtchDocumentHandler] No stored file to extract context from', ['uuid' => $uuid, 'category' => $category]);

                return "Unable to extract content at the moment. please try again later. If the problem persists please contact the adminstrator.";
            }

            $results = $escape
                ? $this->extractFileContent($file, $attachment?->name)
                : self::textNativeResults((string) $file, (string) $attachment->name);

            if ($escape && $attachment !== null) {
                $results = $this->addPageRenders($results, (string) $file, (string) $attachment->name);
            }

            if($results !== null){
                $outputs = [];
                foreach($this->optimizeOutputs($results) as $relativePath => $content){
                    $this->storageService->store($content, basename($relativePath), $uuid, $category, false, '/output');
                    if (strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)) === strtolower($fileType)) {
                        $outputs[] = ['path' => $relativePath, 'contents' => $content];
                    }
                }
                return $this->mergeOutputFiles($outputs, $escape, $attachment?->name, self::containsPageRenders(array_keys($results)));
            }
            else{
                return "Unable to extract content at the moment. please try again later. If the problem persists please contact the adminstrator.";
            }

        }
        catch(Exception $e){
            return "Unable to extract content at the moment. please try again later. If the problem persists please contact the adminstrator.";
        }

    }

    /**
     * Merge the converter's output files into a single context string.
     *
     * File converter 1.x returned one content_markdown.md; 3.x returns the
     * document split into chunks/00001.md, 00002.md, ... each prefixed with a
     * YAML front matter block (keywords, languages, page numbers). Chunks are
     * ordered by file name, the front matter is dropped and the bodies are
     * concatenated so the model receives the whole document.
     */
    protected function mergeOutputFiles(array $files, bool $escape = true, ?string $documentName = null, bool $pagesRendered = false): string
    {
        usort($files, static fn(array $a, array $b) => strnatcmp(basename($a['path']), basename($b['path'])));

        $parts = [];
        foreach ($files as $file) {
            $contents = (string) $file['contents'];
            $body = trim($this->stripFrontMatter($contents));
            if ($body === '') {
                continue;
            }
            // With the pages rendered, the text of each one is headed by the
            // page it belongs to, so the model can put words and picture
            // together. The converter's front matter says which page a chunk
            // came from.
            $page = $pagesRendered ? self::frontMatterPage($contents) : null;
            if ($page !== null) {
                $body = '['.self::pageLabel($page, $documentName)."]\n".$body;
            }
            $parts[] = $body;
        }

        $merged = self::nameFigures(implode("\n\n", $parts), $documentName);

        return $escape ? htmlspecialchars($merged) : $merged;
    }

    /**
     * Renders the pages of a slide deck next to what the converter extracted.
     *
     * The converter gives the model a deck's text and the icons cut out of
     * it, and nothing about where anything sits. For the formats in
     * page_render.formats the sidecar renders each slide, and those images
     * take the place of the extracted figures (page_render.replace_figures):
     * every icon is in its slide already, and sending it a second time on its
     * own cost image tokens and gave the model two things to confuse.
     *
     * A sidecar that is down leaves the results exactly as they were.
     *
     * @param  array<string,string>|null  $results  converter output, relative path => content
     * @param  \Illuminate\Http\UploadedFile|string  $file  the upload or its bytes
     * @return array<string,string>|null
     */
    protected function addPageRenders(?array $results, $file, string $filename): ?array
    {
        $renderer = app(PageRenderer::class);
        if (! $renderer->shouldRender($filename)) {
            return $results;
        }

        $pages = $renderer->render($file, $filename);
        if ($pages === []) {
            return $results;
        }

        if ($results === null) {
            // The converter could not read it but the renderer could: the
            // model gets the slides and is told there is no text to go with them.
            $results = ['content_markdown.md' => '['.$filename.': the text could not be extracted; its pages follow as images.]'."\n"];
        }

        if ((bool) config('page_render.replace_figures', true)) {
            $results = self::withoutFigures($results);
        }

        return $results + $pages;
    }

    /**
     * Drops the figures the converter extracted and the markers that pointed
     * at them, so nothing is left dangling once the rendered pages stand in.
     *
     * @param  array<string,string>  $results
     * @return array<string,string>
     */
    public static function withoutFigures(array $results): array
    {
        $kept = [];
        foreach ($results as $path => $content) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($extension, ['webp', 'png', 'jpg', 'jpeg'], true)
                && DocumentImageService::classify(basename($path))['kind'] === 'figure') {
                continue;
            }
            if ($extension === 'md') {
                $content = self::stripFigureMarkers($content);
            }
            $kept[$path] = $content;
        }

        return $kept;
    }

    /** Removes "> [Image: ../assets/image_N.webp]" lines (and the markdown image form). */
    public static function stripFigureMarkers(string $markdown): string
    {
        $stripped = preg_replace([
            '/^[ \t]*>?[ \t]*\[Image:[^\]]*\][ \t]*\R?/mi',
            '/!\[[^\]]*\]\([^)]*\.(?:webp|png|jpe?g)\)[ \t]*\R?/i',
        ], '', $markdown);

        return $stripped ?? $markdown;
    }

    /** Whether the stored output of an attachment holds rendered pages. */
    protected function hasPageRenders(string $uuid, string $category): bool
    {
        try {
            return self::containsPageRenders($this->storageService->listOutputFilesByType($uuid, $category, 'webp'))
                || self::containsPageRenders($this->storageService->listOutputFilesByType($uuid, $category, 'png'));
        } catch (Exception $e) {
            return false;
        }
    }

    /** @param  string[]  $paths */
    public static function containsPageRenders(array $paths): bool
    {
        foreach ($paths as $path) {
            if (DocumentImageService::classify(basename((string) $path))['kind'] === 'page') {
                return true;
            }
        }

        return false;
    }

    /** The page a converter chunk came from, from its front matter; null when it does not say. */
    public static function frontMatterPage(string $chunk): ?int
    {
        if (! str_starts_with(ltrim($chunk), '---')) {
            return null;
        }
        if (preg_match('/\A\s*---\R(.*?)\R---/s', $chunk, $m) !== 1) {
            return null;
        }

        return preg_match('/^pageNumber:\s*(\d+)\s*$/mi', $m[1], $p) === 1 ? (int) $p[1] : null;
    }

    private static function pageLabel(int $page, ?string $documentName): string
    {
        $name = trim((string) $documentName);

        return 'Page '.$page.($name === '' ? '' : ' of '.$name);
    }

    /**
     * Turns the converter's "[Image: ../assets/image_2.webp]" markers into
     * "[Figure 3 of report.docx]".
     *
     * The stored webp is an implementation detail, but the model read those
     * markers as file names and answered with "image_2.webp" when asked which
     * file it had been given. The only name it should see is the uploaded one.
     * Numbering comes from the asset name, the same rule
     * DocumentImageService::classify() uses, so the marker in the text and
     * the picture sent alongside it carry the same number.
     */
    public static function nameFigures(string $markdown, ?string $documentName = null): string
    {
        $name = trim((string) $documentName);
        $suffix = $name === '' ? '' : ' of '.$name;

        $figure = static function (array $m) use ($suffix): string {
            $what = DocumentImageService::classify($m[1]);

            return '['.($what['kind'] === 'page' ? 'Page ' : 'Figure ').$what['number'].$suffix.']';
        };

        // "> [Image: ../assets/image_2.webp]" and, should a converter write the
        // plain markdown form instead, "![alt](../assets/image_2.webp)".
        $patterns = [
            '/\[Image:\s*[^\]]*?([^\/\]\s]+)\.(?:webp|png|jpe?g)\s*\]/i',
            '/!\[[^\]]*\]\(\s*[^)]*?([^\/)\s]+)\.(?:webp|png|jpe?g)\s*\)/i',
        ];

        foreach ($patterns as $pattern) {
            $markdown = preg_replace_callback($pattern, $figure, $markdown) ?? $markdown;
        }

        return $markdown;
    }

    /**
     * The converter's result shape for a file that needs no converter: one
     * markdown file holding the content in a fenced block named after the
     * file's kind, so a draw.io diagram arrives at the model as ```drawio.
     *
     * @return array<string,string> relative path => content
     */
    public static function textNativeResults(string $content, string $filename): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $language = match ($extension) {
            'drawio' => 'drawio',
            'xml', 'svg' => 'xml',
            'json' => 'json',
            'csv' => 'csv',
            'md', 'markdown' => 'markdown',
            'py' => 'python',
            '' => 'text',
            default => $extension,
        };

        $content = rtrim($content);
        // A fence longer than any run of backticks in the content, so the block cannot end early.
        preg_match_all('/`{3,}/', $content, $runs);
        $longest = $runs[0] === [] ? 0 : max(array_map('strlen', $runs[0]));
        $fence = str_repeat('`', max(3, $longest + 1));

        return [
            'content_markdown.md' => $fence.$language."\n".$content."\n".$fence."\n",
        ];
    }

    /**
     * Office files the sandbox can use as they are, text or no text.
     */
    public static function isBinaryDeliverable(string $filename): bool
    {
        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['potx', 'pptx', 'ppsx', 'xlsx', 'docx'], true);
    }

    /**
     * What the model reads for a file whose content is not text: its name and
     * the one thing it can do with it.
     */
    public static function binaryResults(string $filename): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $kind = match ($extension) {
            'potx' => 'a PowerPoint template',
            'pptx', 'ppsx' => 'a PowerPoint presentation',
            'xlsx' => 'an Excel workbook',
            'docx' => 'a Word document',
            default => 'a binary file',
        };

        return [
            'content_markdown.md' => '['.$filename.' is '.$kind.'. Its text was not extracted. '
                .'The code interpreter has the file at /work/'.$filename
                .($extension === 'potx' || $extension === 'pptx' ? ' - for a deck, build on it with Deck(template="attached").' : '.')
                ."]\n",
        ];
    }

    protected function stripFrontMatter(string $content): string
    {
        if (!str_starts_with(ltrim($content), '---')) {
            return $content;
        }
        return preg_replace('/\A\s*---\R.*?\R---\R?/s', '', $content, 1) ?? $content;
    }

}
