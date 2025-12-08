<?php

namespace App\Console\Commands;

use App\Models\AiModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoSelectModelProviders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'model-info:auto-select-providers
                            {--dry-run : Show what would be done without making changes}
                            {--force : Force update even if provider already selected}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically select providers for AI models based on their HAWKI provider';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('🔍 Auto-selecting providers for AI models...');
        $this->newLine();

        // Get models with model_info but no selected_provider_id (or all if --force)
        $query = AiModel::whereNotNull('ai_model_info_id')
            ->whereNotNull('provider_id')
            ->with(['modelInfo', 'provider']);

        if (!$force) {
            $query->whereNull('selected_provider_id');
        }

        $models = $query->get();

        if ($models->isEmpty()) {
            $this->warn('No models found that need provider selection.');
            return 0;
        }

        $this->info("Found {$models->count()} models to process" . ($isDryRun ? ' (DRY RUN)' : ''));
        $this->newLine();

        $stats = [
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $progressBar = $this->output->createProgressBar($models->count());
        $progressBar->start();

        foreach ($models as $model) {
            try {
                $result = $this->autoSelectProvider($model, $isDryRun);
                
                if ($result) {
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('Failed to auto-select provider', [
                    'model_id' => $model->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Show results
        $this->info('✓ Provider auto-selection completed');
        $this->newLine();

        $this->table(
            ['Status', 'Count'],
            [
                ['Updated', $stats['updated']],
                ['Skipped', $stats['skipped']],
                ['Errors', $stats['errors']],
                ['Total', $models->count()],
            ]
        );

        return 0;
    }

    /**
     * Auto-select provider for a model.
     */
    protected function autoSelectProvider(AiModel $model, bool $isDryRun = false): bool
    {
        if (!$model->modelInfo || !$model->provider) {
            return false;
        }

        $modelInfo = $model->modelInfo;
        $hawkiProvider = $model->provider;
        
        $providerUniqueName = $hawkiProvider->unique_name;
        
        if (!$providerUniqueName || !$modelInfo->providers) {
            return false;
        }
        
        // Normalize provider name
        $normalizedName = strtolower($providerUniqueName);
        $baseName = preg_replace('/-(?:usa|eu|prod|dev|staging)$/', '', $normalizedName);
        
        $matchedProviderId = null;
        
        // Strategy 1: Exact match
        foreach ($modelInfo->providers as $provider) {
            if (!isset($provider['providerId'])) {
                continue;
            }
            
            $providerId = strtolower($provider['providerId']);
            
            if ($providerId === $normalizedName || $providerId === $baseName) {
                $matchedProviderId = $provider['providerId'];
                break;
            }
        }
        
        // Strategy 2: Contains match
        if (!$matchedProviderId) {
            foreach ($modelInfo->providers as $provider) {
                if (!isset($provider['providerId'])) {
                    continue;
                }
                
                $providerId = strtolower($provider['providerId']);
                
                if (str_contains($baseName, $providerId) || str_contains($providerId, $baseName)) {
                    $matchedProviderId = $provider['providerId'];
                    break;
                }
            }
        }
        
        // Strategy 3: Common variations
        if (!$matchedProviderId) {
            $variations = $this->getProviderVariations($baseName);
            
            foreach ($variations as $variation) {
                foreach ($modelInfo->providers as $provider) {
                    if (!isset($provider['providerId'])) {
                        continue;
                    }
                    
                    $providerId = strtolower($provider['providerId']);
                    
                    if ($providerId === $variation || str_contains($providerId, $variation)) {
                        $matchedProviderId = $provider['providerId'];
                        break 2;
                    }
                }
            }
        }
        
        // Set the matched provider
        if ($matchedProviderId) {
            if (!$isDryRun) {
                $model->selected_provider_id = $matchedProviderId;
                $model->save();
            }
            
            $this->newLine();
            $this->line("  ✓ {$model->model_id}: {$providerUniqueName} → {$matchedProviderId}");
            
            return true;
        } else {
            // Fallback to first provider
            $firstProvider = $modelInfo->providers[0] ?? null;
            if ($firstProvider && isset($firstProvider['providerId'])) {
                if (!$isDryRun) {
                    $model->selected_provider_id = $firstProvider['providerId'];
                    $model->save();
                }
                
                $this->newLine();
                $this->line("  ⚠ {$model->model_id}: {$providerUniqueName} → {$firstProvider['providerId']} (fallback)");
                
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get common variations of provider names.
     */
    protected function getProviderVariations(string $baseName): array
    {
        $variations = [$baseName];
        
        $transformations = [
            'openai' => ['openai', 'open-ai'],
            'togetherai' => ['together', 'together-ai', 'togetherai'],
            'fireworks' => ['fireworks', 'fireworks-ai'],
            'google' => ['google', 'google-ai', 'gemini'],
            'anthropic' => ['anthropic', 'claude'],
            'cloudflare' => ['cloudflare', 'cloudflare-workers-ai', 'cf'],
            'gwdg' => ['gwdg', 'openrouter'],
        ];
        
        foreach ($transformations as $pattern => $alternates) {
            if (str_contains($baseName, $pattern)) {
                $variations = array_merge($variations, $alternates);
            }
        }
        
        return array_unique($variations);
    }
}
