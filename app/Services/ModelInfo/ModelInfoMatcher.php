<?php

namespace App\Services\ModelInfo;

use App\Models\AiModel;
use Illuminate\Support\Facades\Log;

class ModelInfoMatcher
{
    protected ModelInfoParser $parser;

    protected ModelInfoProviderMapper $providerMapper;

    public function __construct(
        ModelInfoParser $parser,
        ModelInfoProviderMapper $providerMapper
    ) {
        $this->parser = $parser;
        $this->providerMapper = $providerMapper;
    }

    /**
     * Match a HAWKI AiModel with JSON model info and extract data.
     *
     * @param  AiModel  $aiModel  HAWKI model to match
     * @return array Match result with type and extracted data
     */
    public function matchAndExtract(AiModel $aiModel): array
    {
        // Step 1: Try to find model in JSON by model_id or aliases
        $modelInfo = $this->findModelInJson($aiModel->model_id);

        if ($modelInfo === null) {
            return $this->noMatchResult($aiModel);
        }

        // Step 2: Try to find provider-specific data
        $jsonProviderId = $this->providerMapper->toJsonProviderId($aiModel->provider_id);

        if ($jsonProviderId === null) {
            // University provider - use base model data only
            return $this->baseModelMatchResult($aiModel, $modelInfo);
        }

        // Step 3: Look for exact provider match in model's providers array
        $providerData = $this->findProviderData($modelInfo, $jsonProviderId, $aiModel->provider_id);

        if ($providerData !== null) {
            // Exact match found
            return $this->exactMatchResult($aiModel, $modelInfo, $providerData, $jsonProviderId);
        }

        // Step 4: Fallback - use base model data without provider specifics
        return $this->baseModelMatchResult($aiModel, $modelInfo, $jsonProviderId);
    }

    /**
     * Find model in JSON by ID or alias.
     */
    protected function findModelInJson(string $modelId): ?array
    {
        return $this->parser->findModel($modelId);
    }

    /**
     * Find provider-specific data in model's providers array.
     */
    protected function findProviderData(array $modelInfo, string $jsonProviderId, int $hawkiProviderId): ?array
    {
        if (! isset($modelInfo['providers']) || ! is_array($modelInfo['providers'])) {
            return null;
        }

        // Get all possible provider IDs (including aliases)
        $possibleProviderIds = $this->providerMapper->getJsonProviderIds($hawkiProviderId);

        // Try to find exact match
        foreach ($modelInfo['providers'] as $provider) {
            if (in_array($provider['providerId'], $possibleProviderIds)) {
                return $provider;
            }
        }

        // Try fallback strategy if configured
        return $this->tryFallbackProvider($modelInfo);
    }

    /**
     * Try fallback provider selection strategy.
     */
    protected function tryFallbackProvider(array $modelInfo): ?array
    {
        $strategy = $this->providerMapper->getFallbackStrategy();

        if ($strategy === 'none') {
            return null;
        }

        if ($strategy === 'first' && ! empty($modelInfo['providers'])) {
            return $modelInfo['providers'][0];
        }

        if ($strategy === 'popular') {
            $popularProviders = $this->providerMapper->getPopularProviders();

            foreach ($modelInfo['providers'] as $provider) {
                if (in_array($provider['providerId'], $popularProviders)) {
                    return $provider;
                }
            }

            // If no popular provider found, return first
            return $modelInfo['providers'][0] ?? null;
        }

        return null;
    }

    /**
     * Build result for exact match (provider found in JSON).
     */
    protected function exactMatchResult(
        AiModel $aiModel,
        array $modelInfo,
        array $providerData,
        string $jsonProviderId
    ): array {
        return [
            'match_type' => 'exact',
            'model_info_id' => $modelInfo['id'],
            'matched_provider_id' => $jsonProviderId,
            'data' => $this->extractModelData($modelInfo, $providerData),
        ];
    }

    /**
     * Build result for base model match (provider not found, but model exists).
     */
    protected function baseModelMatchResult(
        AiModel $aiModel,
        array $modelInfo,
        ?string $attemptedProviderId = null
    ): array {
        return [
            'match_type' => 'base_model',
            'model_info_id' => $modelInfo['id'],
            'matched_provider_id' => null,
            'attempted_provider_id' => $attemptedProviderId,
            'data' => $this->extractModelData($modelInfo, null),
        ];
    }

