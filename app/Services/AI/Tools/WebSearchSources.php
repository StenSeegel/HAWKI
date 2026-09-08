<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * The pages a web search actually used, collected across the tool rounds of one
 * message.
 *
 * Providers with their own web search annotate their answer with citations, and
 * the frontend draws the numbered indices and the source list from those. A
 * search HAWKI runs itself gets no annotations: the MCP result is text, and the
 * URLs are in it. So the tool takes them out of its own result and this holds
 * them until the request that called the tool emits the auxiliary.
 *
 * Registered as a singleton for the same reason {@see SandboxImages} is: the
 * extraction happens inside the tool, which can only return a string, while the
 * auxiliary belongs to the response. The request drains what the tool collected.
 */
class WebSearchSources
{
    /**
     * url => title, in the order the searches turned them up. Keyed by URL
     * because a deep research reads the same page in several rounds, and a
     * source list must number each page once.
     *
     * @var array<string, string>
     */
    private array $sources = [];

    /**
     * Takes the sources out of one MCP result.
     *
     * The '## Sources' section every tool of the websearch MCP server closes
     * with is the one format worth parsing: numbered markdown links, the pages
     * the tool used rather than every URL its text happens to mention. The
     * fallbacks below only matter for a server that predates that section, or a
     * differently bound one - an installation is free to point the binding
     * somewhere else entirely.
     */
    public function collect(string $result): void
    {
        if ($this->collectFromSourcesSection($result)) {
            return;
        }

        $this->collectFromResultLines($result);
    }

    /**
     * Takes the pages the model linked in its own answer.
     *
     * The '## Sources' section only names what the tool itself fetched. A model
     * that follows a link found on a fetched page cites a URL the tool never
     * reported, and the native providers list exactly those as well - a
     * subpage, a PDF, or a different site altogether. Without this the link
     * stays a plain link: the frontend numbers a link only when its URL is a
     * known source.
     *
     * Only markdown links count. A bare URL in prose is as often one the model
     * is quoting as one it read, and fenced code is not prose at all, so both
     * are left alone.
     */
    public function collectFromAnswer(string $answer): void
    {
        $prose = preg_replace('/```.*?(?:```|\z)/su', '', $answer) ?? $answer;

        if (! preg_match_all('/\[([^\]]*)\]\((\S+?)\)/u', $prose, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            $this->add($match[2], $match[1]);
        }
    }

    /**
     * @return bool Whether a '## Sources' section was found.
     */
    private function collectFromSourcesSection(string $result): bool
    {
        $start = mb_strpos($result, '## Sources');

        if ($start === false) {
            return false;
        }

        $section = mb_substr($result, $start);
        $found = false;

        // '1. [Title](https://example.org/page)', one per line. A trailing
        // summary line under an entry carries links of its own, which is why
        // only lines that open with the entry number count.
        if (preg_match_all('/^\s*\d+\.\s*\[([^\]]*)\]\((\S+?)\)/mu', $section, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $this->add($match[2], $match[1]);
                $found = true;
            }
        }

        return $found;
    }

    /**
     * The pre '## Sources' formats: a search hit's indented 'URL:' line, the
     * 'URL:'/'Title:' pairs of a batch read, and the 'Content from:' header of
     * a single page read.
     */
    private function collectFromResultLines(string $result): void
    {
        $title = '';

        foreach (preg_split('/\R/u', $result) ?: [] as $line) {
            if (preg_match('/^\s*(?:URL|Content from):\s*(\S+)/u', $line, $match)) {
                // A search hit prints its title on the line before the URL, a
                // batch read on the line after, so both are tried.
                $this->add($match[1], $title);
                $title = '';

                continue;
            }

            if (preg_match('/^\s*Title:\s*(.+)$/u', $line, $match)) {
                $this->titleLastSource(trim($match[1]));

                continue;
            }

            // A numbered search hit: '1. Some page title'.
            if (preg_match('/^\s*\d+\.\s+(.+)$/u', $line, $match)) {
                $title = trim($match[1]);
            }
        }
    }

    /**
     * Records one source. A URL already collected keeps its position, and gains
     * a title if it had none.
     */
    public function add(string $url, string $title = ''): void
    {
        $url = trim($url);

        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return;
        }

        // Markdown and prose leave punctuation on a URL that is not part of it.
        $url = rtrim($url, '.,;:)]>"\'');
        $title = trim($title);

        if (! isset($this->sources[$url]) || ($this->sources[$url] === '' && $title !== '')) {
            $this->sources[$url] = $title;
        }
    }

    /**
     * Gives the source collected last a title, for the formats that print the
     * title after the URL.
     */
    private function titleLastSource(string $title): void
    {
        if ($title === '' || $this->sources === []) {
            return;
        }

        $url = array_key_last($this->sources);

        if ($this->sources[$url] === '') {
            $this->sources[$url] = $title;
        }
    }

    /**
     * The collected sources in the shape of the 'hawkiToolsCitations'
     * auxiliary, emptying the collector.
     *
     * 'start_index' and 'end_index' are what a provider's annotations carry to
     * mark the cited passage; an MCP result has no offsets into the answer, and
     * the frontend matches citations by URL anyway.
     *
     * @return array<int, array{type: string, url: string, title: string, start_index: int, end_index: int}>
     */
    public function drain(): array
    {
        $citations = [];

        foreach ($this->sources as $url => $title) {
            $citations[] = [
                'type' => 'url_citation',
                'url' => $url,
                'title' => $title !== '' ? $title : $this->hostOf($url),
                'start_index' => 0,
                'end_index' => 0,
            ];
        }

        $this->sources = [];

        return $citations;
    }

    /**
     * The label a source falls back to when no title was found. The native
     * providers show the bare host there, never the whole href, which would
     * stretch a long URL across the chip it is drawn in.
     */
    private function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $url;
    }

    public function isEmpty(): bool
    {
        return $this->sources === [];
    }
}
