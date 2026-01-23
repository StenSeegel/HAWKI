<?php

namespace App\Services\AI\Mcp;

use App\Services\AI\Config\AiConfigService;
use NeuronAI\Agent;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Google\Gemini;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Providers\OpenAILikeResponses;
use Illuminate\Support\Facades\Log;

class HawkiMcpAgent extends Agent
{
    /**
     * The AI provider instance.
     */
    public function provider(): AIProviderInterface
    {
        $aiConfigService = app(AiConfigService::class);
        $providers = $aiConfigService->getProviders();
        
        $providerKey = config('mcp.provider', 'ki-at-jlu');
        $model = config('mcp.model');

        // If no model is specified in mcp config, try to get the default model from AiConfigService
        if (!$model) {
            $defaultModels = $aiConfigService->getDefaultModels();
            $model = $defaultModels['default_model'] ?? 'gpt-4o';
        }
        
        Log::info("MCP Agent using model: " . $model);

        $providerConfig = $providers[$providerKey] ?? null;

        if (!$providerConfig) {
            Log::warning("MCP Provider '{$providerKey}' not found in AiConfigService. Falling back to default OpenAI.");
            return new OpenAI(
                key: config('model_providers.providers.openAi.api_key'),
                model: $model
            );
        }

        $apiKey = $providerConfig['api_key'] ?? '';
        $adapter = strtolower($providerConfig['adapter'] ?? '');
        $apiUrl = $providerConfig['api_url'] ?? '';

        Log::info("MCP Agent using adapter: " . $adapter . " with URL: " . $apiUrl);

        // Helper to remove suffixes from the end of the URL
        $stripSuffix = function($url, $suffixPattern) {
            return preg_replace($suffixPattern, '', rtrim($url, '/'));
        };

        return match ($adapter) {
            'anthropic' => new Anthropic(key: $apiKey, model: $model),
            'google' => new Gemini(key: $apiKey, model: $model),
            'responses' => new OpenAILikeResponses(
                baseUri: $stripSuffix($apiUrl, '/\/responses$/i'),
                key: $apiKey,
                model: $model
            ),
            default => new OpenAILike(
                baseUri: $stripSuffix($apiUrl, '/(\/chat\/completions|\/messages|\/v1\/chat\/completions)$/i'),
                key: $apiKey,
                model: $model
            ),
        };
    }

    /**
     * Base instructions for the agent.
     */
    public function instructions(): string
    {
        return "Du bist ein hilfreicher KI-Assistent in der HAWKI-Plattform. " .
               "Du hast Zugriff auf verschiedene Tools über das Model Context Protocol (MCP). " .
               "Nutze diese Tools, um Benutzeranfragen präzise zu beantworten.";
    }

    /**
     * Register tools from configured MCP servers.
     */
    public function tools(): array
    {
        if (!config('mcp.enabled', false)) {
            return [];
        }

        $allTools = [];
        $servers = config('mcp.servers', []);

        foreach ($servers as $name => $config) {
            try {
                Log::info("Connecting to MCP server: {$name}");
                $connector = McpConnector::make($config);
                $allTools = array_merge($allTools, $connector->tools());
            } catch (\Exception $e) {
                Log::error("Failed to connect to MCP server '{$name}': " . $e->getMessage());
            }
        }

        return $allTools;
    }
}
