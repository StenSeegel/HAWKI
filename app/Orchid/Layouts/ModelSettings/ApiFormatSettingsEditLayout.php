<?php

namespace App\Orchid\Layouts\ModelSettings;

use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

class ApiFormatSettingsEditLayout extends Rows
{
    /**
     * Get the fields elements to be displayed.
     *
     * @return Field[]
     */
    protected function fields(): iterable
    {
        return [
            Input::make('apiFormat.unique_name')
                ->title('Unique Name')
                ->placeholder('e.g. openai-api')
                ->help('Internal identifier for the API format (no spaces)')
                ->required(),

            Input::make('apiFormat.display_name')
                ->title('Display Name')
                ->placeholder('e.g. OpenAI API')
                ->help('User-friendly name for display')
                ->required(),

            Input::make('apiFormat.base_url')
                ->type('url')
                ->title('Base URL')
                ->placeholder('https://api.example.com/v1')
                ->help('Base URL for the API (can contain placeholders like {region})')
                ->required(),

            Select::make('apiFormat.provider_class')
                ->title('Provider Class')
                ->help('PHP class that handles this API format')
                ->options($this->getAvailableProviderClasses())
                ->empty('Select Provider Class', '')
                ->required(),

            TextArea::make('apiFormat.metadata')
                ->title('Metadata (JSON)')
                ->rows(8)
                ->help('Additional configuration and metadata in JSON format')
                ->value(function ($repository) {
                    // In Orchid, data comes from Repository, we need to extract the actual model
                    if (is_object($repository) && method_exists($repository, 'get')) {
                        $apiFormat = $repository->get('apiFormat');
                    } else {
                        $apiFormat = $repository;
                    }
                    
                    // Handle array or object
                    if (is_array($apiFormat)) {
                        $apiFormat = (object) $apiFormat;
                    }
                    
                    if ($apiFormat && isset($apiFormat->metadata)) {
                        $metadata = is_string($apiFormat->metadata) ? json_decode($apiFormat->metadata, true) : $apiFormat->metadata;
                        if ($metadata) {
                            return json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        }
                    }
                    
                    return json_encode([
                        'auth_type' => 'bearer',
                        'content_type' => 'application/json',
                        'supports_streaming' => true,
                        'supports_function_calling' => false,
                        'compatible_providers' => []
                    ], JSON_PRETTY_PRINT);
                }),
        ];
    }

    /**
     * Get available AI provider classes by scanning the filesystem
     *
     * @return array
     */
    private function getAvailableProviderClasses(): array
    {
        $providers = [];
        $providerPath = app_path('Services/AI/Providers');
        
        if (!is_dir($providerPath)) {
            return $providers;
        }

        $files = glob($providerPath . '/*Provider.php');
        
        foreach ($files as $file) {
            $className = basename($file, '.php');
            
            // Skip base classes and traits
            if (in_array($className, ['BaseAIModelProvider', 'WebSearchTrait'])) {
                continue;
            }
            
            $fullClassName = 'App\\Services\\AI\\Providers\\' . $className;
            
            // Check if class exists and implements the correct interface
            if (class_exists($fullClassName)) {
                try {
                    $reflection = new \ReflectionClass($fullClassName);
                    
                    // Skip abstract classes
                    if ($reflection->isAbstract()) {
                        continue;
                    }
                    
                    // Check if it implements AIModelProviderInterface
                    if ($reflection->implementsInterface('App\\Services\\AI\\Interfaces\\AIModelProviderInterface')) {
                        // Convert class name to user-friendly display name
                        $displayName = preg_replace('/Provider$/', '', $className);
                        
                        // Handle special cases for better readability
                        $replacements = [
                            'OpenAIResponses' => 'OpenAI Responses',
                            'OpenAI' => 'OpenAI',
                            'GWDG' => 'GWDG', 
                            'WebUI' => 'WebUI',
                            'HAWKI' => 'HAWKI',
                        ];
                        
                        // Apply replacements first
                        foreach ($replacements as $search => $replace) {
                            $displayName = str_replace($search, $replace, $displayName);
                        }
                        
                        // Add spaces before capital letters for remaining parts
                        $displayName = preg_replace('/(?<!^)(?<![A-Z])([A-Z])/', ' $1', $displayName);
                        
                        // Clean up multiple spaces and add Provider suffix
                        $displayName = trim(preg_replace('/\s+/', ' ', $displayName)) . ' Provider';
                        
                        $providers[$fullClassName] = $displayName;
                    }
                } catch (\ReflectionException $e) {
                    // Skip classes that can't be reflected
                    continue;
                }
            }
        }
        
        // Sort by display name for better UX
        asort($providers);
        
        return $providers;
    }
}
