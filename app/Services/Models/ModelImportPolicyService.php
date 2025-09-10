<?php

declare(strict_types=1);

namespace App\Services\Models;

use App\Models\LanguageModel;
use App\Models\ProviderSetting;
use Illuminate\Support\Facades\Log;

class ModelImportPolicyService
{
    /**
     * Import models from API response with consistent policy
     *
     * @param int $providerId Provider ID
     * @param array $apiResponse Raw API response
     * @param bool $activateNewModels Whether to activate new models (default: false)
     * @param bool $makeNewModelsVisible Whether to make new models visible (default: false)
     * @return array Import results with statistics
     */
    public function importModelsFromApiResponse(
        int $providerId,
        array $apiResponse,
        bool $activateNewModels = false,
        bool $makeNewModelsVisible = false
    ): array {
        try {
            $totalModels = 0;
            $importedModels = 0;
            $updatedModels = 0;
            $errors = [];

            // Get the provider settings
            $provider = ProviderSetting::find($providerId);
            if (! $provider) {
                return [
                    'success' => false,
                    'error' => "Provider with ID {$providerId} not found",
                    'total' => 0,
                    'imported' => 0,
                    'updated' => 0,
                ];
            }

            // Extract models from API response
            $models = $this->extractModelsFromApiResponse($apiResponse);
            $totalModels = count($models);

            if (empty($models)) {
                return [
                    'success' => true,
                    'total' => 0,
                    'imported' => 0,
                    'updated' => 0,
                    'message' => 'No models found in API response',
                ];
            }

            foreach ($models as $modelData) {
                try {
                    // Extract model ID from different API formats
                    $modelId = $this->extractModelId($modelData);
                    if (! $modelId) {
                        $errors[] = 'Model missing ID/name field: ' . json_encode(array_keys($modelData));
                        continue;
                    }

                    // Check if model already exists
                    $existingModel = LanguageModel::where('model_id', $modelId)
                        ->where('provider_id', $providerId)
                        ->first();

                    if ($existingModel) {
                        // Update existing model (preserving user settings)
                        $this->updateExistingModel($existingModel, $modelData);
                        $updatedModels++;
                        Log::info("Updated model: {$modelId} for provider {$provider->provider_name}");
                    } else {
                        // Create new model
                        $this->createNewModel($providerId, $modelId, $modelData, $activateNewModels, $makeNewModelsVisible);
                        $importedModels++;
                        Log::info("Imported new model: {$modelId} for provider {$provider->provider_name}");
                    }

                } catch (\Exception $e) {
                    $errors[] = "Error processing model {$modelId}: " . $e->getMessage();
                    Log::error("Error importing model {$modelId}: " . $e->getMessage());
                }
            }

            $success = $importedModels > 0 || $updatedModels > 0 || empty($errors);

            return [
                'success' => $success,
                'total' => $totalModels,
                'imported' => $importedModels,
                'updated' => $updatedModels,
                'errors' => $errors,
            ];

        } catch (\Exception $e) {
            Log::error('Error importing models from API response: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'total' => 0,
                'imported' => 0,
                'updated' => 0,
            ];
        }
    }

    /**
     * Extract model ID from different API response formats
     */
    private function extractModelId(array $modelData): ?string
    {
        // Handle different API response formats
        if (isset($modelData['name']) && str_starts_with($modelData['name'], 'models/')) {
            // Google API format: name = "models/gemini-pro"
            return $modelData['name']; // Keep full name with models/ prefix
        } elseif (isset($modelData['id']) && isset($modelData['display_name'])) {
            // Anthropic API format: id = "claude-3-opus-20240229", display_name = "Claude Opus 3"
            return $modelData['id'];
        } elseif (isset($modelData['id'])) {
            // OpenAI/Standard format: id = "gpt-4"
            return $modelData['id'];
        } elseif (isset($modelData['name'])) {
            // Ollama/Other format: name = "llama2"
            return $modelData['name'];
        }

        return null;
    }

    /**
     * Extract models array from different API response formats
     */
    private function extractModelsFromApiResponse(array $apiResponse): array
    {
        // OpenAI format: {"object": "list", "data": [...]}
        if (isset($apiResponse['data']) && is_array($apiResponse['data'])) {
            return $apiResponse['data'];
        }

        // Alternative format: {"models": [...]}
        if (isset($apiResponse['models']) && is_array($apiResponse['models'])) {
            return $apiResponse['models'];
        }

        // Direct array format: [...]
        if (is_array($apiResponse) && !empty($apiResponse)) {
            $firstItem = reset($apiResponse);
            if (is_array($firstItem) && (isset($firstItem['id']) || isset($firstItem['name']))) {
                return $apiResponse;
            }
        }

        return [];
    }

    /**
     * Update existing model - ONLY update metadata, preserve user settings
     */
    private function updateExistingModel(LanguageModel $existingModel, array $modelData): void
    {
        $updateAttributes = [
            // ONLY update API metadata, preserve user-configured settings
            'information' => json_encode($modelData),
            'updated_at' => now(),
        ];

        // DO NOT update these user-configured fields:
        // - label (user may have customized the display name)
        // - is_active (user has consciously activated/deactivated)
        // - is_visible (user has consciously made visible/invisible)
        // - display_order (user has arranged the order)
        // - settings (user-specific configurations)
        // - streamable (user may have overridden for specific models)

        $existingModel->update($updateAttributes);
    }

