<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\Tools\HawkiToolInterface;
use App\Services\AI\Tools\ToolCallRunner;
use App\Services\AI\Tools\WebSearchTool;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebSearchToolTest extends TestCase
{
    private const MCP_URL = 'https://mcp.test/mcp';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'hawki_tools.mcp_servers' => [
                'websearch-mcp' => ['url' => self::MCP_URL, 'requires_session' => false, 'timeout' => 5],
            ],
            'hawki_tools.bindings.web_search' => [
                'server' => 'websearch-mcp',
                'tools' => [
                    'general' => 'google_search',
                    'local' => 'search_uni_giessen',
                    'url' => 'extract_webpage_content',
                ],
            ],
        ]);

        Http::fake([
            self::MCP_URL => Http::response(
                "data: {\"result\":{\"content\":[{\"type\":\"text\",\"text\":\"mocked result\"}]}}\n",
                200
            ),
        ]);
    }

    private function calledTool(): array
    {
        $request = Http::recorded()[0][0];

        return $request->data()['params'];
    }

    public function test_a_plain_query_routes_to_the_general_search(): void
    {
        $result = app(WebSearchTool::class)->execute(['query' => 'php 8.4 release date']);

        $this->assertSame('mocked result', $result);
        $params = $this->calledTool();
        $this->assertSame('google_search', $params['name']);
        $this->assertSame(['query' => 'php 8.4 release date'], $params['arguments']);
    }

    public function test_a_university_query_routes_to_the_local_search(): void
    {
        app(WebSearchTool::class)->execute(['query' => 'Mensa Öffnungszeiten JLU']);

        $this->assertSame('search_uni_giessen', $this->calledTool()['name']);
    }

    public function test_a_university_spelled_out_query_routes_to_the_local_search(): void
    {
        app(WebSearchTool::class)->execute(['query' => 'Semesterticket Universität Gießen']);

        $this->assertSame('search_uni_giessen', $this->calledTool()['name']);
    }

    public function test_a_question_about_the_city_is_a_general_search(): void
    {
        // Regression: the bare city name used to route to the university search,
        // which cannot answer this and sent the model back for another round.
        app(WebSearchTool::class)->execute(['query' => 'Wie ist das Wetter heute in Gießen?']);

        $this->assertSame('google_search', $this->calledTool()['name']);
    }

    public function test_the_german_verb_giessen_is_not_a_university_query(): void
    {
        // "gießen" also means "to water", so this must not hit the local search.
        app(WebSearchTool::class)->execute(['query' => 'Wie oft soll ich Tomaten gießen?']);

        $this->assertSame('google_search', $this->calledTool()['name']);
    }

    public function test_a_bare_url_routes_to_content_extraction(): void
    {
        app(WebSearchTool::class)->execute(['query' => 'https://example.com/page']);

        $params = $this->calledTool();
        $this->assertSame('extract_webpage_content', $params['name']);
        $this->assertSame(['url' => 'https://example.com/page'], $params['arguments']);
    }

    public function test_a_www_url_is_normalised_to_https(): void
    {
        app(WebSearchTool::class)->execute(['query' => 'www.example.com/page']);

        $this->assertSame(
            ['url' => 'https://www.example.com/page'],
            $this->calledTool()['arguments']
        );
    }

    public function test_a_sentence_mentioning_a_url_is_still_a_search(): void
    {
        app(WebSearchTool::class)->execute(['query' => 'what does https://example.com say about php']);

        $this->assertSame('google_search', $this->calledTool()['name']);
    }

    public function test_an_unbound_server_is_reported_to_the_model_not_thrown(): void
    {
        config(['hawki_tools.mcp_servers' => []]);

        $result = app(ToolCallRunner::class)->run(
            ['web_search' => app(WebSearchTool::class)],
            'web_search',
            '{"query":"php"}'
        );

        $this->assertStringStartsWith('Error:', $result);
        $this->assertStringContainsString('reachable MCP server', $result);
    }

    public function test_runner_reports_an_unknown_tool_to_the_model(): void
    {
        $result = app(ToolCallRunner::class)->run(
            ['web_search' => app(WebSearchTool::class)],
            'delete_everything',
            '{}'
        );

        $this->assertStringContainsString('not available', $result);
        $this->assertStringContainsString('web_search', $result);
    }

    public function test_runner_sanitizes_before_dispatching(): void
    {
        $result = app(ToolCallRunner::class)->run(
            ['web_search' => app(WebSearchTool::class)],
            'web_search',
            '{"query": "weather Kassel<|\"|>"}'
        );

        $this->assertSame('mocked result', $result);
        $this->assertSame(['query' => 'weather Kassel'], $this->calledTool()['arguments']);
    }

    public function test_runner_turns_a_broken_tool_into_a_message_for_the_model(): void
    {
        $exploding = new class implements HawkiToolInterface
        {
            public function getKey(): string
            {
                return 'web_search';
            }

            public function getDefinition(): array
            {
                return ['type' => 'function', 'function' => ['name' => 'web_search', 'description' => '', 'parameters' => $this->getArgumentSchema()]];
            }

            public function getArgumentSchema(): array
            {
                return ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']];
            }

            public function execute(array $arguments, ?string $serverBinding = null): string
            {
                throw new \RuntimeException('search backend exploded');
            }
        };

        $result = app(ToolCallRunner::class)->run(['web_search' => $exploding], 'web_search', '{"query":"php"}');

        $this->assertStringContainsString('search backend exploded', $result);
        $this->assertStringStartsWith('Error:', $result);
    }
}
