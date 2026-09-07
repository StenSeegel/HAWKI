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
                    'urls' => 'extract_multiple_webpages',
                    'research' => 'research_topic',
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
        $this->assertSame('https://example.com/page', $params['arguments']['url']);
    }

    public function test_a_named_url_is_read_in_full_not_as_a_preview(): void
    {
        // A preview is what the batch read returns; a model that names one URL
        // wants what is actually on the page.
        config(['hawki_tools.tools.web_search.max_page_chars' => 4321]);

        app(WebSearchTool::class)->execute(['query' => 'https://example.com/page']);

        $arguments = $this->calledTool()['arguments'];
        $this->assertTrue($arguments['full_content']);
        $this->assertSame(4321, $arguments['max_length']);
    }

    public function test_a_www_url_is_normalised_to_https(): void
    {
        app(WebSearchTool::class)->execute(['query' => 'www.example.com/page']);

        $this->assertSame(
            'https://www.example.com/page',
            $this->calledTool()['arguments']['url']
        );
    }

    public function test_a_sentence_mentioning_a_url_is_still_a_search(): void
    {
        app(WebSearchTool::class)->execute(['query' => 'what does https://example.com say about php']);

        $this->assertSame('google_search', $this->calledTool()['name']);
    }

    public function test_a_deep_query_routes_to_the_research_synthesis(): void
    {
        app(WebSearchTool::class)->execute(['query' => 'Stand der Forschung zu Perowskit-Solarzellen', 'depth' => 'deep']);

        $params = $this->calledTool();
        $this->assertSame('research_topic', $params['name']);
        $this->assertSame(
            ['topic' => 'Stand der Forschung zu Perowskit-Solarzellen', 'depth' => 'advanced'],
            $params['arguments']
        );
    }

    public function test_a_quick_query_stays_on_the_general_search(): void
    {
        app(WebSearchTool::class)->execute(['query' => 'php 8.4 release date', 'depth' => 'quick']);

        $this->assertSame('google_search', $this->calledTool()['name']);
    }

    public function test_deep_research_wins_over_the_local_search(): void
    {
        // The synthesis picks up the university's own pages by itself, and a user
        // who asked for research wants more than a list of site hits.
        app(WebSearchTool::class)->execute(['query' => 'Forschungsprofil der JLU', 'depth' => 'deep']);

        $this->assertSame('research_topic', $this->calledTool()['name']);
    }

    public function test_deep_research_falls_back_to_the_general_search_when_unbound(): void
    {
        // An installation whose binding was saved before the research route
        // existed has to answer rather than fail.
        config(['hawki_tools.bindings.web_search.tools.research' => null]);

        app(WebSearchTool::class)->execute(['query' => 'Perowskit-Solarzellen', 'depth' => 'deep']);

        $params = $this->calledTool();
        $this->assertSame('google_search', $params['name']);
        $this->assertSame(['query' => 'Perowskit-Solarzellen'], $params['arguments']);
    }

    public function test_several_urls_route_to_the_batch_read(): void
    {
        app(WebSearchTool::class)->execute([
            'urls' => ['https://example.com/a', 'www.example.com/b'],
        ]);

        $params = $this->calledTool();
        $this->assertSame('extract_multiple_webpages', $params['name']);
        $this->assertSame(
            ['urls' => ['https://example.com/a', 'https://www.example.com/b'], 'format' => 'markdown'],
            $params['arguments']
        );
    }

    public function test_the_batch_read_drops_duplicates_and_non_urls_and_caps_at_five(): void
    {
        app(WebSearchTool::class)->execute([
            'urls' => [
                'https://example.com/a',
                'https://example.com/a',
                'not a url',
                'https://example.com/b',
                'https://example.com/c',
                'https://example.com/d',
                'https://example.com/e',
                'https://example.com/f',
            ],
        ]);

        $urls = $this->calledTool()['arguments']['urls'];
        $this->assertCount(5, $urls);
        $this->assertSame('https://example.com/a', $urls[0]);
        $this->assertNotContains('not a url', $urls);
    }

    public function test_the_batch_read_falls_back_to_reading_the_first_page(): void
    {
        config(['hawki_tools.bindings.web_search.tools.urls' => null]);

        app(WebSearchTool::class)->execute([
            'urls' => ['https://example.com/a', 'https://example.com/b'],
        ]);

        $params = $this->calledTool();
        $this->assertSame('extract_webpage_content', $params['name']);
        $this->assertSame('https://example.com/a', $params['arguments']['url']);
    }

    public function test_a_call_without_a_query_or_urls_is_reported_to_the_model(): void
    {
        $result = app(ToolCallRunner::class)->run(
            ['web_search' => app(WebSearchTool::class)],
            'web_search',
            '{"depth":"deep"}'
        );

        $this->assertStringStartsWith('Error:', $result);
        $this->assertStringContainsString('No search query was given', $result);
    }

    public function test_a_urls_only_call_passes_the_schema_check(): void
    {
        $result = app(ToolCallRunner::class)->run(
            ['web_search' => app(WebSearchTool::class)],
            'web_search',
            '{"urls":["https://example.com/a"]}'
        );

        $this->assertSame('mocked result', $result);
        $this->assertSame('extract_multiple_webpages', $this->calledTool()['name']);
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
