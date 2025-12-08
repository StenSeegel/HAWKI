<?php

declare(strict_types=1);

namespace App\Orchid\Screens\ModelSettings;

use App\Orchid\Layouts\ModelSettings\AiModelTabMenu;
use App\Orchid\Traits\OrchidLoggingTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Toast;

class AiModelSettingsScreen extends Screen
{
    use OrchidLoggingTrait;

    /**
     * Fetch data to be displayed on the screen.
     *
     * @return array
     */
    public function query(): iterable
    {
        // Get file info if exists
        $filePath = 'model_lists/model_info.json';
        $fileExists = Storage::exists($filePath);
        
        $fileInfo = null;
        if ($fileExists) {
            $fileInfo = [
                'exists' => true,
                'size' => Storage::size($filePath),
                'size_human' => $this->formatBytes(Storage::size($filePath)),
                'last_modified' => Storage::lastModified($filePath),
                'last_modified_human' => \Carbon\Carbon::createFromTimestamp(Storage::lastModified($filePath))->diffForHumans(),
            ];
        }

        return [
            'fileInfo' => $fileInfo,
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return 'Model Settings';
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        return 'Manage AI model information and synchronization settings.';
    }

    /**
     * Permission required to access this screen.
     */
    public function permission(): ?iterable
    {
        return [
            'platform.modelsettings.models',
        ];
    }

    /**
     * The screen's action buttons.
     *
     * @return \Orchid\Screen\Action[]
     */
    public function commandBar(): iterable
    {
        // Check if Ollama providers exist
        $hasOllamaProviders = \App\Models\ApiProvider::where('is_active', true)
            ->where(function($query) {
                $query->where('base_url', 'like', '%ollama%')
                      ->orWhere('base_url', 'like', '%:11434%');
            })->exists();

        $buttons = [
            Button::make('Fetch Model Info')
                ->icon('bs.cloud-download')
                ->method('fetchModelInfo')
                ->confirm('This will download the latest model metadata from the repository and update the local model_info.json file. Continue?'),
                
            Button::make('Import & Match Models')
                ->icon('bs.arrow-repeat')
                ->method('syncToDatabase')
                ->confirm('This will import model information from models.hawki.info for all used models and automatically match them. Continue?'),
        ];

        // Add Ollama button only if Ollama providers exist
        if ($hasOllamaProviders) {
            array_splice($buttons, 1, 0, [
                Button::make('Import Ollama')
                    ->icon('bs.server')
                    ->method('importOllama')
                    ->confirm('Import models from Ollama providers?'),
            ]);
        }

        return $buttons;
    }

    /**
     * The screen's layout elements.
     *
     * @return \Orchid\Screen\Layout[]|string[]
     */
    public function layout(): iterable
    {
        return [
            AiModelTabMenu::class,
        ];
    }

    /**
     * Fetch model information from the repository and update local file.
     */
    public function fetchModelInfo(Request $request)
    {
        $startTime = microtime(true);
        $url = 'https://models.hawki.info/models.json';

        try {
            // Fetch model info from repository
            $response = Http::timeout(30)
                ->acceptJson()
                ->get($url);

            if (! $response->successful()) {
                throw new \Exception("HTTP request failed with status {$response->status()}");
            }

            $modelData = $response->json();

            // Validate basic structure
            if (! isset($modelData['models']) || ! is_array($modelData['models'])) {
                throw new \Exception('Invalid model data structure: missing models array');
            }

            if (! isset($modelData['providers']) || ! is_array($modelData['providers'])) {
                throw new \Exception('Invalid model data structure: missing providers array');
            }

            // Store the data in storage/app/model_lists/model_info.json
            $filePath = 'model_lists/model_info.json';

            // Write the file with pretty formatting using Storage facade
            $jsonContent = json_encode($modelData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($jsonContent === false) {
                throw new \Exception('Failed to encode model data to JSON');
            }

            $result = Storage::put($filePath, $jsonContent);

            if ($result === false) {
                throw new \Exception('Failed to write model data to storage');
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $modelsCount = count($modelData['models']);
            $providersCount = count($modelData['providers']);

            // Get full path for logging
            $fullPath = Storage::path($filePath);
            $fileSize = Storage::size($filePath);

            // Log successful operation
            $this->logScreenOperation(
                'fetch_model_info',
                'completed',
                [
                    'url' => $url,
                    'models_count' => $modelsCount,
                    'providers_count' => $providersCount,
                    'file_path' => $fullPath,
                    'file_size_bytes' => $fileSize,
                    'duration_ms' => $duration,
                    'initiated_by' => auth()->id(),
                ]
            );

            Toast::success("Model info fetched successfully! {$modelsCount} models, {$providersCount} providers ({$duration}ms)");

            return redirect()->route('platform.models.settings');
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            // Log error
            $this->logScreenOperation(
                'fetch_model_info',
                'failed',
                [
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'duration_ms' => $duration,
                    'initiated_by' => auth()->id(),
                ]
            );

            Toast::error('Failed to fetch model info: '.$e->getMessage());

            return redirect()->route('platform.models.settings');
        }
    }

    /**
     * Import models from Ollama providers.
     */
    public function importOllama(Request $request)
    {
        $startTime = microtime(true);
        
        try {
            // Run artisan command
            \Artisan::call('model-info:import-ollama');
            $output = \Artisan::output();
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            // Log the operation
            $this->logScreenOperation(
                'import_ollama',
                'completed',
                [
                    'duration_ms' => $duration,
                    'initiated_by' => auth()->id(),
                    'output' => $output,
                ]
            );
            
            Toast::success('Ollama models imported successfully. (Duration: ' . $duration . 'ms)');
            
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->logScreenOperation(
                'import_ollama',
                'error',
                [
                    'error' => $e->getMessage(),
                    'duration_ms' => $duration,
                    'initiated_by' => auth()->id(),
                ]
            );
            
            Toast::error('Failed to import Ollama models: ' . $e->getMessage());
        }
        
        return redirect()->back();
    }

    /**
     * Sync model info to database.
     */
    public function syncToDatabase(Request $request)
    {
        $startTime = microtime(true);

        try {
            // Schritt 1: Import model information from models.hawki.info
            \Artisan::call('model-info:import');
            $importOutput = \Artisan::output();
            
            // Schritt 2: Auto-Match Models mit ai_model_infos
            $this->autoMatchModels();
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            // Log successful operation
            $this->logScreenOperation(
                'sync_model_info',
                'completed',
                [
                    'duration_ms' => $duration,
                    'initiated_by' => auth()->id(),
                    'import_output' => $importOutput,
                ]
            );

            Toast::success("Model information imported and matched successfully! (Duration: {$duration}ms)");

            return redirect()->route('platform.models.language');
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            // Log error
            $this->logScreenOperation(
                'sync_model_info',
                'failed',
                [
                    'error' => $e->getMessage(),
                    'duration_ms' => $duration,
                    'initiated_by' => auth()->id(),
                ]
            );

            Toast::error('Failed to sync model info: '.$e->getMessage());

            return redirect()->route('platform.models.settings');
        }
    }

    /**
     * Auto-match ai_models with ai_model_infos.
     */
    protected function autoMatchModels(): void
    {
        $aiModels = \App\Models\AiModel::with('provider')->get();
        $matchedCount = 0;
        
        foreach ($aiModels as $aiModel) {
            // Erstelle model_info_id aus provider.unique_name + model.model_id
            $providerUniqueName = $aiModel->provider->unique_name ?? null;
            $modelId = $aiModel->model_id;
            
            if (!$providerUniqueName || !$modelId) {
                continue;
            }
            
            // Erwartetes Format: "provider_unique_name/model_id"
            $expectedModelInfoId = "{$providerUniqueName}/{$modelId}";
            
            // Versuche exaktes Match
            $modelInfo = \App\Models\AiModelInfo::where('model_info_id', $expectedModelInfoId)->first();
            
            if ($modelInfo) {
                // Exact Match gefunden!
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
                $matchedCount++;
            } else {
                // Kein exaktes Match - versuche Fuzzy Match über base_model_id
                $fuzzyMatches = \App\Models\AiModelInfo::where('base_model_id', $modelId)
                    ->where('provider_id', $aiModel->provider_id)
                    ->limit(5)
                    ->get();
                
                if ($fuzzyMatches->isNotEmpty()) {
                    $candidates = [];
                    foreach ($fuzzyMatches as $fuzzyMatch) {
                        $candidates[] = [
                            'ai_model_info_id' => $fuzzyMatch->id,
                            'model_info_id' => $fuzzyMatch->model_info_id,
                            'name' => $fuzzyMatch->name,
                            'score' => 0.8,
                            'match_type' => 'fuzzy',
                        ];
                    }
                    
                    // Nehme besten Kandidaten als Match
                    $bestMatch = $fuzzyMatches->first();
                    $aiModel->ai_model_info_id = $bestMatch->id;
                    $aiModel->match_type = 'fuzzy';
                    $aiModel->last_matched_at = now();
                    $aiModel->matching_candidates = $candidates;
                    $aiModel->save();
                    $matchedCount++;
                }
            }
        }
        
        \Log::info("Auto-matched {$matchedCount} models with ai_model_infos");
    }

    /**
     * Format bytes to human readable format.
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision).' '.$units[$i];
    }
}
