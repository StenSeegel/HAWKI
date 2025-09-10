<?php

namespace App\Orchid\Traits;

trait ApiFormatColorTrait
{
    /**
     * Get consistent badge color for API format based on ID
     *
     * @param int $apiFormatId
     * @return string
     */
    protected function getApiFormatBadgeColor(int $apiFormatId): string
    {
        // Define consistent color palette across the system
        $colors = [
            'success',    // Green
            'info',       // Light blue
            'warning',    // Yellow
            'danger',     // Red
            'primary',    // Blue
            'dark',       // Dark
            'secondary',  // Gray
        ];
        
        // Generate consistent color based on API format ID
        $colorIndex = $apiFormatId % count($colors);
        return $colors[$colorIndex];
    }

    /**
     * Generate badge HTML for API format
     *
     * @param \App\Models\ApiFormat|null $apiFormat
     * @param string $additionalClasses
     * @param bool $largeText
     * @return string
     */
    protected function getApiFormatBadge($apiFormat, string $additionalClasses = '', bool $largeText = false): string
    {
        $fontSize = $largeText ? 'fs-6' : '';
        
        if (!$apiFormat) {
            return '<span class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill ' . $fontSize . ' ' . $additionalClasses . '">Not Set</span>';
        }

        $badgeColor = $this->getApiFormatBadgeColor($apiFormat->id);
        return '<span class="badge bg-' . $badgeColor . '-subtle text-' . $badgeColor . '-emphasis rounded-pill ' . $fontSize . ' ' . $additionalClasses . '">' . e($apiFormat->display_name) . '</span>';
    }

    /**
     * Generate simple badge HTML with consistent styling
     *
     * @param string $text
     * @param string $colorClass
     * @param string $additionalClasses
     * @param bool $largeText
     * @return string
     */
    protected function getSimpleBadge(string $text, string $colorClass = 'secondary', string $additionalClasses = '', bool $largeText = false): string
    {
        $fontSize = $largeText ? 'fs-6' : '';
        return '<span class="badge bg-' . $colorClass . '-subtle text-' . $colorClass . '-emphasis rounded-pill ' . $fontSize . ' ' . $additionalClasses . '">' . e($text) . '</span>';
    }

    /**
     * Generate provider badge HTML with consistent styling
     *
     * @param string $providerName
     * @param string $colorClass
     * @param string $additionalClasses
     * @param bool $largeText
     * @return string
     */
    protected function getProviderBadge(string $providerName, string $colorClass = 'primary', string $additionalClasses = '', bool $largeText = false): string
    {
        return $this->getSimpleBadge($providerName, $colorClass, $additionalClasses, $largeText);
    }

    /**
     * Generate dynamic badge class for provider based on API format
     *
     * @param int|null $apiFormatId
     * @param bool $includeRoundedPill
     * @param bool $largeText
     * @return string
     */
    protected function getProviderBadgeClass(?int $apiFormatId, bool $includeRoundedPill = true, bool $largeText = true): string
    {
        $baseClass = 'bg-primary-subtle text-primary-emphasis';
        
        if ($apiFormatId) {
            $colorClass = $this->getApiFormatBadgeColor($apiFormatId);
            $baseClass = "bg-{$colorClass}-subtle text-{$colorClass}-emphasis";
        }
        
        if ($includeRoundedPill) {
            $baseClass .= ' rounded-pill';
        }
        
        if ($largeText) {
            $baseClass .= ' fs-6';
        }
        
        return $baseClass;
    }

    /**
     * Generate dynamic badge class for model based on provider's API format
     *
     * @param \App\Models\LanguageModel|array|null $model
     * @param string $defaultColor
     * @param bool $includeRoundedPill
     * @param bool $largeText
     * @return string
     */
    protected function getModelProviderBadgeClass($model, string $defaultColor = 'primary', bool $includeRoundedPill = true, bool $largeText = true): string
    {
        $apiFormatId = null;
        
        // Handle different input types
        if (is_array($model) && isset($model['provider']['api_format_id'])) {
            $apiFormatId = $model['provider']['api_format_id'];
        } elseif (is_object($model) && $model->provider && isset($model->provider->api_format_id)) {
            $apiFormatId = $model->provider->api_format_id;
        }
        
        $baseClass = "bg-{$defaultColor}-subtle text-{$defaultColor}-emphasis";
        
        if ($apiFormatId) {
            $colorClass = $this->getApiFormatBadgeColor($apiFormatId);
            $baseClass = "bg-{$colorClass}-subtle text-{$colorClass}-emphasis";
        }
        
        if ($includeRoundedPill) {
            $baseClass .= ' rounded-pill';
        }
        
        if ($largeText) {
            $baseClass .= ' fs-6';
        }
        
        return $baseClass;
    }
}
