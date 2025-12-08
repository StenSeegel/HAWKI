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

class ModelInfoScreen extends Screen
{
    use OrchidLoggingTrait;

    /**
     * Fetch data to be displayed on the screen.
     *
     * @return array
     */
    public function query(): iterable
    {
        return [];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return 'Model Info';
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        return 'View information about AI models and their capabilities.';
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
        return [
            Button::make('Get Model Info')
                ->icon('bs.download')
                ->method('getModelInfo')
                ->confirm('This will fetch the latest model metadata from the repository and update the local model_info.json file. Continue?'),
        ];
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
    public function getModelInfo(Request $request)
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
                'get_model_info',
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

            Toast::success(
                "Successfully fetched model information: {$modelsCount} models, ".
                "{$providersCount} providers. (Duration: {$duration}ms)"
            );

        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            // Log error
            $this->logScreenOperation(
                'get_model_info',
                'error',
                [
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'duration_ms' => $duration,
                    'initiated_by' => auth()->id(),
                ],
                'error'
            );

            Toast::error("Failed to fetch model information: {$e->getMessage()}");
        }

        return redirect()->back();
    }
}
