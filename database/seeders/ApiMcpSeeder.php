<?php

namespace Database\Seeders;

use App\Models\ApiMcp;
use Illuminate\Database\Seeder;

class ApiMcpSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $servers = [
            [
                'name' => 'jlu-mcp',
                'label' => 'JLU Google Search',
                'type' => 'http',
                'url' => 'https://api.hrz.uni-giessen.de/mcp',
                'headers' => [
                    'x-litellm-api-key' => env('JLU_MCP_API_KEY', 'Bearer sk-placeholder'),
                ],
                'is_active' => true,
                'display_order' => 1,
                'description' => 'JLU MCP server providing Google Search tools',
            ],
            [
                'name' => 'tavily',
                'label' => 'Tavily Web Research',
                'type' => 'http',
                'url' => 'https://mcp.tavily.com/mcp/?tavilyApiKey='.env('TAVILY_API_KEY', 'placeholder'),
                'headers' => null,
                'is_active' => false,
                'display_order' => 2,
                'description' => 'Tavily MCP server for web research and content extraction',
            ],
        ];

        foreach ($servers as $server) {
            ApiMcp::updateOrCreate(
                ['name' => $server['name']],
                $server
            );
        }
    }
}
