<?php

namespace App\Console\Commands;

use App\Services\AI\Mcp\McpClientService;
use Illuminate\Console\Command;

class TestMcpClient extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hawki:test-mcp {prompt : The prompt to send to the MCP agent}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the MCP client integration by sending a prompt to the HawkiMcpAgent';

    /**
     * Execute the console command.
     */
    public function handle(McpClientService $mcpService): int
    {
        $prompt = $this->argument('prompt');

        $this->info("Sende Prompt an MCP Agent: \"{$prompt}\"");
        $this->info("Bitte warten (dies kann einen Moment dauern, da MCP-Server gestartet werden)...");

        $response = $mcpService->ask($prompt);

        $this->newLine();
        $this->info("Antwort vom MCP Agent:");
        $this->line($response);

        return self::SUCCESS;
    }
}
