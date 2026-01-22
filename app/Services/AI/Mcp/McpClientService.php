<?php

namespace App\Services\AI\Mcp;

use NeuronAI\Chat\Messages\UserMessage;
use Illuminate\Support\Facades\Log;

class McpClientService
{
    /**
     * Send a message to the MCP-enabled agent and get a response.
     *
     * @param string $prompt
     * @return string
     */
    public function ask(string $prompt): string
    {
        try {
            $agent = HawkiMcpAgent::make();
            $response = $agent->chat(new UserMessage($prompt));
            
            return $response->getContent();
        } catch (\Exception $e) {
            Log::error("MCP Client Error: " . $e->getMessage());
            return "Entschuldigung, es gab einen Fehler bei der Verarbeitung der MCP-Anfrage: " . $e->getMessage();
        }
    }
}