    /**
     * Build result for no match (model not found in JSON).
     */
    protected function noMatchResult(AiModel $aiModel): array
    {
        return [
            'match_type' => 'none',
            'model_info_id' => null,
            'matched_provider_id' => null,
            'data' => [],
        ];
    }

    /**
     * Extract model data from JSON.
     */
    protected function extractModelData(array $modelInfo, ?array $providerData): array
    {
        $data = [
            // Base model metadata (always included)
            'aliases' => $modelInfo['aliases'] ?? null,
            'description_en' => $modelInfo['description']['en'] ?? null,
            'description_de' => $modelInfo['description']['de'] ?? null,
            'knowledge_cutoff' => $this->parseKnowledgeCutoff($modelInfo['knowledge'] ?? null),
            'reasoning' => $modelInfo['reasoning'] ?? false,
            'tool_calling' => $modelInfo['toolCalling'] ?? false,
            'open_weights' => $modelInfo['openWeights'] ?? false,
            'input_types' => $modelInfo['input'] ?? null,
            'output_types' => $modelInfo['output'] ?? null,
            'parameters' => $modelInfo['parameters'] ?? null,
            'default_parameters' => $modelInfo['defaultParameters'] ?? null,
            'deprecated' => $modelInfo['deprecated'] ?? false,
            'last_imported_at' => $modelInfo['lastImportedAt'] ?? null,
        ];

        // Add provider-specific data if available
        if ($providerData !== null) {
            $data['context_length'] = $providerData['contextLength'] ?? null;
            $data['output_limit'] = $providerData['outputLimit'] ?? null;

            // Extract pricing
            if (isset($providerData['price'])) {
                $data['price_input_usd'] = $providerData['price']['usd']['input'] ?? null;
                $data['price_output_usd'] = $providerData['price']['usd']['output'] ?? null;
                $data['price_input_eur'] = $providerData['price']['eur']['input'] ?? null;
                $data['price_output_eur'] = $providerData['price']['eur']['output'] ?? null;
            }
        } else {
            // No provider data - set to null
            $data['context_length'] = null;
            $data['output_limit'] = null;
            $data['price_input_usd'] = null;
            $data['price_output_usd'] = null;
            $data['price_input_eur'] = null;
            $data['price_output_eur'] = null;
        }

        return $data;
    }

    /**
     * Parse knowledge cutoff date from various formats.
     */
    protected function parseKnowledgeCutoff(?string $knowledge): ?string
    {
        if ($knowledge === null) {
            return null;
        }

        try {
            // Try to parse as date (YYYY-MM-DD format expected)
            $date = \Carbon\Carbon::parse($knowledge);

            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            Log::warning('Failed to parse knowledge cutoff date', [
                'value' => $knowledge,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get match summary for logging/display.
     */
    public function getMatchSummary(array $matchResult): string
    {
        $type = $matchResult['match_type'];
        $modelId = $matchResult['model_info_id'];
        $providerId = $matchResult['matched_provider_id'];

        return match ($type) {
            'exact' => "Exact match: {$modelId} @ {$providerId}",
            'base_model' => "Base model match: {$modelId} (no provider data)",
            'none' => 'No match found',
            default => 'Unknown match type',
        };
    }

    /**
     * Batch match multiple models.
     */
    public function batchMatch(iterable $aiModels): array
    {
        $results = [];

        foreach ($aiModels as $aiModel) {
            $results[$aiModel->id] = $this->matchAndExtract($aiModel);
        }

        return $results;
    }

    /**
     * Get matching statistics for a batch.
     */
    public function getBatchStatistics(array $batchResults): array
    {
        $stats = [
            'total' => count($batchResults),
            'exact_matches' => 0,
            'base_model_matches' => 0,
            'no_matches' => 0,
        ];

        foreach ($batchResults as $result) {
            match ($result['match_type']) {
                'exact' => $stats['exact_matches']++,
                'base_model' => $stats['base_model_matches']++,
                'none' => $stats['no_matches']++,
                default => null,
            };
        }

        return $stats;
    }
}
