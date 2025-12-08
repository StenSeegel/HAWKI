<?php

namespace App\Services\ModelInfo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ModelInfoParser
{
    /**
     * Path to the model_info.json file in storage.
     */
    protected string $filePath = 'model_lists/model_info.json';

    /**
     * Cache duration in seconds (1 hour).
     */
    protected int $cacheDuration = 3600;

    /**
     * Cached parsed data.
     */
    protected ?array $data = null;

    /**
     * Load and parse the model_info.json file.
     *
     * @return array Parsed JSON data
     *
     * @throws \Exception If file cannot be loaded or parsed
     */
    public function load(bool $fresh = false): array
    {
        if ($this->data !== null && ! $fresh) {
            return $this->data;
        }

        $cacheKey = 'model_info_json_data';

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        $this->data = Cache::remember($cacheKey, $this->cacheDuration, function () {
            if (! Storage::exists($this->filePath)) {
                throw new \Exception("Model info file not found: {$this->filePath}");
            }

            $jsonContent = Storage::get($this->filePath);

            if ($jsonContent === false) {
                throw new \Exception("Failed to read model info file: {$this->filePath}");
            }

            $data = json_decode($jsonContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Failed to parse model info JSON: '.json_last_error_msg());
            }

            // Validate structure
            if (! isset($data['models']) || ! is_array($data['models'])) {
                throw new \Exception('Invalid model info structure: missing models array');
            }

            if (! isset($data['providers']) || ! is_array($data['providers'])) {
                throw new \Exception('Invalid model info structure: missing providers array');
            }

            Log::info('Model info JSON loaded', [
                'models_count' => count($data['models']),
                'providers_count' => count($data['providers']),
                'file_size' => Storage::size($this->filePath),
            ]);

            return $data;
        });

        return $this->data;
    }

