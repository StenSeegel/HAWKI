<?php

namespace App\Services;

use App\Models\AiAssistant;
use App\Models\AiAssistantPrompt;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class AssistantService
{
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Get all assistants accessible by a user.
     */
    public function getAccessibleAssistants(User $user): Collection
    {
        $cacheKey = "user_assistants_{$user->id}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
            return AiAssistant::accessibleBy($user)
                ->with(['aiModel', 'owner:id,name'])
                ->orderBy('usage_count', 'desc')
                ->get();
        });
    }

    /**
     * Group assistants by categories for modal display.
     */
    public function getGroupedAssistants(User $user): array
    {
        $assistants = $this->getAccessibleAssistants($user);
        $favorites = $user->favoriteAssistants()->pluck('ai_assistants.id')->toArray();

        // Mark favorites
        $assistants->each(function ($assistant) use ($favorites) {
            $assistant->is_favorited = in_array($assistant->id, $favorites);
        });

        return [
            'featured' => $assistants->where('visibility', 'public')
                ->sortByDesc('usage_count')
                ->take(6)
                ->values(),
            'my_assistants' => $assistants->where('owner_id', $user->id)->values(),
            'organization' => $assistants->where('visibility', 'group')
                ->where('owner_id', '!=', $user->id)
                ->values(),
            'favorites' => $assistants->filter(fn ($a) => $a->is_favorited)->values(),
            'by_category' => $assistants->groupBy('category'),
        ];
    }

    /**
     * Create a new assistant for a user.
     */
    public function createAssistant(User $user, array $data): AiAssistant
    {
        $data['owner_id'] = $user->id;
        $data['status'] = $data['status'] ?? 'draft';

        // Generate unique key if not provided
        if (empty($data['key'])) {
            $data['key'] = $this->generateUniqueKey($data['name']);
        }

        return AiAssistant::create($data);
    }

    /**
     * Build the final system prompt for an assistant.
     */
    public function buildSystemPrompt(AiAssistant $assistant): string
    {
        $prompt = $assistant->full_system_prompt ?? '';

        // Load prompt template if assigned
        if ($assistant->prompt) {
            $promptTemplate = AiAssistantPrompt::where('title', $assistant->prompt)->first();
            if ($promptTemplate) {
                $prompt = $promptTemplate->content."\n\n".$prompt;
            }
        }

        // Add file context if available
        $files = $assistant->files()->with('attachment')->get();
        if ($files->isNotEmpty()) {
            $prompt .= "\n\n## Available Knowledge Base:\n";
            foreach ($files as $file) {
                $prompt .= "- {$file->attachment->name}";
                if ($file->description) {
                    $prompt .= ": {$file->description}";
                }
                $prompt .= "\n";
            }
        }

        return $prompt;
    }

    /**
     * Generate a unique key from name.
     */
    private function generateUniqueKey(string $name): string
    {
        $baseKey = str($name)->slug('_')->lower()->toString();
        $key = $baseKey;
        $counter = 1;

        while (AiAssistant::where('key', $key)->exists()) {
            $key = $baseKey.'_'.$counter;
            $counter++;
        }

        return $key;
    }

    /**
     * Clear assistant cache for a user.
     */
    public function clearCache(User $user): void
    {
        Cache::forget("user_assistants_{$user->id}");
    }
}
