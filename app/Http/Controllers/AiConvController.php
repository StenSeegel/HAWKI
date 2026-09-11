<?php

namespace App\Http\Controllers;

use App\Models\AiConv;
use App\Models\AiConvMsg;
use App\Models\Attachment;
use App\Services\Chat\AiConv\AiConvService;
use App\Services\Chat\Attachment\AttachmentService;
use App\Services\Chat\Message\MessageContentValidator;
use App\Services\Chat\Message\MessageHandlerFactory;
use App\Services\Storage\FileStorageService;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;


class AiConvController extends Controller
{
    protected $aiConvService;
    protected $messageHandler;
    protected $contentValidator;
    protected $attachmentService;

    public function __construct(
            AttachmentService $attachmentService,
            AiConvService $aiConvService)
    {
        $this->aiConvService = $aiConvService;
        $this->messageHandler = app(MessageHandlerFactory::class)->create('private');
        $this->contentValidator = new MessageContentValidator();
        $this->attachmentService = $attachmentService;
    }


    ///CREATE NEW CONVERSATION
    public function create(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'conv_name'     => 'nullable|string|max:255',
            'system_prompt' => 'nullable|string'
        ]);

        $conv = $this->aiConvService->create($validatedData);

        return response()->json([
            'success' => true,
            'conv'    => $conv,
        ], 201);
    }


    /// RETURNS CONVERSATION DATA WHICH WILL BE DYNAMICALLY LOADED ON THE PAGE
    public function load($slug): JsonResponse
    {
        $convData = $this->aiConvService->load($slug);
        return response()->json([
            'success' => true,
            'data' => $convData,
        ]);
    }


    public function update(Request $request, $slug): JsonResponse
    {
        $validatedData = $request->validate([
            'system_prompt' => 'string'
        ]);
        $this->aiConvService->update($validatedData, $slug);

        return response()->json([
            'success' => true,
            'response' => "Info updated successfully",
        ]);
    }

    public function delete($slug): JsonResponse
    {
        $this->aiConvService->delete($slug);
        return response()->json([
            'success' => true,
            'message' => 'Conv deleted successfully'
        ]);
    }


    public function sendMessage(Request $request, $slug, MessageContentValidator $contentValidator): JsonResponse {

        $validatedData = $request->validate([
            'isAi' => 'required|boolean',
            'threadId' => 'required|integer|min:0',
            'content' => 'required|array',
            'model' => 'string',
            'completion' => 'required|boolean',
        ]);
        $validatedData['content'] = $contentValidator->validate($validatedData['content']);

        // CREATE MESSAGE
        $conv = AiConv::where('slug', $slug)->firstOrFail();
        $message = $this->messageHandler->create($conv, $validatedData);

        $messageData = $message->createMessageObject();

        // Reload conversation to get updated timestamp
        $conv->refresh();

        return response()->json([
            'success' => true,
            'messageData'=> $messageData,
            'conv_updated_at' => $conv->updated_at->toISOString()
        ]);
    }



    public function updateMessage(Request $request, $slug, MessageContentValidator $contentValidator): JsonResponse {

        $validatedData = $request->validate([
            'isAi' => 'required|boolean',
            'content' => 'required|array',
            'model' => 'nullable|string',
            'completion' => 'required|boolean',
            'message_id' => 'required|string',
        ]);
        $validatedData['content'] = $contentValidator->validate($validatedData['content']);

        $conv = AiConv::where('slug', $slug)->firstOrFail();
        $message = $this->messageHandler->update($conv, $validatedData);

        // Same shape as sendMessage: the frontend re-renders the message from
        // messageData.content.text, so a raw toArray() - where 'content' is the
        // ciphertext string instead of the nested content object - makes it render
        // an empty message and drop the answer that was just streamed.
        $messageData = $message->createMessageObject();

        // Reload conversation to get updated timestamp
        $conv->refresh();

        return response()->json([
            'success' => true,
            'messageData' => $messageData,
            'conv_updated_at' => $conv->updated_at->toISOString()
        ]);
    }

    public function deleteMessage(Request $request, $slug): JsonResponse {
        $validatedData = $request->validate([
            "message_id" => 'required|string|size:5'
        ]);

        $conv = AiConv::where('slug', $slug)->first();
        $deleted = $this->messageHandler->delete($conv, $validatedData);

        return response()->json([
            'success'=> true,
        ]);


    }


    /// ATTACHMENT FUNCTIONS
    ///

    public function storeAttachment(Request $request): JsonResponse {
        $validateData = $request->validate([
            'file' => 'required|file|max:20480'
        ]);
        $result = $this->attachmentService->store($validateData['file'], 'private');
        return response()->json($result);
    }

    /**
     * @throws Exception
     */
    public function getAttachmentUrl(Request $request, string $uuid): JsonResponse
    {

        $attachment = Attachment::where('uuid', $uuid)->firstOrFail();
        if($attachment->user->isNot(Auth::user())){
            throw new AuthorizationException();
        }
        $url = $this->attachmentService->getFileUrl($attachment, null);
        return response()->json([
            'success' => true,
            'url' => $url
        ]);
    }


    /**
     * The file behind a stable attachment url (AttachmentService::viewUrl):
     * shown inline to its owner, from persistent or temp storage.
     */
    public function viewAttachment(string $uuid)
    {
        $attachment = Attachment::where('uuid', $uuid)->firstOrFail();
        if ($attachment->user->isNot(Auth::user())) {
            throw new AuthorizationException();
        }

        return $this->attachmentService->inlineResponse($attachment);
    }

    public function downloadAttachment(string $uuid, string $path)
    {
        try {
            $attachment = Attachment::where('uuid', $uuid)->firstOrFail();
            if($attachment->user->isNot(Auth::user())){
                throw new AuthorizationException();
            }

            $storageService = app(FileStorageService::class);
            try {
                $stream = $storageService->streamFromSignedPath($path); // returns a resource

                return response()->stream(function () use ($stream)
                {
                    fpassthru($stream); // send stream directly to browser
                },
                    200,
                    $this->attachmentService->inlineHeaders($attachment)
                );
            } catch (FileNotFoundException $e) {
                // If the temp file is not found, maybe it was moved to persistent storage.
                // Redirect to the persistent file URL.
                $url = app(AttachmentService::class)->getFileUrl($attachment);
                if ($url) {
                    return redirect($url);
                }
                abort(404, 'File not found');
            }
        } catch (\Exception $e) {
            abort(404, 'File not found');
        }
    }


    public function deleteAttachment(Request $request): JsonResponse {
        $validateData = $request->validate([
            'fileId' => 'required|string',
        ]);

        try{
            $attachment = Attachment::where('uuid', $validateData['fileId'])->firstOrFail();

            if ($attachment->user && !$attachment->user->is(Auth::user())) {
                throw new AuthorizationException();
            }

            if ($attachment->category !== 'private') {
                return response()->json([
                    'success'=> false,
                    'err'=> 'File Id does not match the properties!'
                ], 500);
            }

            // Allow deleting orphaned private attachments (attachable = null),
            // but keep rejecting files attached to non-conversation models.
            if ($attachment->attachable !== null && !$attachment->attachable instanceof AiConvMsg) {
                return response()->json([
                    'success'=> false,
                    'err'=> 'File Id does not match the properties!'
                ], 500);
            }

            $result = $this->attachmentService->delete($attachment);
            return response()->json([
                "success" => $result
            ]);
        }
        catch(Exception $e) {
            Log::error($e);
            throw $e;

        }
    }

    public function updateTitle(Request $request, $slug): JsonResponse
    {
        // Same limit as the conversation name on creation, generated names can exceed 25 characters
        $validatedData = $request->validate(['title' => 'required|string|max:255']);
        $conv = AiConv::where('slug', $slug)->firstOrFail();
        if ($conv->user_id !== Auth::id()) {
            return response()->json(['error' => 'Access denied'], 403);
        }
        $conv->update(['conv_name' => $validatedData['title']]);
        return response()->json(['success' => true]);
    }

    public function loadMoreConversations(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'offset' => 'required|integer|min:0',
            'limit' => 'integer|min:1|max:50'
        ]);

        $offset = $validatedData['offset'];
        $limit = $validatedData['limit'] ?? 20;

        $user = Auth::user();
        $conversations = $user->conversations()
            ->orderBy('updated_at', 'desc')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $hasMore = $user->conversations()->count() > ($offset + $limit);

        return response()->json([
            'success' => true,
            'conversations' => $conversations,
            'hasMore' => $hasMore
        ]);
    }
}