    /**
     * Create new model with policy-compliant defaults
     */
    private function createNewModel(
        int $providerId,
        string $modelId,
        array $modelData,
        bool $activateNewModels,
        bool $makeNewModelsVisible
    ): void {
        // Generate a human-readable label
        $label = $this->generateModelLabel($modelId, $modelData);

        // Determine if model should be streamable (default true unless specified)
        $streamable = $this->determineStreamableCapability($modelId);

        $modelAttributes = [
            'model_id' => $modelId,
            'provider_id' => $providerId,
            'label' => $label,
            'streamable' => $streamable,
            'is_active' => $activateNewModels,           // Policy: default false
            'is_visible' => $makeNewModelsVisible,       // Policy: default false
            'display_order' => 1000,                     // Default order, can be adjusted later
            'information' => json_encode($modelData),
            'settings' => json_encode([]),
        ];

        LanguageModel::create($modelAttributes);
    }

    /**
     * Generate a human-readable label for a model
     */
    private function generateModelLabel(string $modelId, array $modelData): string
    {
        // Use display name if available
        if (isset($modelData['displayName'])) {
            return $modelData['displayName'];
        }

        // Use display_name if available
        if (isset($modelData['display_name'])) {
            return $modelData['display_name'];
        }

        // Use name if available and different from ID
        if (isset($modelData['name']) && $modelData['name'] !== $modelId) {
            return $modelData['name'];
        }

        // For Google models with "models/" prefix, clean up the display name
        if (str_starts_with($modelId, 'models/')) {
            $cleanName = str_replace('models/', '', $modelId);
            return 'Google ' . ucwords(str_replace('-', ' ', $cleanName));
        }

        // For OpenAI models, create more readable labels
        $patterns = [
            '/^gpt-4o-mini/' => 'OpenAI GPT-4o Mini',
            '/^gpt-4o/' => 'OpenAI GPT-4o',
            '/^gpt-4-turbo/' => 'OpenAI GPT-4 Turbo',
            '/^gpt-4/' => 'OpenAI GPT-4',
            '/^gpt-3\.5-turbo/' => 'OpenAI GPT-3.5 Turbo',
            '/^o1-mini/' => 'OpenAI o1-mini',
            '/^o1-preview/' => 'OpenAI o1-preview',
            '/^o3-mini/' => 'OpenAI o3-mini',
            '/^text-davinci/' => 'OpenAI Text Davinci',
            '/^text-embedding/' => 'OpenAI Text Embedding',
            '/^whisper/' => 'OpenAI Whisper',
            '/^dall-e/' => 'OpenAI DALL-E',
            '/^claude-3-5-sonnet/' => 'Anthropic Claude 3.5 Sonnet',
            '/^claude-3-opus/' => 'Anthropic Claude 3 Opus',
            '/^claude-3-haiku/' => 'Anthropic Claude 3 Haiku',
            '/^gemini-1\.5-pro/' => 'Google Gemini 1.5 Pro',
            '/^gemini-1\.5-flash/' => 'Google Gemini 1.5 Flash',
        ];

        foreach ($patterns as $pattern => $label) {
            if (preg_match($pattern, $modelId)) {
                return $label;
            }
        }

        // Default: use the model ID as-is
        return $modelId;
    }

    /**
     * Determine if a model should be streamable based on its ID
     */
    private function determineStreamableCapability(string $modelId): bool
    {
        // For OpenAI reasoning models (o1, o3 series), disable streaming
        if (str_contains($modelId, 'o1') || str_contains($modelId, 'o3')) {
            return false;
        }

        // For embedding models, typically no streaming
        if (str_contains($modelId, 'embedding')) {
            return false;
        }

        // For audio models like Whisper, typically no streaming
        if (str_contains($modelId, 'whisper')) {
            return false;
        }

        // For image models like DALL-E, typically no streaming
        if (str_contains($modelId, 'dall-e')) {
            return false;
        }

        // For Google models, check the supported generation methods
        if (str_starts_with($modelId, 'models/')) {
            // Google embedding models don't support streaming
            if (str_contains($modelId, 'embedding')) {
                return false;
            }
            // Most Google text generation models support streaming
            return true;
        }

        // Default: assume streamable for chat models
        return true;
    }

    /**
     * Process single provider models with policy
     * Used for individual provider refresh (fetchProviderModels)
     */
    public function processSingleProviderModels(
        int $providerId,
        array $apiResponse,
        bool $activateNewModels = false
    ): array {
        return $this->importModelsFromApiResponse(
            $providerId,
            $apiResponse,
            $activateNewModels,
            false // Never auto-show new models
        );
    }

    /**
     * Process bulk refresh models with policy
     * Used for bulk refresh across all providers (refreshModels)
     */
    public function processBulkRefreshModels(
        int $providerId,
        array $apiResponse
    ): array {
        return $this->importModelsFromApiResponse(
            $providerId,
            $apiResponse,
            false, // Never auto-activate in bulk refresh
            false  // Never auto-show in bulk refresh
        );
    }
}
