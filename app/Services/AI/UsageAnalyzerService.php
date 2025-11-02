<?php

namespace App\Services\AI;

use App\Models\Records\UsageRecord;
use App\Services\AI\Value\TokenUsage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class UsageAnalyzerService
{
    /**
     * Submit a usage record with specific type tracking
     *
     * @param TokenUsage|null $usage
     * @param string $type Supported types: 'private', 'group', 'api', 'title', 'improver', 'summarizer'
     * @param int|null $roomId
     * @return void
     */
    public function submitUsageRecord(?TokenUsage $usage, string $type, ?int $roomId = null): void
    {
        if ($usage === null) {
            return;
        }

        $userId = Auth::user()->id;
        
        // Extract provider unique_name by looking up the model in the database
        // and accessing its provider relation directly
        $apiProvider = null;
        try {
            $modelId = $usage->model->getId();
            $providerId = $usage->model->getProvider()->getConfig()->getId();
            
            // Find the AI model in the database with eager-loaded provider relation
            // Use both model_id AND provider_name to ensure we get the correct model instance
            $aiModel = \App\Models\AiModel::with('provider')
                ->where('model_id', $modelId)
                ->whereHas('provider', function($query) use ($providerId) {
                    $query->where('provider_name', $providerId);
                })
                ->first();
            
            if ($aiModel && $aiModel->provider) {
                $apiProvider = $aiModel->provider->unique_name;
            } else {
                \Log::warning('Could not determine api_provider for usage record', [
                    'model' => $modelId,
                    'provider_name' => $providerId,
                    'ai_model_found' => $aiModel !== null
                ]);
            }
        } catch (\Throwable $e) {
            \Log::error('Error determining api_provider for usage record', [
                'model' => $usage->model->getId(),
                'error' => $e->getMessage()
            ]);
        }

        // Create a new record
        UsageRecord::create([
            'user_id' => $userId,
            'room_id' => $roomId,
            'prompt_tokens' => $usage->promptTokens,
            'completion_tokens' => $usage->completionTokens,
            'type' => $type,
            'api_provider' => $apiProvider,
            'model' => $usage->model->getId(),
        ]);
    }

    public function summarizeAndCleanup()
    {
        $lastMonth = Carbon::now()->subMonth()->format('Y-m');

        // Updated summary logic to include the 'model' column
        $summaries = UsageRecord::selectRaw('user_id, room_id, type, model, SUM(prompt_tokens) as total_prompt_tokens, SUM(completion_tokens) as total_completion_tokens')
            ->whereMonth('created_at', Carbon::now()->subMonth()->month)
            ->whereYear('created_at', Carbon::now()->subMonth()->year)
            ->groupBy('user_id', 'room_id', 'type', 'model')
            ->get();

        foreach ($summaries as $summary) {
            // Store summaries in another table, save to a file, or perform another action
        }

        // Clean up old records
        UsageRecord::whereMonth('created_at', Carbon::now()->subMonth()->month)
            ->whereYear('created_at', Carbon::now()->subMonth()->year)
            ->delete();
    }

}
