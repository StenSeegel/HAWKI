<?php

namespace App\Console\Commands;

use App\Models\AiModel;
use App\Models\AiModelInfo;
use App\Services\ModelInfo\ModelInfoParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateModelMatchingCandidates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'model-info:generate-candidates
                            {--auto-link : Automatically link exact matches}
                            {--model= : Process only a specific model by ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate matching candidates for AI models from the model info catalog';

    protected ModelInfoParser $parser;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->parser = app(ModelInfoParser::class);

        $this->info('🔍 Generating matching candidates for AI models...');
        $this->newLine();

        // Get models to process
        $query = AiModel::query();
        
        if ($modelId = $this->option('model')) {
            $query->where('id', $modelId);
        }

        $models = $query->get();
        $totalModels = $models->count();

        if ($totalModels === 0) {
            $this->error('No models found to process');
            return self::FAILURE;
        }

        $this->info("Processing {$totalModels} models...");
        $this->newLine();

        $bar = $this->output->createProgressBar($totalModels);
        $bar->start();

        $stats = [
            'exact_matches' => 0,
            'fuzzy_matches' => 0,
            'no_matches' => 0,
            'auto_linked' => 0,
            'errors' => 0,
        ];

        foreach ($models as $model) {
            try {
                $result = $this->generateCandidates($model);
                $stats[$result]++;
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('Failed to generate candidates', [
                    'model_id' => $model->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Show summary
        $this->info('✓ Candidate generation completed');
        $this->newLine();
        $this->table(
            ['Status', 'Count'],
            [
                ['Exact Matches', $stats['exact_matches']],
                ['Fuzzy Matches', $stats['fuzzy_matches']],
                ['No Matches', $stats['no_matches']],
                ['Auto-Linked', $stats['auto_linked']],
                ['Errors', $stats['errors']],
                ['Total Processed', $totalModels],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Generate matching candidates for a single model.
     */
    protected function generateCandidates(AiModel $model): string
    {
        $searchTerm = $model->model_id;
        $candidates = [];
        $shouldAutoLink = false;

        // 1. Try exact match by ID
        $exactMatch = $this->findBestMatch($searchTerm);
        
        if ($exactMatch) {
            $candidates[] = $exactMatch;

            // Mark for auto-linking if option is set and it's a high-confidence match
            if ($this->option('auto-link') && !$model->ai_model_info_id && $exactMatch['score'] >= 0.95) {
                $shouldAutoLink = true;
            }
        }

        // 2. Try fuzzy search for additional candidates
        $similarModels = $this->parser->searchModels($searchTerm, 10);
        
        foreach ($similarModels as $modelData) {
            // Skip if already added as exact match
            if (!empty($candidates) && $candidates[0]['model_info_id'] === $modelData['id']) {
                continue;
            }

            $modelInfo = AiModelInfo::where('model_info_id', $modelData['id'])->first();
            
            if ($modelInfo) {
                // Calculate similarity score
                $score = $this->calculateSimilarity($searchTerm, $modelData['id']);
                
                $candidates[] = [
                    'ai_model_info_id' => $modelInfo->id,
                    'model_info_id' => $modelInfo->model_info_id,
                    'score' => $score,
                    'match_type' => 'fuzzy',
                    'name' => $modelInfo->name,
                ];
            }
        }
        
        // 2.5. Search for variants with suffixes (e.g., gpt-oss-120b:exacto, gpt-4o-search-preview)
        // Strip provider prefix if present (e.g., jlu/gpt-oss-120b -> gpt-oss-120b)
        $baseSearchTerm = $searchTerm;
        if (str_contains($searchTerm, '/')) {
            $parts = explode('/', $searchTerm);
            $baseSearchTerm = end($parts);
        }
        
        // Search for variants with different suffix patterns
        $variantModels = AiModelInfo::where(function($query) use ($baseSearchTerm) {
            // Pattern 1: Colon variants (e.g., gpt-oss-120b:exacto)
            $query->where('model_info_id', 'like', $baseSearchTerm . ':%')
                  // Pattern 2: Free variants (e.g., gpt-oss-120b-free)
                  ->orWhere('model_info_id', 'like', $baseSearchTerm . '-free')
                  ->orWhere('model_info_id', 'like', $baseSearchTerm . ':free');
        })->get();
        
        // Additional: Search for base models when we have a variant
        // E.g., "gpt-4o-search-preview" should also match "gpt-4o"
        $baseVariants = [];
        if (preg_match('/^(.+?)-(search|preview|mini|turbo|instruct|chat|vision|audio|realtime|snapshot|latest|extended)/', $baseSearchTerm, $matches)) {
            $baseModel = $matches[1];
            $baseVariant = AiModelInfo::where('model_info_id', $baseModel)->first();
            if ($baseVariant) {
                $baseVariants[] = $baseVariant;
            }
        }
        
        $variantModels = $variantModels->merge($baseVariants);
        
        foreach ($variantModels as $modelInfo) {
            // Skip if already in candidates
            $alreadyAdded = false;
            foreach ($candidates as $candidate) {
                if ($candidate['model_info_id'] === $modelInfo->model_info_id) {
                    $alreadyAdded = true;
                    break;
                }
            }
            
            if (!$alreadyAdded) {
                $candidates[] = [
                    'ai_model_info_id' => $modelInfo->id,
                    'model_info_id' => $modelInfo->model_info_id,
                    'score' => 0.95, // High score for variants
                    'match_type' => 'fuzzy',
                    'name' => $modelInfo->name,
                ];
            }
        }

        // 3. If no candidates, try broader search
        if (empty($candidates)) {
            $parts = explode('-', $searchTerm);
            $parts = array_filter($parts, fn($p) => !preg_match('/^\d/', $p)); // Remove parts starting with numbers
            
            if (!empty($parts)) {
                $broadTerm = $parts[0];
                $broadModels = $this->parser->searchModels($broadTerm, 5);
                
                foreach ($broadModels as $modelData) {
                    $modelInfo = AiModelInfo::where('model_info_id', $modelData['id'])->first();
                    
                    if ($modelInfo) {
                        $score = $this->calculateSimilarity($searchTerm, $modelData['id']) * 0.7; // Lower score for broad matches
                        
                        $candidates[] = [
                            'ai_model_info_id' => $modelInfo->id,
                            'model_info_id' => $modelInfo->model_info_id,
                            'score' => $score,
                            'match_type' => 'fuzzy',
                            'name' => $modelInfo->name,
                        ];
                    }
                }
            }
        }

        // Sort by score
        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);

        // Limit to top 10
        $candidates = array_slice($candidates, 0, 10);

        // Save candidates
        $model->matching_candidates = $candidates;
        $model->save();

        // Perform auto-linking after collecting all candidates
        if ($shouldAutoLink && !empty($candidates)) {
            $model->ai_model_info_id = $candidates[0]['ai_model_info_id'];
            $model->match_type = $candidates[0]['match_type'];
            $model->last_matched_at = now();
            $model->save();
            
            return 'auto_linked';
        }

        if (empty($candidates)) {
            return 'no_matches';
        }

        return $candidates[0]['match_type'] === 'exact' ? 'exact_matches' : 'fuzzy_matches';
    }

    /**
     * Find the best match for a model ID using the parser.
     */
    protected function findBestMatch(string $searchTerm): ?array
    {
        // Try exact ID match using the parser
        $exactModel = $this->parser->findModel($searchTerm);
        if ($exactModel) {
            $modelInfo = AiModelInfo::where('model_info_id', $exactModel['id'])->first();
            if ($modelInfo) {
                return [
                    'ai_model_info_id' => $modelInfo->id,
                    'model_info_id' => $modelInfo->model_info_id,
                    'score' => 1.0,
                    'match_type' => 'exact',
                    'name' => $modelInfo->name,
                ];
            }
        }

        // Check aliases in database
        $modelInfo = AiModelInfo::whereJsonContains('aliases', $searchTerm)->first();
        if ($modelInfo) {
            return [
                'ai_model_info_id' => $modelInfo->id,
                'model_info_id' => $modelInfo->model_info_id,
                'score' => 1.0,
                'match_type' => 'exact',
                'name' => $modelInfo->name,
            ];
        }

        return null;
    }

    /**
     * Calculate similarity score between two strings.
     */
    protected function calculateSimilarity(string $a, string $b): float
    {
        // Normalize strings
        $a = strtolower($a);
        $b = strtolower($b);

        // Exact match
        if ($a === $b) {
            return 1.0;
        }

        // Use similar_text for basic similarity
        similar_text($a, $b, $percent);
        
        return round($percent / 100, 2);
    }
}
