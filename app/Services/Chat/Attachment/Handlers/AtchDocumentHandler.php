<?php
namespace App\Services\Chat\Attachment\Handlers;

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
        $results = $this->extractFileContent($file);

        if (!$results) {
            return [
                'success' => false,
                'uuid' => $uuid,
                'message'=> 'Failed to extract text from file'
            ];
            // throw new \Exception('Failed to store file.');
        }

        foreach($results as $relativePath => $content){
            $this->storageService->store($content, basename($relativePath), $uuid, $category, true, '/output');
        }

        return [
            'success' => true,
            'uuid' => $uuid,
//            'url'=> $url
        ];
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
        $files = $this->storageService->retrieveOutputFilesByType($uuid, $category, $fileType);
        if($files || count($files) > 0){
            return $this->mergeOutputFiles($files);
        }

        try{

            // No converter output next to the stored file: extract again. The
            // attachment is already persistent at this point, so the output is
            // written to the persistent folder (not temp) and returned directly
            // instead of re-reading it, which would recurse forever if the
            // write landed somewhere retrieveOutputFilesByType does not look.
            $file = $this->storageService->retrieve($uuid, $category);
            $results = $this->extractFileContent($file);

            if($results !== null){
                $outputs = [];
                foreach($results as $relativePath => $content){
                    $this->storageService->store($content, basename($relativePath), $uuid, $category, false, '/output');
                    if (strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)) === strtolower($fileType)) {
                        $outputs[] = ['path' => $relativePath, 'contents' => $content];
                    }
                }
                return $this->mergeOutputFiles($outputs);
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
    protected function mergeOutputFiles(array $files): string
    {
        usort($files, static fn(array $a, array $b) => strnatcmp(basename($a['path']), basename($b['path'])));

        $parts = [];
        foreach ($files as $file) {
            $body = trim($this->stripFrontMatter((string) $file['contents']));
            if ($body !== '') {
                $parts[] = $body;
            }
        }

        return htmlspecialchars(implode("\n\n", $parts));
    }

    protected function stripFrontMatter(string $content): string
    {
        if (!str_starts_with(ltrim($content), '---')) {
            return $content;
        }
        return preg_replace('/\A\s*---\R.*?\R---\R?/s', '', $content, 1) ?? $content;
    }

}
