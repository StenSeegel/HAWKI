<?php

namespace Tests\Feature;

use App\Models\AppCss;
use App\Services\Frontend\CssCache;
use App\Services\Routing\CacheBusting\CacheBusterGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stylesheets that are files load through asset() like every other static
 * asset. Only the database-managed ones go through the css.get route, and its
 * URL has to be versioned from the database content - or an admin's edit stays
 * invisible behind a year-long immutable cache until users clear it by hand.
 */
class CssCacheBustingTest extends TestCase
{
    use RefreshDatabase;

    public function test_css_route_url_carries_the_file_cache_buster_for_a_file_stylesheet(): void
    {
        $expected = app(CacheBusterGenerator::class)->getCacheBusterFor('css/style.css');

        $this->assertStringEndsWith('/css/style?v='.$expected, route('css.get', 'style'));
        $this->assertStringEndsWith('/css/style?v='.$expected, route('css.get', ['name' => 'style']));
        $this->assertSame(
            parse_url(asset('css/style.css'), PHP_URL_QUERY),
            parse_url(route('css.get', 'style'), PHP_URL_QUERY)
        );
    }

    public function test_custom_styles_url_is_versioned_from_the_database_content(): void
    {
        AppCss::create(['name' => 'custom-styles', 'description' => 'test', 'content' => 'body{color:red}', 'active' => true]);
        $before = route('css.get', 'custom-styles');
        $this->assertStringContainsString('?v=', $before);

        // The admin panel saves and then clears the CSS cache, nothing else.
        AppCss::where('name', 'custom-styles')->update(['content' => 'body{color:blue}']);
        app(CssCache::class)->forgetAll();
        $after = route('css.get', 'custom-styles');

        $this->assertNotSame($before, $after, 'an edit in the admin panel must change the URL');
        $this->assertSame($after, route('css.get', 'custom-styles'), 'the URL is stable while the content is');
        $this->get($after)->assertOk()->assertSee('color:blue', false);
    }

    public function test_custom_styles_buster_does_not_come_from_the_file_of_the_same_name(): void
    {
        AppCss::create(['name' => 'custom-styles', 'description' => 'test', 'content' => 'body{}', 'active' => true]);

        $this->assertNotSame(
            parse_url(asset('css/custom-styles.css'), PHP_URL_QUERY),
            parse_url(route('css.get', 'custom-styles'), PHP_URL_QUERY),
            'public/css/custom-styles.css is not what is served, so it must not be what is versioned'
        );
    }

    public function test_custom_styles_without_a_database_row_serves_a_placeholder(): void
    {
        $this->get(route('css.get', 'custom-styles'))->assertOk()->assertSee('CSS not found');
    }

    public function test_other_routes_are_left_alone(): void
    {
        $this->assertStringNotContainsString('?v=', route('login'));
    }

    public function test_versioned_css_is_cacheable_forever(): void
    {
        $response = $this->get(route('css.get', 'style'));

        $response->assertOk();
        $this->assertStringStartsWith('text/css', (string) $response->headers->get('Content-Type'));
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=31536000', $cacheControl);
        $this->assertStringContainsString('immutable', $cacheControl);
        $this->assertStringContainsString('public', $cacheControl);
    }

    public function test_unversioned_css_must_be_revalidated(): void
    {
        $response = $this->get('/css/style');

        $response->assertOk();
        // PreventBackHistory turns the controller's no-cache into no-store, which
        // is stricter still; what matters is that nothing lets the browser keep it.
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertMatchesRegularExpression('/no-store|no-cache/', $cacheControl);
        $this->assertStringNotContainsString('immutable', $cacheControl);
    }

    public function test_missing_css_is_a_404_and_not_cached(): void
    {
        $response = $this->get('/css/does-not-exist?v=abc');

        $response->assertNotFound();
        $this->assertStringNotContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    public function test_views_use_the_css_route_only_for_database_managed_stylesheets(): void
    {
        $offenders = [];
        foreach ($this->bladeFiles() as $path) {
            $blade = file_get_contents($path);
            // File stylesheets belong to asset(): served by nginx, one URL per file.
            preg_match_all("/route\\('css\\.get',\\s*(?:\\[\\s*'name'\\s*=>\\s*)?'([^']+)'/", $blade, $m);
            foreach ($m[1] as $name) {
                if (!CssCache::isDatabaseManaged($name)) {
                    $offenders[] = "$path loads '$name' through css.get - use asset('css/$name.css')";
                }
            }
            // Database stylesheets must not be loaded as the file of the same name.
            foreach (CssCache::DATABASE_MANAGED as $name) {
                if (str_contains($blade, "asset('css/$name.css')")) {
                    $offenders[] = "$path loads '$name' as a file - use route('css.get', '$name')";
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * @return list<string>
     */
    private function bladeFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($it as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }
}
