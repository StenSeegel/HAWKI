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
        $apiProvider = null;
        try {
            $modelId = $usage->model->getId();
            
            // Find the AI model in the database
            $aiModel = \App\Models\AiModel::where('model_id', $modelId)->first();
            
            if ($aiModel && $aiModel->provider_id) {
                // Get the provider's unique_name
                $provider = \App\Models\ApiProvider::find($aiModel->provider_id);
                if ($provider && $provider->unique_name) {
                    $apiProvider = $provider->unique_name;
                }
            }
            
            // If database lookup failed, log a warning
            if ($apiProvider === null) {
                \Log::warning('Could not determine api_provider for usage record', [
                    'model' => $modelId,
                    'ai_model_found' => $aiModel !== null,
                    'provider_id' => $aiModel->provider_id ?? null
                ]);
            }
        } catch (\Throwable $e) {
            // If lookup fails, log error and leave api_provider as null
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
