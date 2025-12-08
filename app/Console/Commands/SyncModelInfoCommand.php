<?php

namespace App\Console\Commands;

use App\Models\AiModel;
use App\Models\AiModelInfo;
use App\Services\ModelInfo\ModelInfoMatcher;
use App\Services\ModelInfo\ModelInfoParser;
use App\Services\ModelInfo\ModelInfoProviderMapper;
use Illuminate\Console\Command;

class SyncModelInfoCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:sync-model-info
                            {--dry-run : Preview changes without saving to database}
                            {--force-update=* : Force update specific fields even if locked (comma-separated)}
                            {--provider= : Only sync models for specific provider ID}
                            {--model= : Only sync specific model ID}
                            {--fresh : Clear JSON cache before syncing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync AI model information from model_info.json to database';

    protected ModelInfoParser $parser;

    protected ModelInfoProviderMapper $providerMapper;

    protected ModelInfoMatcher $matcher;

    /**
     * Statistics tracking.
     */
    protected array $stats = [
        'total' => 0,
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'exact_matches' => 0,
        'base_model_matches' => 0,
        'no_matches' => 0,
        'fields_updated' => 0,
        'fields_skipped_locked' => 0,
    ];

    /**
     * Execute the console command.
     */
    public function handle(
        ModelInfoParser $parser,
        ModelInfoProviderMapper $providerMapper,
        ModelInfoMatcher $matcher
    ): int {
        $this->parser = $parser;
        $this->providerMapper = $providerMapper;
        $this->matcher = $matcher;

        $this->info('🔄 Syncing AI Model Information');
        $this->newLine();

        // Clear cache if requested
        if ($this->option('fresh')) {
            $this->parser->clearCache();
            $this->info('✓ JSON cache cleared');
        }

        // Load and validate JSON file
        if (! $this->validateJsonFile()) {
            return self::FAILURE;
        }

        // Get models to sync
        $models = $this->getModelsToSync();
        $this->stats['total'] = $models->count();

        if ($this->stats['total'] === 0) {
            $this->warn('No models found to sync');

            return self::SUCCESS;
        }

        $this->info("Found {$this->stats['total']} models to sync");
        $this->newLine();

        // Process models
        $this->withProgressBar($models, function ($model) {
            $this->syncModel($model);
        });

        $this->newLine(2);

        // Display results
        $this->displayResults();

        return self::SUCCESS;
    }

    /**
     * Validate JSON file exists and is loadable.
     */
    protected function validateJsonFile(): bool
    {
        try {
            $fileInfo = $this->parser->getFileInfo();

            if (! $fileInfo['exists']) {
                $this->error('❌ Model info JSON file not found!');
                $this->error('Please run the "Get Model Info" button in the admin panel first.');

                return false;
            }

            $this->info("📄 JSON File: {$fileInfo['size_human']} ({$fileInfo['last_modified_human']})");

            $stats = $this->parser->getStatistics();
            $this->info("📊 Contains: {$stats['total_models']} models, {$stats['total_providers']} providers");
            $this->newLine();

            return true;
        } catch (\Exception $e) {
            $this->error("❌ Failed to load JSON: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Get models to sync based on options.
     */
    protected function getModelsToSync()
    {
        $query = AiModel::with('provider', 'modelInfo');

        // Filter by provider if specified
        if ($providerId = $this->option('provider')) {
            $query->where('provider_id', $providerId);
        }

        // Filter by specific model if specified
        if ($modelId = $this->option('model')) {
            $query->where('id', $modelId);
        }

        return $query->get();
    }

    /**
     * Sync a single model.
     */
    protected function syncModel(AiModel $aiModel): void
    {
        try {
            // Match model with JSON data
            $matchResult = $this->matcher->matchAndExtract($aiModel);

            // Track match type
            match ($matchResult['match_type']) {
                'exact' => $this->stats['exact_matches']++,
                'base_model' => $this->stats['base_model_matches']++,
                'none' => $this->stats['no_matches']++,
                default => null,
            };

            // Get or create model info
            $modelInfo = $aiModel->modelInfo;
            $isNew = $modelInfo === null;

            if ($isNew) {
                $modelInfo = new AiModelInfo(['ai_model_id' => $aiModel->id]);
            }

            // Update fields
            $updated = $this->updateModelInfo($modelInfo, $matchResult, $isNew);

            if (! $this->option('dry-run')) {
                if ($updated || $isNew) {
                    $modelInfo->last_matched_at = now();

                    // Temporarily disable observer events for this save
                    \App\Models\AiModelInfo::withoutEvents(function () use ($modelInfo) {
                        $modelInfo->save();
                    });

                    if ($isNew) {
                        $this->stats['created']++;
                    } else {
                        $this->stats['updated']++;
                    }
                } else {
                    $this->stats['skipped']++;
                }
            } else {
                // Dry run - just count what would happen
                if ($updated || $isNew) {
                    $isNew ? $this->stats['created']++ : $this->stats['updated']++;
                } else {
                    $this->stats['skipped']++;
                }
            }
        } catch (\Exception $e) {
            $this->error("Failed to sync model {$aiModel->id} ({$aiModel->label}): {$e->getMessage()}");
        }
    }

    /**
     * Update model info with matched data.
     */
    protected function updateModelInfo(AiModelInfo $modelInfo, array $matchResult, bool $isNew): bool
    {
        $data = $matchResult['data'];
        $updated = false;

        // Get force-update fields
        $forceFields = $this->option('force-update') ? explode(',', implode(',', $this->option('force-update'))) : [];

        // Always update match metadata
        $modelInfo->model_info_id = $matchResult['model_info_id'];
        $modelInfo->matched_provider_id = $matchResult['matched_provider_id'];
        $modelInfo->match_type = $matchResult['match_type'];

        // Update data fields
        foreach ($data as $field => $value) {
            // Skip if field is locked (unless forced)
            if (! $isNew && $modelInfo->isFieldOverwritten($field) && ! in_array($field, $forceFields)) {
                $this->stats['fields_skipped_locked']++;

                continue;
            }

            // Update if value changed
            if ($modelInfo->getAttribute($field) !== $value) {
                $modelInfo->setAttribute($field, $value);
                $this->stats['fields_updated']++;
                $updated = true;
            }
        }

        return $updated;
    }

    /**
     * Display sync results.
     */
    protected function displayResults(): void
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('🔍 DRY RUN - No changes were saved');
            $this->newLine();
        }

        $this->info('📊 Sync Results:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Models', $this->stats['total']],
                ['Created', $this->stats['created']],
                ['Updated', $this->stats['updated']],
                ['Skipped (no changes)', $this->stats['skipped']],
                ['---', '---'],
                ['Exact Matches', $this->stats['exact_matches']],
                ['Base Model Matches', $this->stats['base_model_matches']],
                ['No Matches', $this->stats['no_matches']],
                ['---', '---'],
                ['Fields Updated', $this->stats['fields_updated']],
                ['Fields Skipped (locked)', $this->stats['fields_skipped_locked']],
            ]
        );

        // Success rate
        $matchRate = $this->stats['total'] > 0
            ? round((($this->stats['exact_matches'] + $this->stats['base_model_matches']) / $this->stats['total']) * 100, 1)
            : 0;

        $this->newLine();
        $this->info("✓ Match Rate: {$matchRate}%");

        if ($this->stats['fields_skipped_locked'] > 0) {
            $this->warn("⚠️  {$this->stats['fields_skipped_locked']} field(s) skipped due to manual overwrites");
            $this->info('   Use --force-update=field1,field2 to override locks');
        }

        if ($isDryRun) {
            $this->newLine();
            $this->info('💡 Run without --dry-run to apply changes');
        }
    }
}
