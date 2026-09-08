<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\Tools\WebSearchSources;
use Tests\TestCase;

/**
 * The sources a web search result carries, as the citations auxiliary is built
 * from them. The fixtures are shortened copies of what the websearch MCP server
 * actually returns.
 */
class WebSearchSourcesTest extends TestCase
{
    private WebSearchSources $sources;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sources = new WebSearchSources;
    }

    public function test_it_reads_the_sources_section(): void
    {
        $this->sources->collect(<<<'TXT'
        Search results for "Photosynthese":

        1. Grundlagen der Photosynthese
           URL: https://www.uni-marburg.de/de/fb17/grundlagen
           Snippet: Die Photosynthese ist ein grundlegender Prozess.

        ## Sources

        1. [Grundlagen der Photosynthese](https://www.uni-marburg.de/de/fb17/grundlagen)
        2. [Photosynthese – Wikipedia](https://de.wikipedia.org/wiki/Photosynthese)


        Retrieved at: 2026-09-08T08:30:21.032Z
        TXT);

        $this->assertSame([
            [
                'type' => 'url_citation',
                'url' => 'https://www.uni-marburg.de/de/fb17/grundlagen',
                'title' => 'Grundlagen der Photosynthese',
                'start_index' => 0,
                'end_index' => 0,
            ],
            [
                'type' => 'url_citation',
                'url' => 'https://de.wikipedia.org/wiki/Photosynthese',
                'title' => 'Photosynthese – Wikipedia',
                'start_index' => 0,
                'end_index' => 0,
            ],
        ], $this->sources->drain());
    }

    public function test_it_ignores_links_inside_a_source_summary(): void
    {
        // research_topic prints a summary under each entry, and those summaries
        // carry links of their own - pages the tool never read.
        $this->sources->collect(<<<'TXT'
        ## Sources

        1. [C4-Photosynthese](https://www.pflanzenforschung.de/lexikon/c4-293)
           - Ungünstig ist bei der [Photorespiration](https://www.pflanzenforschung.de/lexikon/photo-297) der Verlust an CO2.

        TXT);

        $citations = $this->sources->drain();

        $this->assertCount(1, $citations);
        $this->assertSame('https://www.pflanzenforschung.de/lexikon/c4-293', $citations[0]['url']);
    }

    public function test_it_collects_one_entry_per_page_across_rounds(): void
    {
        // A deep research reads the same page in several rounds; the source list
        // numbers each page once, and the first title found sticks.
        $this->sources->collect("## Sources\n\n1. [C4-Pflanze – Wikipedia](https://de.wikipedia.org/wiki/C4-Pflanze)\n");
        $this->sources->collect("## Sources\n\n1. [C4-Pflanze](https://de.wikipedia.org/wiki/C4-Pflanze)\n2. [Abiweb](https://www.abiweb.de/c4)\n");

        $citations = $this->sources->drain();

        $this->assertCount(2, $citations);
        $this->assertSame('C4-Pflanze – Wikipedia', $citations[0]['title']);
        $this->assertSame('https://www.abiweb.de/c4', $citations[1]['url']);
    }

    public function test_it_falls_back_to_the_result_lines_without_a_sources_section(): void
    {
        // A server that predates the sources section, or a differently bound one.
        $this->sources->collect(<<<'TXT'
        Content from 2 webpages:

        URL: https://www.uni-giessen.de/de/studium/beratung/zsb
        Title: Zentrale Studienberatung (ZSB) — JLU Gießen
        Stats: 571 words

        URL: https://www.uni-giessen.de/nope-404
        Error: Failed to fetch webpage: Request failed with status code 404
        TXT);

        $citations = $this->sources->drain();

        $this->assertCount(2, $citations);
        $this->assertSame('Zentrale Studienberatung (ZSB) — JLU Gießen', $citations[0]['title']);
        // Without a title of its own an entry is labelled by its host, the way
        // the native providers label a source they have no title for.
        $this->assertSame('www.uni-giessen.de', $citations[1]['title']);
    }

    public function test_it_reads_the_numbered_hits_of_an_older_search_result(): void
    {
        $this->sources->collect(<<<'TXT'
        Search results from uni-giessen.de for "Studienberatung":

        1. Zentrale Studienberatung (ZSB)
           URL: https://www.uni-giessen.de/de/studium/beratung/zsb
           Per Telefon, Montag 10:00-12:00 Uhr

        2. Büro für Studienberatung
           URL: https://www.uni-giessen.de/de/org/admin/zust/bfst
        TXT);

        $citations = $this->sources->drain();

        $this->assertCount(2, $citations);
        $this->assertSame('Zentrale Studienberatung (ZSB)', $citations[0]['title']);
        $this->assertSame('Büro für Studienberatung', $citations[1]['title']);
    }

    public function test_it_keeps_out_what_is_not_a_page(): void
    {
        $this->sources->collect("## Sources\n\n1. [Relative](/de/studium)\n2. [Mailto](mailto:info@example.org)\n");

        $this->assertTrue($this->sources->isEmpty());
        $this->assertSame([], $this->sources->drain());
    }

    public function test_it_collects_the_pages_the_answer_links(): void
    {
        $this->sources->collect(<<<'TXT'
        ## Sources

        1. [Anthropic Transparency Hub](https://www.anthropic.com/transparency)
        TXT);

        // The pages the model reached by following links on the fetched page:
        // the native providers list these too.
        $this->sources->collectFromAnswer(<<<'TXT'
        Die Werte stehen in der [Constitution](https://www.anthropic.com/constitution)
        und ausführlich in der [System Card](https://www-cdn.anthropic.com/card.pdf).
        TXT);

        $this->assertSame(
            [
                'https://www.anthropic.com/transparency',
                'https://www.anthropic.com/constitution',
                'https://www-cdn.anthropic.com/card.pdf',
            ],
            array_column($this->sources->drain(), 'url')
        );
    }

    public function test_a_page_keeps_the_title_the_tool_gave_it(): void
    {
        $this->sources->collect(<<<'TXT'
        ## Sources

        1. [Anthropic Transparency Hub](https://www.anthropic.com/transparency)
        TXT);

        $this->sources->collectFromAnswer(
            'Siehe [hier](https://www.anthropic.com/transparency).'
        );

        $citations = $this->sources->drain();

        $this->assertCount(1, $citations);
        $this->assertSame('Anthropic Transparency Hub', $citations[0]['title']);
    }

    public function test_it_ignores_links_inside_fenced_code(): void
    {
        $this->sources->collectFromAnswer(<<<'TXT'
        So sieht es aus:

        ```markdown
        [Beispiel](https://example.org/not-a-source)
        ```

        Belegt in [der Quelle](https://example.org/real).
        TXT);

        $this->assertSame(
            ['https://example.org/real'],
            array_column($this->sources->drain(), 'url')
        );
    }

    public function test_a_source_without_a_title_falls_back_to_its_host(): void
    {
        $this->sources->add('https://www.anthropic.com/transparency/voluntary-commitments');

        $citations = $this->sources->drain();

        $this->assertSame('www.anthropic.com', $citations[0]['title']);
    }

    public function test_draining_empties_the_collector(): void
    {
        // One message's sources must not reach the next one.
        $this->sources->collect("## Sources\n\n1. [Page](https://example.org/page)\n");

        $this->assertCount(1, $this->sources->drain());
        $this->assertSame([], $this->sources->drain());
    }
}
