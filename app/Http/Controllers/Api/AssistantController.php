<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiAssistant;
use App\Services\AssistantService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private AssistantService $assistantService
    ) {}

    /**
     * GET /api/assistants
     * List all assistants accessible by the user.
     */
    public function index(Request $request): JsonResponse
    {
        $grouped = $this->assistantService->getGroupedAssistants($request->user());

        return response()->json([
            'success' => true,
            'data' => $grouped,
        ]);
    }

    /**
     * GET /api/assistants/{assistant}
     * Get details of a specific assistant.
     */
    public function show(Request $request, AiAssistant $assistant): JsonResponse
    {
        $this->authorize('view', $assistant);

        $assistant->load(['aiModel', 'files.attachment', 'toolConfigs', 'owner:id,name']);

        // Check if current user has favorited this assistant
        $assistant->is_favorited = $request->user()
            ->favoriteAssistants()
            ->where('assistant_id', $assistant->id)
            ->exists();

        return response()->json([
            'success' => true,
            'data' => $assistant,
        ]);
    }

    /**
     * POST /api/assistants
     * Create a new assistant.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'ai_model' => 'nullable|exists:ai_models,system_id',
            'full_system_prompt' => 'nullable|string',
            'visibility' => 'required|in:private,group,public',
            'required_role' => 'nullable|exists:roles,slug',
            'conversation_starters' => 'nullable|array|max:4',
            'conversation_starters.*' => 'string|max:200',
        ]);

        // Validate role requirement for group visibility
        if ($validated['visibility'] === 'group' && empty($validated['required_role'])) {
            return response()->json([
                'success' => false,
                'message' => 'required_role is required when visibility is group',
            ], 422);
        }

        $assistant = $this->assistantService->createAssistant(
            $request->user(),
            $validated
        );

        return response()->json([
            'success' => true,
            'data' => $assistant,
            'message' => 'Assistant created successfully.',
        ], 201);
    }

    /**
     * PUT /api/assistants/{assistant}
     * Update an assistant.
     */
    public function update(Request $request, AiAssistant $assistant): JsonResponse
    {
        $this->authorize('update', $assistant);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'ai_model' => 'nullable|exists:ai_models,system_id',
            'full_system_prompt' => 'nullable|string',
            'visibility' => 'sometimes|in:private,group,public',
            'status' => 'sometimes|in:draft,active,archived',
            'conversation_starters' => 'nullable|array|max:4',
            'conversation_starters.*' => 'string|max:200',
            'required_role' => 'nullable|exists:roles,slug',
        ]);

        $assistant->update($validated);

        // Clear cache
        $this->assistantService->clearCache($request->user());

        return response()->json([
            'success' => true,
            'data' => $assistant->fresh(),
        ]);
    }

    /**
     * DELETE /api/assistants/{assistant}
     * Delete an assistant.
     */
    public function destroy(Request $request, AiAssistant $assistant): JsonResponse
    {
        $this->authorize('delete', $assistant);

        $assistant->delete();

        // Clear cache
        $this->assistantService->clearCache($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Assistant deleted successfully.',
        ]);
    }

    /**
     * POST /api/assistants/{assistant}/favorite
     * Toggle favorite status.
     */
    public function toggleFavorite(Request $request, AiAssistant $assistant): JsonResponse
    {
        $user = $request->user();

        if ($user->favoriteAssistants()->where('assistant_id', $assistant->id)->exists()) {
            $user->favoriteAssistants()->detach($assistant->id);
            $isFavorite = false;
        } else {
            $user->favoriteAssistants()->attach($assistant->id);
            $isFavorite = true;
        }

        // Clear cache
        $this->assistantService->clearCache($user);

        return response()->json([
            'success' => true,
            'is_favorite' => $isFavorite,
        ]);
    }

    /**
     * POST /api/assistants/{assistant}/use
     * Track usage of an assistant.
     */
    public function trackUsage(Request $request, AiAssistant $assistant): JsonResponse
    {
        $assistant->incrementUsage();

        return response()->json(['success' => true]);
    }
}