    /**
     * Find a model by ID in the JSON data.
     *
     * @param  string  $modelId  Model ID to search for
     * @return array|null Model data or null if not found
     */
    public function findModelById(string $modelId): ?array
    {
        $data = $this->load();

        foreach ($data['models'] as $model) {
            if ($model['id'] === $modelId) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Find a model by ID or any of its aliases.
     *
     * @param  string  $searchTerm  Model ID or alias to search for
     * @return array|null Model data or null if not found
     */
    public function findModel(string $searchTerm): ?array
    {
        $data = $this->load();

        // Step 1: Try exact match first
        foreach ($data['models'] as $model) {
            // Check exact ID match
            if ($model['id'] === $searchTerm) {
                return $model;
            }

            // Check aliases
            if (isset($model['aliases']) && is_array($model['aliases'])) {
                if (in_array($searchTerm, $model['aliases'])) {
                    return $model;
                }
            }
        }

        // Step 2: Try case-insensitive exact match
        $searchTermLower = strtolower($searchTerm);
        
        foreach ($data['models'] as $model) {
            // Check case-insensitive ID match
            if (strtolower($model['id']) === $searchTermLower) {
                Log::info('Case-insensitive model match found', [
                    'search_term' => $searchTerm,
                    'matched_id' => $model['id'],
                ]);
                return $model;
            }

            // Check case-insensitive aliases
            if (isset($model['aliases']) && is_array($model['aliases'])) {
                foreach ($model['aliases'] as $alias) {
                    if (strtolower($alias) === $searchTermLower) {
                        Log::info('Case-insensitive model match found via alias', [
                            'search_term' => $searchTerm,
                            'matched_alias' => $alias,
                            'matched_id' => $model['id'],
                        ]);
                        return $model;
                    }
                }
            }
        }

        // Step 3: Try iterative fuzzy matching - remove suffixes one by one
        $result = $this->iterativeNormalizedMatch($searchTerm, $data['models']);
        
        if ($result !== null) {
            return $result;
        }

        return null;
    }

    /**
     * Try to match model by iteratively removing suffixes until a match is found.
     *
     * @param  string  $searchTerm  Original search term
     * @param  array  $models  List of models to search in
     * @return array|null Matched model or null
     */
    protected function iterativeNormalizedMatch(string $searchTerm, array $models): ?array
    {
        $currentTerm = strtolower($searchTerm);
        $removedParts = [];
        
        // Step 0: Remove provider prefix if present (e.g., "ollama/", "openai/")
        if (preg_match('/^([a-z0-9_-]+)\/(.+)$/', $currentTerm, $matches)) {
            $providerPrefix = $matches[1];
            $currentTerm = $matches[2];
            $removedParts[] = $providerPrefix . '/';
            
            Log::debug('Removed provider prefix', [
                'original' => $searchTerm,
                'prefix' => $providerPrefix,
                'remaining' => $currentTerm,
            ]);
        }
        
        // Keep trying to remove suffixes until we find a match or can't remove anymore
        $maxIterations = 10; // Safety limit
        $iteration = 0;
        
        while ($iteration < $maxIterations) {
            $iteration++;
            
            // Try to match current normalized term (exact match)
            foreach ($models as $model) {
                // Check against model ID
                if (strtolower($model['id']) === $currentTerm) {
                    Log::info('Iterative fuzzy model match found', [
                        'search_term' => $searchTerm,
                        'normalized' => $currentTerm,
                        'removed_parts' => $removedParts,
                        'iterations' => $iteration,
                        'matched_id' => $model['id'],
                    ]);
                    return $model;
                }

                // Check against aliases
                if (isset($model['aliases']) && is_array($model['aliases'])) {
                    foreach ($model['aliases'] as $alias) {
                        if (strtolower($alias) === $currentTerm) {
                            Log::info('Iterative fuzzy model match found via alias', [
                                'search_term' => $searchTerm,
                                'normalized' => $currentTerm,
                                'removed_parts' => $removedParts,
                                'iterations' => $iteration,
                                'matched_alias' => $alias,
                                'matched_id' => $model['id'],
                            ]);
                            return $model;
                        }
                    }
                }
            }
            
            // Try fuzzy match with smart separator normalization
            // Strategy: Replace separators but keep dots in version numbers (e.g., "3.2", "2.0")
            // This allows "llama3.2" to match "llama-3.2" and "gpt-oss:20b" to match "gpt-oss-20b"
            
            // First, normalize by replacing common separators with a unified separator
            // Keep dots that are between digits (version numbers like 3.2, 2.0, etc.)
            $normalizedTerm = preg_replace('/[:\-_\s]+/', '', $currentTerm);  // Remove -, :, _, spaces
            $normalizedTerm = preg_replace('/\.(?!\d)/', '', $normalizedTerm); // Remove dots NOT followed by digits
            $normalizedTerm = preg_replace('/(?<!\d)\./', '', $normalizedTerm); // Remove dots NOT preceded by digits
            
            foreach ($models as $model) {
                $normalizedModelId = preg_replace('/[:\-_\s]+/', '', strtolower($model['id']));
                $normalizedModelId = preg_replace('/\.(?!\d)/', '', $normalizedModelId);
                $normalizedModelId = preg_replace('/(?<!\d)\./', '', $normalizedModelId);
                
                // Check if normalized IDs match
                if ($normalizedModelId === $normalizedTerm) {
                    Log::info('Iterative fuzzy model match found (separator-insensitive)', [
                        'search_term' => $searchTerm,
                        'normalized' => $currentTerm,
                        'normalized_term' => $normalizedTerm,
                        'normalized_model_id' => $normalizedModelId,
                        'removed_parts' => $removedParts,
                        'iterations' => $iteration,
                        'matched_id' => $model['id'],
                        'match_type' => 'separator_insensitive',
                    ]);
                    return $model;
                }
                
                // Also check if the search term is a prefix of the model ID (for base model matching)
                // e.g., "llama3.2" should match "llama-3.2-3b-instruct"
                if (str_starts_with($normalizedModelId, $normalizedTerm)) {
                    Log::info('Iterative fuzzy model match found (prefix match)', [
                        'search_term' => $searchTerm,
                        'normalized' => $currentTerm,
                        'normalized_term' => $normalizedTerm,
                        'normalized_model_id' => $normalizedModelId,
                        'removed_parts' => $removedParts,
                        'iterations' => $iteration,
                        'matched_id' => $model['id'],
                        'match_type' => 'prefix_match',
                    ]);
                    return $model;
                }

                // Check against aliases (separator-insensitive)
                if (isset($model['aliases']) && is_array($model['aliases'])) {
                    foreach ($model['aliases'] as $alias) {
                        $normalizedAlias = preg_replace('/[:\-_\s]+/', '', strtolower($alias));
                        $normalizedAlias = preg_replace('/\.(?!\d)/', '', $normalizedAlias);
                        $normalizedAlias = preg_replace('/(?<!\d)\./', '', $normalizedAlias);
                        
                        if ($normalizedAlias === $normalizedTerm || str_starts_with($normalizedAlias, $normalizedTerm)) {
                            Log::info('Iterative fuzzy model match found via alias (separator-insensitive)', [
                                'search_term' => $searchTerm,
                                'normalized' => $currentTerm,
                                'normalized_term' => $normalizedTerm,
                                'normalized_alias' => $normalizedAlias,
                                'removed_parts' => $removedParts,
                                'iterations' => $iteration,
                                'matched_alias' => $alias,
                                'matched_id' => $model['id'],
                                'match_type' => str_starts_with($normalizedAlias, $normalizedTerm) ? 'prefix_match' : 'separator_insensitive',
                            ]);
                            return $model;
                        }
                    }
                }
            }
            
            // No match found - try removing another suffix
            $newTerm = $this->removeSingleSuffix($currentTerm);
            
            if ($newTerm === $currentTerm) {
                // No more suffixes can be removed
                break;
            }
            
            // Track what was removed
            $removedParts[] = substr($currentTerm, strlen($newTerm));
            $currentTerm = $newTerm;
        }
        
        return null;
    }

    /**
     * Remove a single suffix from the model ID.
     * Returns the modified string, or the original if no suffix was found.
     *
     * @param  string  $modelId  Model ID to process
     * @return string Model ID with one suffix removed
     */
    protected function removeSingleSuffix(string $modelId): string
    {
        // Priority order: remove most specific suffixes first
        
        // 0. Remove vendor/variant infixes (e.g., "meta-", "-sauerkrautlm-", "-rag")
        // These appear in the middle of model names
        $infixPatterns = [
            '/^meta-llama-(.+)$/' => 'llama-$1',           // meta-llama-3.1-8b → llama-3.1-8b
            '/^meta-(.+)$/' => '$1',                        // meta-llama → llama
            '/^text-(.+)$/' => '$1',                        // text-embedding-004 → embedding-004
            '/^embedding-gecko-(.+)$/' => 'gemini-embedding-$1',  // embedding-gecko-001 → gemini-embedding-001
            '/^nano-banana-(.+)$/' => 'gemini-2.5-flash-image-$1', // nano-banana-pro-preview → gemini-2.5-flash-image-pro-preview
            '/^learnlm-(.+)$/' => 'gemini-$1',              // learnlm-2.0-flash-experimental → gemini-2.0-flash-experimental
            '/^gemini-exp-(\d+)$/' => 'gemini-2.0-flash-exp', // gemini-exp-1206 → gemini-2.0-flash-exp
            '/^gemini-flash-latest$/' => 'gemini-2.5-flash', // gemini-flash-latest → gemini-2.5-flash
            '/^gemini-flash-lite-latest$/' => 'gemini-2.5-flash-lite', // gemini-flash-lite-latest → gemini-2.5-flash-lite
            '/^gemini-pro-latest$/' => 'gemini-2.5-pro',   // gemini-pro-latest → gemini-2.5-pro
            '/^(gemini|imagen|veo)-(\d+)\.0-(.+)$/' => '$1-$2-$3',  // gemini-2.0-pro → gemini-2-pro, imagen-4.0-fast → imagen-4-fast
            '/-sauerkrautlm-/' => '-',                      // llama-3.1-sauerkrautlm-70b → llama-3.1-70b
            '/-nemotron-/' => '-',                          // llama-3.1-nemotron-70b → llama-3.1-70b
            '/-lumimaid-/' => '-',                          // llama-3.1-lumimaid-8b → llama-3.1-8b
            '/-computer-use-/' => '-',                      // gemini-2.5-computer-use-preview → gemini-2.5-preview
            '/-robotics-er-/' => '-',                       // gemini-robotics-er-1.5 → gemini-1.5
            '/-image-/' => '-',                             // gemini-3-pro-image-preview → gemini-3-pro-preview
            '/-rag$/' => '',                                // model-8b-rag → model-8b
            '/-rag-/' => '-',                               // model-rag-8b → model-8b
        ];
        
        foreach ($infixPatterns as $pattern => $replacement) {
            if (preg_match($pattern, $modelId)) {
                return preg_replace($pattern, $replacement, $modelId);
            }
        }
        
        // 1. Remove Docker-style tags with colon separator (e.g., :12b, :latest, :8b)
        // Match patterns like :12b, :8b, :70b, :latest, :v1.5
        if (preg_match('/:[a-z0-9._-]+$/', $modelId)) {
            return preg_replace('/:[a-z0-9._-]+$/', '', $modelId);
        }
        
        // 2. Remove quantization format suffixes
        $quantFormats = [
            '-fp8',
            '-fp16',
            '-fp32',
            '-int4',
            '-int8',
            '-gptq',
            '-awq',
            '-gguf',
            '-ggml',
            '-q4_0',
            '-q4_1',
            '-q5_0',
            '-q5_1',
            '-q8_0',
        ];
        
        foreach ($quantFormats as $format) {
            if (str_ends_with($modelId, $format)) {
                return substr($modelId, 0, -strlen($format));
            }
        }
        
        // 3. Remove date-based suffixes (YYYY-MM-DD format)
        if (preg_match('/-\d{4}-\d{2}-\d{2}$/', $modelId)) {
            return preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $modelId);
        }
        
        // 4. Remove short date suffixes (4 digits like -2506)
        if (preg_match('/-\d{4}$/', $modelId)) {
            return preg_replace('/-\d{4}$/', '', $modelId);
        }
        
        // 4b. Remove generation/preview suffixes with dates (e.g., -generate-preview-06-06, -generate-001)
        if (preg_match('/-generate-[a-z]+-\d{2}-\d{2}$/', $modelId)) {
            return preg_replace('/-generate-[a-z]+-\d{2}-\d{2}$/', '', $modelId);
        }
        
        if (preg_match('/-generate-\d{3}$/', $modelId)) {
            return preg_replace('/-generate-\d{3}$/', '', $modelId);
        }
        
        // 4c. Remove version suffixes with dates (e.g., -preview-10-2025, -exp-02-05, -exp-03-07)
        if (preg_match('/-(preview|exp)-\d{2}-\d{2,4}$/', $modelId)) {
            return preg_replace('/-(preview|exp)-\d{2}-\d{2,4}$/', '', $modelId);
        }
        
        // 5. Remove version/variant suffixes
        $versionSuffixes = [
            '-1b-it',         // gemma-3-1b-it → gemma-3 (combined suffix)
            '-2b-it',         // gemma-3-2b-it → gemma-3
            '-4b-it',         // gemma-3-4b-it → gemma-3
            '-instruct',
            '-thinking',
            '-tts',           // text-to-speech variant
            '-exp',           // experimental variant
            '-generate',      // generation variant
            '-preview',
            '-latest',
            '-snapshot',
            '-experimental',
            '-beta',
            '-alpha',
            '-free',
            '-online',
            '-extended',
            '-instant',
            '-fast',
            '-lite',          // lite variant
            '-ultra',         // ultra variant
            '-robotics',      // robotics variant
            ':extended',
            ':latest',
            ':free',
        ];
        
        foreach ($versionSuffixes as $suffix) {
            if (str_ends_with($modelId, $suffix)) {
                return substr($modelId, 0, -strlen($suffix));
            }
        }
        
        // 6. Remove trailing model size suffixes
        // Handle single-digit sizes first (1b, 2b, 3b) before multi-digit
        if (preg_match('/-[1-9]b(-|$)/', $modelId)) {
            return preg_replace('/-[1-9]b$/', '', $modelId);
        }
        
        // Then handle multi-digit sizes and versions like -v1, -v2, -12b, -27b, -70b
        if (preg_match('/-\d+[a-z]?$/', $modelId)) {
            return preg_replace('/-\d+[a-z]?$/', '', $modelId);
        }
        
        // 7. Remove model variant suffixes (FALLBACK - lower priority)
        // These define model capabilities and should only be removed as a last resort
        $variantSuffixes = [
            '-vl',        // Vision-Language variant
            '-coder',     // Coding-specialized variant
            '-omni',      // Omni/multimodal variant
            '-captioner', // Captioning variant
        ];
        
        foreach ($variantSuffixes as $suffix) {
            if (str_ends_with($modelId, $suffix)) {
                return substr($modelId, 0, -strlen($suffix));
            }
        }
        
        // 8. Remove single trailing dash (cleanup step)
        // This helps match "gemma3" with "gemma-3" after other suffixes are removed
        if (str_ends_with($modelId, '-')) {
            return substr($modelId, 0, -1);
        }
        
        // No suffix found to remove
        return $modelId;
    }

    /**
     * Search for models by partial name/ID match.
     *
     * @param  string  $query  Search query
     * @param  int  $limit  Maximum results to return
     * @return array Array of matching models
     */
    public function searchModels(string $query, int $limit = 10): array
    {
        $data = $this->load();
        $results = [];
        $query = strtolower($query);

        foreach ($data['models'] as $model) {
            // Search in ID
            if (str_contains(strtolower($model['id']), $query)) {
                $results[] = $model;

                if (count($results) >= $limit) {
                    break;
                }

                continue;
            }

            // Search in name
            if (isset($model['name']) && str_contains(strtolower($model['name']), $query)) {
                $results[] = $model;

                if (count($results) >= $limit) {
                    break;
                }

                continue;
            }

            // Search in aliases
            if (isset($model['aliases']) && is_array($model['aliases'])) {
                foreach ($model['aliases'] as $alias) {
                    if (str_contains(strtolower($alias), $query)) {
                        $results[] = $model;

                        if (count($results) >= $limit) {
                            break 2;
                        }

                        continue 2;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Get all models from JSON.
     *
     * @return array Array of all models
     */
    public function getAllModels(): array
    {
        $data = $this->load();

        return $data['models'] ?? [];
    }

    /**
     * Get all providers from JSON.
     *
     * @return array Array of all providers
     */
    public function getAllProviders(): array
    {
        $data = $this->load();

        return $data['providers'] ?? [];
    }

    /**
     * Get all models that support a specific provider.
     *
     * @param  string  $providerId  Provider ID to filter by (e.g., 'openai', 'anthropic')
     * @return array Array of models that include this provider
     */
    public function getModelsByProvider(string $providerId): array
    {
        $data = $this->load();
        $models = $data['models'] ?? [];
        $matchingModels = [];

        foreach ($models as $model) {
            // Check if the model has providers array
            if (!isset($model['providers']) || !is_array($model['providers'])) {
                continue;
            }

            // Check if this provider is in the model's providers list
            foreach ($model['providers'] as $provider) {
                if (isset($provider['providerId']) && $provider['providerId'] === $providerId) {
                    $matchingModels[] = $model;
                    break; // Found the provider, no need to check other providers for this model
                }
            }
        }

        return $matchingModels;
    }

    /**
     * Get statistics about models per provider.
     *
     * @return array Provider statistics [providerId => count]
     */
    public function getProviderStatistics(): array
    {
        $data = $this->load();
        $models = $data['models'] ?? [];
        $stats = [];

        foreach ($models as $model) {
            if (!isset($model['providers']) || !is_array($model['providers'])) {
                continue;
            }

            foreach ($model['providers'] as $provider) {
                if (isset($provider['providerId'])) {
                    $providerId = $provider['providerId'];
                    
                    if (!isset($stats[$providerId])) {
                        $stats[$providerId] = 0;
                    }
                    
                    $stats[$providerId]++;
                }
            }
        }

        // Sort by count descending
        arsort($stats);

        return $stats;
    }

    /**
     * Find provider by ID in JSON.
     *
     * @param  string  $providerId  Provider ID to search for
     * @return array|null Provider data or null if not found
     */
    public function findProvider(string $providerId): ?array
    {
        $providers = $this->getAllProviders();

        foreach ($providers as $provider) {
            if ($provider['id'] === $providerId) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Get statistics about the loaded data.
     *
     * @return array Statistics array
     */
    public function getStatistics(): array
    {
        $data = $this->load();
        $models = $data['models'] ?? [];
        $providers = $data['providers'] ?? [];

        $stats = [
            'total_models' => count($models),
            'total_providers' => count($providers),
            'deprecated_models' => 0,
            'reasoning_models' => 0,
            'tool_calling_models' => 0,
            'multimodal_models' => 0,
        ];

        foreach ($models as $model) {
            if (isset($model['deprecated']) && $model['deprecated']) {
                $stats['deprecated_models']++;
            }

            if (isset($model['reasoning']) && $model['reasoning']) {
                $stats['reasoning_models']++;
            }

            if (isset($model['toolCalling']) && $model['toolCalling']) {
                $stats['tool_calling_models']++;
            }

            $inputTypes = $model['input'] ?? [];
            if (count($inputTypes) > 1) {
                $stats['multimodal_models']++;
            }
        }

        return $stats;
    }

    /**
     * Clear the cache.
     */
    public function clearCache(): void
    {
        Cache::forget('model_info_json_data');
        $this->data = null;
    }

    /**
     * Get the file path.
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * Check if the file exists.
     */
    public function fileExists(): bool
    {
        return Storage::exists($this->filePath);
    }

    /**
     * Get file information.
     */
    public function getFileInfo(): array
    {
        if (! $this->fileExists()) {
            return [
                'exists' => false,
            ];
        }

        return [
            'exists' => true,
            'path' => $this->filePath,
            'full_path' => Storage::path($this->filePath),
            'size' => Storage::size($this->filePath),
            'size_human' => $this->formatBytes(Storage::size($this->filePath)),
            'last_modified' => Storage::lastModified($this->filePath),
            'last_modified_human' => date('Y-m-d H:i:s', Storage::lastModified($this->filePath)),
        ];
    }

    /**
     * Format bytes to human-readable size.
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision).' '.$units[$i];
    }
}
