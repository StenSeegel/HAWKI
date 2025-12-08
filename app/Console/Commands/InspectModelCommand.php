<?php

namespace App\Console\Commands;

use App\Models\AiModel;
use Illuminate\Console\Command;

class InspectModelCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'model:inspect 
                            {system_id? : The system ID of the model to inspect}
                            {--all : Show all models}
                            {--locked : Show only models with locked fields}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inspect AI model details including model info and locked fields';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('all')) {
            return $this->showAllModels();
        }

        if ($this->option('locked')) {
            return $this->showLockedModels();
        }

        $systemId = $this->argument('system_id');

        if (!$systemId) {
            $this->error('Please provide a system_id or use --all or --locked option');
            $this->info('Usage: php artisan model:inspect <system_id>');
            $this->info('       php artisan model:inspect --all');
            $this->info('       php artisan model:inspect --locked');
            return Command::FAILURE;
        }

        return $this->inspectModel($systemId);
    }

    /**
     * Inspect a specific model.
     */
    protected function inspectModel(string $systemId): int
    {
        $model = AiModel::where('system_id', $systemId)
            ->with(['provider', 'modelInfo'])
            ->first();

        if (!$model) {
            $this->error("Model not found with system_id: {$systemId}");
            return Command::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>AI MODEL</>', '');
        $this->table(
            ['Property', 'Value'],
            [
                ['ID', $model->id],
                ['System ID', $model->system_id],
                ['Model ID', $model->model_id],
                ['Label', $model->label],
                ['Provider', $model->provider ? $model->provider->unique_name : 'N/A'],
                ['Active', $model->is_active ? '✓ Yes' : '✗ No'],
                ['Visible', $model->is_visible ? '✓ Yes' : '✗ No'],
                ['Streamable', $model->streamable ? '✓ Yes' : '✗ No'],
                ['Match Type', $model->match_type],
                ['Last Matched', $model->last_matched_at ? $model->last_matched_at->format('Y-m-d H:i:s') : 'Never'],
            ]
        );

        if ($model->modelInfo) {
            $info = $model->modelInfo;
            
            $this->newLine();
            $this->components->twoColumnDetail('<fg=cyan>MODEL INFO</>', '');
            $this->table(
                ['Property', 'Value'],
                [
                    ['ID', $info->id],
                    ['Model Info ID', $info->model_info_id],
                    ['Base Model ID', $info->base_model_id],
                    ['Model Family', $info->model_family],
                    ['Name', $info->name],
                ]
            );

            $this->newLine();
            $this->components->twoColumnDetail('<fg=cyan>TECHNICAL SPECS</>', '');
            $this->table(
                ['Property', 'Value'],
                [
                    ['Context Length', number_format($info->context_length ?? 0) . ' tokens'],
                    ['Output Limit', number_format($info->output_limit ?? 0) . ' tokens'],
                    ['Knowledge Cutoff', $info->knowledge_cutoff ? $info->knowledge_cutoff->format('Y-m-d') : 'N/A'],
                ]
            );

            $this->newLine();
            $this->components->twoColumnDetail('<fg=cyan>PRICING</>', '');
            $this->table(
                ['Type', 'USD (per 1M tokens)', 'EUR (per 1M tokens)'],
                [
                    ['Input', '$' . number_format($info->price_input_usd, 4), '€' . number_format($info->price_input_eur, 4)],
                    ['Output', '$' . number_format($info->price_output_usd, 4), '€' . number_format($info->price_output_eur, 4)],
                ]
            );

            $this->newLine();
            $this->components->twoColumnDetail('<fg=cyan>CAPABILITIES</>', '');
            $this->table(
                ['Capability', 'Status'],
                [
                    ['Reasoning', $info->reasoning ? '✓ Yes' : '✗ No'],
                    ['Tool Calling', $info->tool_calling ? '✓ Yes' : '✗ No'],
                    ['Open Weights', $info->open_weights ? '✓ Yes' : '✗ No'],
                    ['Deprecated', $info->deprecated ? '⚠ Yes' : '✓ No'],
                    ['Input Types', json_encode($info->input_types)],
                    ['Output Types', json_encode($info->output_types)],
                ]
            );

            $this->newLine();
            $this->components->twoColumnDetail('<fg=cyan>IMPORT INFO</>', '');
            $this->table(
                ['Property', 'Value'],
                [
                    ['Last Imported', $info->last_imported_at ? $info->last_imported_at->format('Y-m-d H:i:s') : 'Never'],
                    ['Import Source', $info->import_source],
                    ['Source URL', $info->source_url ?? 'N/A'],
                ]
            );

            // Locked Fields
            $this->newLine();
            $lockedFields = $info->locked_fields ?? [];
            if (empty($lockedFields)) {
                $this->components->info('No fields locked - all fields will be synced from models.hawki.info');
            } else {
                $this->components->warn('🔒 LOCKED FIELDS (' . count($lockedFields) . ' - will NOT be updated during sync)');
                $rows = [];
                foreach ($lockedFields as $field) {
                    $value = $info->$field;
                    if (is_numeric($value)) {
                        $value = number_format($value, 10);
                    }
                    $rows[] = [$field, $value];
                }
                $this->table(['Field', 'Custom Value'], $rows);
            }
        } else {
            $this->newLine();
            $this->components->error('No model info linked');
            
            if (!empty($model->matching_candidates)) {
                $this->newLine();
                $this->components->twoColumnDetail('<fg=cyan>MATCHING CANDIDATES</>', '');
                $rows = [];
                foreach ($model->matching_candidates as $candidate) {
                    $rows[] = [
                        $candidate['model_info_id'] ?? 'N/A',
                        $candidate['name'] ?? 'N/A',
                        $candidate['match_type'] ?? 'unknown',
                        (($candidate['score'] ?? 0) * 100) . '%',
                    ];
                }
                $this->table(['Model Info ID', 'Name', 'Match Type', 'Score'], $rows);
            }
        }

        $this->newLine();

        return Command::SUCCESS;
    }

    /**
     * Show all models with basic info.
     */
    protected function showAllModels(): int
    {
        $models = AiModel::with(['provider', 'modelInfo'])->get();

        $this->components->info('Total models: ' . $models->count());
        $this->newLine();

        $rows = [];
        foreach ($models as $model) {
            $rows[] = [
                substr($model->system_id, 0, 8) . '...',
                $model->label,
                $model->provider ? $model->provider->unique_name : 'N/A',
                $model->is_active ? '✓' : '✗',
                $model->modelInfo ? '✓' : '✗',
                $model->modelInfo ? count($model->modelInfo->locked_fields ?? []) : 0,
            ];
        }

        $this->table(
            ['System ID', 'Label', 'Provider', 'Active', 'Info', 'Locked'],
            $rows
        );

        return Command::SUCCESS;
    }

    /**
     * Show models with locked fields.
     */
    protected function showLockedModels(): int
    {
        $models = AiModel::with(['provider', 'modelInfo'])
            ->whereHas('modelInfo', function ($query) {
                $query->whereNotNull('locked_fields')
                    ->whereRaw('JSON_LENGTH(locked_fields) > 0');
            })
            ->get();

        $this->components->info('Models with locked fields: ' . $models->count());
        $this->newLine();

        if ($models->isEmpty()) {
            $this->components->warn('No models with locked fields found');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($models as $model) {
            $lockedFields = $model->modelInfo->locked_fields ?? [];
            $rows[] = [
                $model->system_id,
                $model->label,
                $model->provider ? $model->provider->unique_name : 'N/A',
                count($lockedFields),
                implode(', ', $lockedFields),
            ];
        }

        $this->table(
            ['System ID', 'Label', 'Provider', 'Locked Count', 'Locked Fields'],
            $rows
        );

        return Command::SUCCESS;
    }
}
