<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\AiModel;
use App\Models\AiModelInfo;

$matched = 0;
$fuzzy = 0;
$none = 0;

$aiModels = AiModel::with('provider')->get();

foreach ($aiModels as $aiModel) {
    $providerUniqueName = $aiModel->provider->unique_name ?? null;
    $modelId = $aiModel->model_id;
    
    if (!$providerUniqueName || !$modelId) {
        $none++;
        continue;
    }
    
    $expectedModelInfoId = "{$providerUniqueName}/{$modelId}";
    $modelInfo = AiModelInfo::where('model_info_id', $expectedModelInfoId)->first();
    
    if ($modelInfo) {
        $aiModel->ai_model_info_id = $modelInfo->id;
        $aiModel->match_type = 'exact';
        $aiModel->last_matched_at = now();
        $aiModel->matching_candidates = [[
            'ai_model_info_id' => $modelInfo->id,
            'model_info_id' => $modelInfo->model_info_id,
            'name' => $modelInfo->name,
            'score' => 1.0,
            'match_type' => 'exact',
        ]];
        $aiModel->save();
        $matched++;
    } else {
        $fuzzyMatches = AiModelInfo::where('base_model_id', $modelId)
            ->where('provider_id', $aiModel->provider_id)
            ->limit(1)
            ->get();
        
        if ($fuzzyMatches->isNotEmpty()) {
            $bestMatch = $fuzzyMatches->first();
            $aiModel->ai_model_info_id = $bestMatch->id;
            $aiModel->match_type = 'fuzzy';
            $aiModel->last_matched_at = now();
            $aiModel->matching_candidates = [[
                'ai_model_info_id' => $bestMatch->id,
                'model_info_id' => $bestMatch->model_info_id,
                'name' => $bestMatch->name,
                'score' => 0.8,
                'match_type' => 'fuzzy',
            ]];
            $aiModel->save();
            $fuzzy++;
        } else {
            $none++;
        }
    }
}

echo "Auto-Matching Results:\n";
echo "  Exact matches: {$matched}\n";
echo "  Fuzzy matches: {$fuzzy}\n";
echo "  No matches: {$none}\n";
