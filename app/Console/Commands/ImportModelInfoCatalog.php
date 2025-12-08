<?php

namespace App\Console\Commands;

use App\Models\AiModelInfo;
use App\Services\ModelInfo\ModelInfoParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportModelInfoCatalog extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'model-info:import-catalog
                            {--fresh : Drop all existing entries before import}
                            {--limit= : Limit the number of models to import (for testing)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import all models from model_info.json into the ai_model_infos catalog table';

    protected ModelInfoParser $parser;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->parser = app(ModelInfoParser::class);

        $this->info('🚀 Starting model info catalog import...');
        $this->newLine();

        // Check if we should clear existing data
        if ($this->option('fresh')) {
            if ($this->confirm('This will delete all existing model info entries. Continue?', false)) {
                $this->warn('Clearing existing model info entries...');
                AiModelInfo::truncate();
                $this->info('✓ Cleared');
            } else {
                $this->error('Import cancelled');
                return self::FAILURE;
            }
        }

        // Get all models from JSON
        $allModels = $this->parser->getAllModels();
        $totalModels = count($allModels);

        if ($totalModels === 0) {
            $this->error('No models found in model_info.json');
            return self::FAILURE;
        }

        $this->info("Found {$totalModels} models in model_info.json");

        // Apply limit if specified
        $limit = $this->option('limit');
        if ($limit) {
            $allModels = array_slice($allModels, 0, (int)$limit);
            $this->warn("Limiting import to {$limit} models for testing");
        }

        $this->newLine();
        $this->info('Importing models...');

        $bar = $this->output->createProgressBar(count($allModels));
        $bar->start();

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($allModels as $modelData) {
            try {
                $result = $this->importModel($modelData);
                
                match ($result) {
                    'created' => $imported++,
                    'updated' => $updated++,
                    'skipped' => $skipped++,
                    default => null,
                };

            } catch (\Exception $e) {
                $errors++;
                Log::error('Failed to import model info', [
                    'model_id' => $modelData['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Show summary
        $this->info('✓ Import completed');
        $this->newLine();
        $this->table(
            ['Status', 'Count'],
            [
                ['Imported (new)', $imported],
                ['Updated (existing)', $updated],
                ['Skipped', $skipped],
                ['Errors', $errors],
                ['Total Processed', count($allModels)],
            ]
        );

        if ($errors > 0) {
            $this->warn("⚠ {$errors} errors occurred. Check logs for details.");
        }

        return self::SUCCESS;
    }

    /**
     * Import or update a single model.
     */
    protected function importModel(array $modelData): string
    {
        $modelInfoId = $modelData['id'];

        // Check if model already exists
        $modelInfo = AiModelInfo::where('model_info_id', $modelInfoId)->first();

        // Extract provider data
        $providersData = $modelData['providers'] ?? [];
        $defaultProvider = !empty($providersData) ? $providersData[0] : null;

        // Prepare data
        $data = [
            'model_info_id' => $modelInfoId,
            'name' => $modelData['name'] ?? null,
            'aliases' => $modelData['aliases'] ?? null,
            'description_en' => $modelData['description']['en'] ?? null,
            'description_de' => $modelData['description']['de'] ?? null,
            'knowledge_cutoff' => isset($modelData['knowledge']) ? $this->parseKnowledgeCutoff($modelData['knowledge']) : null,
            'reasoning' => $modelData['reasoning'] ?? false,
            'tool_calling' => $modelData['toolCalling'] ?? false,
            'open_weights' => $modelData['openWeights'] ?? false,
            'deprecated' => $modelData['deprecated'] ?? false,
            'input_types' => $modelData['input'] ?? null,
            'output_types' => $modelData['output'] ?? null,
            'parameters' => $modelData['parameters'] ?? null,
            'default_parameters' => $modelData['defaultParameters'] ?? null,
            'providers' => $providersData,
            'context_length' => $modelData['contextLength'] ?? $defaultProvider['contextLength'] ?? null,
            'output_limit' => $modelData['outputLimit'] ?? $defaultProvider['outputLimit'] ?? null,
            'last_imported_at' => $modelData['lastImportedAt'] ?? now(),
            'import_source' => 'model_info.json',
        ];

        // Extract pricing from default provider
        if ($defaultProvider && isset($defaultProvider['price'])) {
            $price = $defaultProvider['price'];
            
            if (isset($price['usd'])) {
                $data['price_input_usd'] = $price['usd']['input'] ?? null;
                $data['price_output_usd'] = $price['usd']['output'] ?? null;
            }
            
            if (isset($price['eur'])) {
                $data['price_input_eur'] = $price['eur']['input'] ?? null;
                $data['price_output_eur'] = $price['eur']['output'] ?? null;
            }
        }

        if ($modelInfo) {
            // Update existing
            $modelInfo->update($data);
            return 'updated';
        } else {
            // Create new
            AiModelInfo::create($data);
            return 'created';
        }
    }

    /**
     * Parse knowledge cutoff date from various formats.
     */
    protected function parseKnowledgeCutoff($knowledge): ?\DateTime
    {
        if (empty($knowledge)) {
            return null;
        }

        try {
            return new \DateTime($knowledge);
        } catch (\Exception $e) {
            return null;
        }
    }
}
