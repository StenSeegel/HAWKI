<?php

namespace Tests\Feature;

use App\Services\Routing\CacheBusting\CacheBusterGenerator;
use Tests\TestCase;

/**
 * The stylesheets are served through the css.get route, not asset(), so they
 * need their own cache busting or a deploy leaves browsers on the old CSS.
 */
class CssCacheBustingTest extends TestCase
{
    public function test_css_route_url_carries_the_file_cache_buster(): void
    {
        $expected = app(CacheBusterGenerator::class)->getCacheBusterFor('css/style.css');

        $this->assertStringEndsWith('/css/style?v='.$expected, route('css.get', 'style'));
        $this->assertStringEndsWith('/css/style?v='.$expected, route('css.get', ['name' => 'style']));
    }

    public function test_css_route_buster_follows_asset_buster_for_the_same_file(): void
    {
        $this->assertSame(
            parse_url(asset('css/style.css'), PHP_URL_QUERY),
            parse_url(route('css.get', 'style'), PHP_URL_QUERY)
        );
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
}
