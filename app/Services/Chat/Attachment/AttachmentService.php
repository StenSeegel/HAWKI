<?php

namespace App\Services\Chat\Attachment;


use App\Models\AiConvMsg;
use App\Models\Message;
use App\Models\Attachment;

use App\Services\Chat\Attachment\AttachmentFactory;

use App\Services\Storage\FileStorageService;
use App\Services\Storage\Interfaces\StorageServiceInterface;
use App\Services\Storage\StorageServiceFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

use Exception;

class AttachmentService{


    public function __construct(
        private FileStorageService $storageService
    ) {}

    public function store($file, $category): ?array
    {
        try{
            // GET FILE TYPE
            $mime = $file->getMimeType();
            $type = $this->convertToAttachmentType($mime);
            // CREATE HANDLER
            $attachmentHandler = AttachmentFactory::create($type);
            // STORE FILE BASED ON TYPE
            $result = $attachmentHandler->store($file, $category);

            return $result;
        }
        catch(Exception $e){
            Log::error("Error storing file: $e");
            return null;
        }
    }



    public function retrieve(Attachment $attachment, $outputType = null)
    {
        $uuid = $attachment->uuid;
        $category = $attachment->category;

        if($outputType){
            $attachmentHandler = AttachmentFactory::create($attachment->type);
            return $attachmentHandler->retrieveContext($uuid, $category, $outputType);
        }
        else{
            try{
                $file = $this->storageService->retrieve($uuid, $category);
                return $file;
            }
            catch(Exception $e){
                Log::error("Error retrieving file", ["UUID"=> $uuid, "category"=> $category]);
                return null;
            }
        }
    }


    public function getFileUrl(Attachment $attachment, $outputType = null)
    {
        $uuid = $attachment->uuid;
        $category = $attachment->category;

        if($outputType){
            $urls = $this->storageService->getOutputFilesUrls($uuid, $category, $outputType);
            return $urls[0];
        }
        else{
            try{
                return $this->storageService->getUrl($uuid, $category);
            }
            catch(Exception $e){
                Log::error("Error retrieving file", ["UUID"=> $uuid, "category"=> $category]);
                return null;
            }
        }
    }



    public function delete(Attachment $attachment): bool
    {
        try{
            $deleted = $this->storageService->delete($attachment->uuid, $attachment->category);
            if(!$deleted){
                return false;
            }

            $attachment->delete();
            return true;
        }
        catch(Exception $e){
            Log::error(message: "Failed to remove attachment: $e" );
            return false;
        }
    }


    public function convertToAttachmentType($mime){

        if(str_contains($mime, 'pdf') ||
           str_contains($mime, 'word')){
            return 'document';
        }
        if(str_contains($mime, 'image')){
            return 'image';
        }
    }


    public function assignToMessage(AiConvMsg|Message $message, array $data): ?string
    {
        try{
            $category = $message instanceof AiConvMsg ? 'private' : 'group';
            $this->storageService->moveFileToPersistentFolder($data['uuid'], $category);

            $type = $this->convertToAttachmentType($data['mime']);
            $message->attachments()->create([
                'uuid' => $data['uuid'],
                'name' => $data['name'],
                'category' => $category,
                'mime'=> $data['mime'],
                'type'=> $type,
                'user_id'=> Auth::id()
            ]);
            return true;
        }
        catch(Exception $e){
            return false;
        }
    }

    /**
     * Store a base64-encoded image (used for AI-generated images)
     *
     * @param string $base64Data Base64-encoded image data (without data:image/png;base64, prefix)
     * @param string $category Storage category ('private' or 'group')
     * @param string $filename Optional filename (default: 'generated_image.png')
     * @return array|null Array with 'uuid', 'url', 'mime', 'name' or null on failure
     */
    public function storeFromBase64(string $base64Data, string $category, string $filename = 'generated_image.png'): ?array
    {
        try {
            // Remove data URI prefix if present
            if (str_contains($base64Data, 'base64,')) {
                $base64Data = explode('base64,', $base64Data)[1];
            }

            // Decode base64 data
            $imageData = base64_decode($base64Data);
            if ($imageData === false) {
                Log::error("Failed to decode base64 image data");
                return null;
            }

            // Generate UUID for the file
            $uuid = \Illuminate\Support\Str::uuid()->toString();

            // Detect MIME type from decoded data
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($imageData);

            // Determine file extension from MIME type
            $extension = match($mime) {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'png'
            };

            // Use provided filename or generate one
            if (pathinfo($filename, PATHINFO_EXTENSION) === '') {
                $filename = pathinfo($filename, PATHINFO_FILENAME) . '.' . $extension;
            }

            // Store file using FileStorageService
            $stored = $this->storageService->store(
                file: $imageData,
                filename: $filename,
                uuid: $uuid,
                category: $category,
                temp: true
            );

            if (!$stored) {
                Log::error("Failed to store base64 image");
                return null;
            }

            Log::info('[ATTACHMENT SERVICE] Stored base64 image', [
                'uuid' => $uuid,
                'filename' => $filename,
                'category' => $category,
                'temp' => true
            ]);

            // Create Attachment database entry (required for download route)
            $type = $this->convertToAttachmentType($mime);
            \App\Models\Attachment::create([
                'uuid' => $uuid,
                'name' => $filename,
                'category' => $category,
                'mime' => $mime,
                'type' => $type,
                'user_id' => \Illuminate\Support\Facades\Auth::id()
            ]);

            Log::info('[ATTACHMENT SERVICE] Created Attachment database entry', [
                'uuid' => $uuid,
                'filename' => $filename
            ]);

            // Get URL for the stored file
            $url = $this->storageService->getUrl($uuid, $category, true);

            Log::info('[ATTACHMENT SERVICE] Generated URL for base64 image', [
                'uuid' => $uuid,
                'url' => $url
            ]);

            return [
                'uuid' => $uuid,
                'url' => $url,
                'mime' => $mime,
                'name' => $filename
            ];
        } catch (Exception $e) {
            Log::error("Error storing base64 image: " . $e->getMessage());
            return null;
        }
    }

}
