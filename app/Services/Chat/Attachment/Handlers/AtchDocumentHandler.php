<?php
namespace App\Services\Chat\Attachment\Handlers;

use App\Models\Attachment;
use App\Services\Chat\Attachment\AttachmentService;

use App\Services\Chat\Attachment\DocumentImageService;
use App\Services\FileConverter\FileConverterFactory;
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

    public function extractFileContent($file): ?array{
        try{
            $converter = FileConverterFactory::create();
            return $converter->convert($file);
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
            return $this->mergeOutputFiles($files, $escape);
        }

        try{

            // No converter output next to the stored file: extract again. The
            // attachment is already persistent at this point, so the output is
            // written to the persistent folder (not temp) and returned directly
            // instead of re-reading it, which would recurse forever if the
            // write landed somewhere retrieveOutputFilesByType does not look.
            $file = $this->storageService->retrieve($uuid, $category);
            $results = $escape
                ? $this->extractFileContent($file)
                : self::textNativeResults((string) $file, (string) $attachment->name);

            if($results !== null){
                $outputs = [];
                foreach($this->optimizeOutputs($results) as $relativePath => $content){
                    $this->storageService->store($content, basename($relativePath), $uuid, $category, false, '/output');
                    if (strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)) === strtolower($fileType)) {
                        $outputs[] = ['path' => $relativePath, 'contents' => $content];
                    }
                }
                return $this->mergeOutputFiles($outputs, $escape);
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
    protected function mergeOutputFiles(array $files, bool $escape = true): string
    {
        usort($files, static fn(array $a, array $b) => strnatcmp(basename($a['path']), basename($b['path'])));

        $parts = [];
        foreach ($files as $file) {
            $body = trim($this->stripFrontMatter((string) $file['contents']));
            if ($body !== '') {
                $parts[] = $body;
            }
        }

        $merged = implode("\n\n", $parts);

        return $escape ? htmlspecialchars($merged) : $merged;
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

    protected function stripFrontMatter(string $content): string
    {
        if (!str_starts_with(ltrim($content), '---')) {
            return $content;
        }
        return preg_replace('/\A\s*---\R.*?\R---\R?/s', '', $content, 1) ?? $content;
    }

}
