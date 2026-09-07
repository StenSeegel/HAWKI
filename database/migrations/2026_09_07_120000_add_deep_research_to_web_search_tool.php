<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SOURCE = 'hawki_tools';

    /**
     * The routes the web search tool gained for deep research, and the MCP tools
     * of the google_search_http server behind them.
     */
    private const NEW_ROUTES = [
        'urls' => 'extract_multiple_webpages',
        'research' => 'research_topic',
    ];

    /**
     * Marker of the awareness block added below. Its presence is what makes this
     * migration idempotent, and it is also the heading of the block itself.
     */
    private const AWARENESS_MARKER = 'DEEP RESEARCH';

    /**
     * Bring the stored HAWKI tool settings up to the deep research capable web
     * search tool.
     *
     * The Tools screen stores every value it shows as an app_settings override of
     * config/hawki_tools.php, so on an installation whose tools were ever saved
     * there the new config defaults are shadowed by rows written before them: the
     * two new binding routes would be missing and the awareness prompt would never
     * mention deep research, leaving the feature configured but unreachable.
     *
     * Both changes are applied additively - a route is only inserted when it is
     * absent, and the prompt block is only appended when it is not there yet - so
     * an admin's own edits to the prompt survive.
     */
    public function up(): void
    {
        $this->addBindingRoutes();
        $this->extendAwarenessPrompt();
    }

    /**
     * Nothing is rolled back: the routes and the prompt block describe tools the
     * MCP server offers either way, and an installation that never stored tool
     * settings has no rows to restore.
     */
    public function down(): void {}

    private function addBindingRoutes(): void
    {
        // No stored binding at all means the config file is what applies, and it
        // already carries the new routes.
        if (! $this->hasSetting('bindings.web_search.tools.general')) {
            return;
        }

        foreach (self::NEW_ROUTES as $route => $mcpTool) {
            $key = 'bindings.web_search.tools.'.$route;

            if ($this->hasSetting($key)) {
                continue;
            }

            $this->put($key, $mcpTool);
        }
    }

    private function extendAwarenessPrompt(): void
    {
        $key = 'tools.web_search.awareness';
        $stored = $this->getSetting($key);

        if ($stored === null || str_contains($stored, self::AWARENESS_MARKER)) {
            return;
        }

        $this->put($key, rtrim($stored)."\n\n".implode("\n", [
            self::AWARENESS_MARKER,
            'The same tool serves a quick lookup and a deep investigation; the arguments decide which of the two you get.',
            '- `depth: "deep"` returns a synthesis written from 8 to 10 sources instead of a list of hits. Use it whenever the user asks you to research something, to compare options, or for an overview, a report, the state of the art, or advantages and disadvantages - anything that no single page answers.',
            '- `urls: ["...", "..."]` reads up to 5 pages in one call and returns a short preview of each. Use it on the most promising hits of a search to find out which pages actually carry the answer.',
            '- `query: "<one URL>"` reads that single page in full. Use it on the page a preview showed to be the right one.',
            '',
            'A researched answer takes more than one call: search or research first, then read the pages that matter, then answer. Do not stop at the first list of hits when the user asked you to research something, and name the sources you used.',
        ]));
    }

    private function hasSetting(string $path): bool
    {
        return DB::table('app_settings')->where('key', $this->dbKey($path))->exists();
    }

    private function getSetting(string $path): ?string
    {
        $value = DB::table('app_settings')->where('key', $this->dbKey($path))->value('value');

        return $value === null ? null : (string) $value;
    }

    private function put(string $path, string $value): void
    {
        $dbKey = $this->dbKey($path);

        $attributes = [
            'value' => $value,
            'type' => 'string',
            'source' => self::SOURCE,
            'group' => config('settings.group_mapping.'.self::SOURCE, 'tools'),
            'updated_at' => now(),
        ];

        $row = DB::table('app_settings')->where('key', $dbKey);

        if ($row->exists()) {
            $row->update($attributes);
        } else {
            DB::table('app_settings')->insert($attributes + ['key' => $dbKey, 'created_at' => now()]);
        }

        // The settings service caches every key on its own, so a fresh row would
        // otherwise stay invisible until the cache expired.
        Cache::forget('settings.'.$dbKey);
    }

    private function dbKey(string $path): string
    {
        return self::SOURCE.'_'.$path;
    }
};
