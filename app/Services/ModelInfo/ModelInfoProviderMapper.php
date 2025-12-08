<?php

namespace App\Services\ModelInfo;

class ModelInfoProviderMapper
{
    /**
     * Provider ID mapping from config.
     */
    protected array $providerMapping;

    /**
     * Provider aliases from config.
     */
    protected array $providerAliases;

    /**
     * University/manual providers.
     */
    protected array $manualProviders;

    /**
     * Reverse mapping cache (JSON provider ID → HAWKI provider ID).
     */
    protected ?array $reverseMapping = null;

    public function __construct()
    {
        $this->providerMapping = config('model_info_providers.provider_mapping', []);
        $this->providerAliases = config('model_info_providers.provider_aliases', []);
        $this->manualProviders = config('model_info_providers.requires_manual_setup', []);
    }

    /**
     * Map HAWKI provider ID to JSON provider ID.
     *
     * @param  int  $hawkiProviderId  HAWKI's api_providers.id
     * @return string|null JSON provider ID or null if not mapped
     */
    public function toJsonProviderId(int $hawkiProviderId): ?string
    {
        return $this->providerMapping[$hawkiProviderId] ?? null;
    }

    /**
     * Get all possible JSON provider IDs for a HAWKI provider (including aliases).
     *
     * @param  int  $hawkiProviderId  HAWKI's api_providers.id
     * @return array Array of possible JSON provider IDs
     */
    public function getJsonProviderIds(int $hawkiProviderId): array
    {
        $primaryId = $this->toJsonProviderId($hawkiProviderId);

        if ($primaryId === null) {
            return [];
        }

        // Get aliases if defined
        $aliases = $this->providerAliases[$hawkiProviderId] ?? [$primaryId];

        // Ensure primary ID is included
        if (! in_array($primaryId, $aliases)) {
            $aliases[] = $primaryId;
        }

        return $aliases;
    }

    /**
     * Map JSON provider ID to HAWKI provider ID (reverse lookup).
     *
     * @param  string  $jsonProviderId  Provider ID from JSON
     * @return int|null HAWKI provider ID or null if not found
     */
    public function toHawkiProviderId(string $jsonProviderId): ?int
    {
        if ($this->reverseMapping === null) {
            $this->buildReverseMapping();
        }

        return $this->reverseMapping[$jsonProviderId] ?? null;
    }

    /**
     * Check if a HAWKI provider requires manual setup.
     *
     * @param  int  $hawkiProviderId  HAWKI's api_providers.id
     * @return bool True if provider needs manual configuration
     */
    public function requiresManualSetup(int $hawkiProviderId): bool
    {
        return in_array($hawkiProviderId, $this->manualProviders);
    }

    /**
     * Check if a HAWKI provider is likely to have exact matches in JSON.
     *
     * @param  int  $hawkiProviderId  HAWKI's api_providers.id
     * @return bool True if provider should have JSON data
     */
    public function hasJsonMatch(int $hawkiProviderId): bool
    {
        $jsonId = $this->toJsonProviderId($hawkiProviderId);

        return $jsonId !== null && ! $this->requiresManualSetup($hawkiProviderId);
    }

    /**
     * Get all HAWKI provider IDs that require manual setup.
     *
     * @return array Array of provider IDs
     */
    public function getManualProviders(): array
    {
        return $this->manualProviders;
    }

    /**
     * Get all mapped provider IDs (HAWKI → JSON).
     *
     * @return array Associative array of mappings
     */
    public function getAllMappings(): array
    {
        return $this->providerMapping;
    }

    /**
     * Build reverse mapping cache (JSON → HAWKI).
     */
    protected function buildReverseMapping(): void
    {
        $this->reverseMapping = [];

        foreach ($this->providerMapping as $hawkiId => $jsonId) {
            if ($jsonId !== null) {
                $this->reverseMapping[$jsonId] = $hawkiId;

                // Add aliases to reverse mapping
                if (isset($this->providerAliases[$hawkiId])) {
                    foreach ($this->providerAliases[$hawkiId] as $alias) {
                        $this->reverseMapping[$alias] = $hawkiId;
                    }
                }
            }
        }
    }

    /**
     * Get popular fallback providers from config.
     *
     * @return array Array of popular JSON provider IDs
     */
    public function getPopularProviders(): array
    {
        return config('model_info_providers.popular_providers', []);
    }

    /**
     * Get fallback strategy from config.
     *
     * @return string Strategy: 'first', 'popular', or 'none'
     */
    public function getFallbackStrategy(): string
    {
        return config('model_info_providers.fallback_strategy', 'popular');
    }

    /**
     * Check if a JSON provider ID is in the popular list.
     *
     * @param  string  $jsonProviderId  Provider ID from JSON
     * @return bool True if provider is popular
     */
    public function isPopularProvider(string $jsonProviderId): bool
    {
        return in_array($jsonProviderId, $this->getPopularProviders());
    }

    /**
     * Get a human-readable description of the mapping.
     *
     * @param  int  $hawkiProviderId  HAWKI's api_providers.id
     * @return string Description
     */
    public function getMappingDescription(int $hawkiProviderId): string
    {
        $jsonId = $this->toJsonProviderId($hawkiProviderId);

        if ($jsonId === null) {
            if ($this->requiresManualSetup($hawkiProviderId)) {
                return 'University/Local provider - requires manual setup';
            }

            return 'Not mapped';
        }

        $aliases = $this->providerAliases[$hawkiProviderId] ?? [];
        if (! empty($aliases) && count($aliases) > 1) {
            return "Maps to '{$jsonId}' (aliases: ".implode(', ', $aliases).')';
        }

        return "Maps to '{$jsonId}'";
    }
}
