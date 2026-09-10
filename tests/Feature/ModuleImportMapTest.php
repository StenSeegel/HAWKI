<?php

namespace Tests\Feature;

use App\Services\Routing\CacheBusting\CacheBusterGenerator;
use App\Services\Routing\CacheBusting\ModuleImportMap;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * ES module imports bypass asset(), so an import map has to hand them the
 * cache buster - otherwise a deploy runs a fresh entry point against stale
 * modules (the production create-mode button that never got its handler).
 */
class ModuleImportMapTest extends TestCase
{
    public function test_every_module_file_is_mapped_to_its_versioned_asset_url(): void
    {
        $map = app(ModuleImportMap::class)->build();

        $buster = app(CacheBusterGenerator::class)->getCacheBusterFor('js/translate/UIManager.js');
        $this->assertSame(asset('js/translate/UIManager.js'), $map['imports']['/js/translate/UIManager.js']);
        $this->assertStringEndsWith('/js/translate/UIManager.js?v=' . $buster, $map['imports']['/js/translate/UIManager.js']);
        $this->assertArrayHasKey('/js/modules/transcript/TranscriptUI.js', $map['imports']);

        foreach (ModuleImportMap::DIRECTORIES as $directory) {
            $this->assertNotEmpty(
                array_filter(array_keys($map['imports']), fn ($k) => str_starts_with($k, '/' . $directory . '/')),
                "no modules mapped under $directory"
            );
        }
        foreach ($map['imports'] as $specifier => $url) {
            if (isset(ModuleImportMap::CDN_PACKAGES[$specifier])) {
                continue;
            }
            $this->assertSame(parse_url($url, PHP_URL_PATH), $specifier, 'the key must be the path a relative import resolves to');
            $this->assertFileExists(public_path(ltrim($specifier, '/')));
            $this->assertStringContainsString('?v=', $url, $specifier . ' must carry the cache buster');
        }
    }

    public function test_keys_follow_the_asset_root_when_the_app_lives_under_a_path_prefix(): void
    {
        URL::forceRootUrl('https://example.test/hawki');

        $map = app(ModuleImportMap::class)->build();

        $this->assertArrayHasKey('/hawki/js/translate/UIManager.js', $map['imports']);
        // asset() keeps the scheme of the current request, so only host and path are checked.
        $url = parse_url($map['imports']['/hawki/js/translate/UIManager.js']);
        $this->assertSame('example.test', $url['host']);
        $this->assertSame('/hawki/js/translate/UIManager.js', $url['path']);
        $this->assertStringStartsWith('v=', $url['query']);
    }

    public function test_cdn_packages_are_kept_verbatim(): void
    {
        $map = app(ModuleImportMap::class)->build();

        foreach (ModuleImportMap::CDN_PACKAGES as $specifier => $url) {
            $this->assertSame($url, $map['imports'][$specifier]);
        }
    }

    public function test_the_partial_renders_one_valid_import_map(): void
    {
        $html = view('partials.module-import-map')->render();

        $this->assertSame(1, substr_count($html, '<script type="importmap">'));
        preg_match('/<script type="importmap">(.*)<\/script>/s', $html, $m);
        $json = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('/js/translate/UIManager.js', $json['imports']);
        $this->assertArrayHasKey('@tiptap/extension-mathematics', $json['imports']);
        $this->assertStringNotContainsString('</script', $m[1], 'JSON must not be able to close the script tag');
    }

    public function test_the_layout_declares_the_map_before_any_module_script(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/home.blade.php'));

        $this->assertNotFalse($map = strpos($layout, "@include('partials.module-import-map')"), 'import map include missing');
        $this->assertNotFalse($vite = strpos($layout, '@vite('), '@vite marker missing');
        $this->assertNotFalse($module = strpos($layout, 'type="module"'), 'module script marker missing');
        $this->assertLessThan($vite, $map, 'the map must precede the Vite module scripts');
        $this->assertLessThan($module, $map, 'the map must precede the first module script');

        $translation = file_get_contents(resource_path('views/translate/translation.blade.php'));
        $this->assertStringNotContainsString('importmap', $translation, 'a page may declare only one import map');
    }

    public function test_every_page_with_a_module_script_gets_the_map(): void
    {
        // A module on a page without the map fetches its imports unversioned; the
        // browser then keeps them for as long as it likes. Every view that loads a
        // module has to include the partial itself or extend a layout that does.
        $views = resource_path('views');
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($views)) as $file) {
            if (!str_ends_with($file->getFilename(), '.blade.php') || str_contains($file->getPathname(), 'module-import-map')) {
                continue;
            }
            $blade = file_get_contents($file->getPathname());
            if (!preg_match('/<script[^>]*type=["\']module["\']/', $blade)) {
                continue;
            }
            if (str_contains($blade, "@include('partials.module-import-map')")) {
                continue;
            }
            if (preg_match("/@extends\('([^']+)'\)/", $blade, $m)) {
                $layout = $views . '/' . str_replace('.', '/', $m[1]) . '.blade.php';
                if (is_file($layout) && str_contains(file_get_contents($layout), "@include('partials.module-import-map')")) {
                    continue;
                }
            }
            $offenders[] = str_replace($views . '/', '', $file->getPathname());
        }

        $this->assertSame([], $offenders, 'these views load an ES module without the import map');
    }

    public function test_module_specifiers_carry_no_hand_rolled_versions(): void
    {
        // Import-map keys match the exact resolved URL, so a "?v=" in any module
        // specifier - static, side-effect, re-export or dynamic - escapes the map.
        $files = [];
        foreach (ModuleImportMap::DIRECTORIES as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(public_path($directory))) as $file) {
                if ($file->getExtension() === 'js') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $offenders = [];
        foreach ($files as $path) {
            if (preg_match("/['\"`][^'\"`\\n]*\\.js\\?v=[^'\"`\\n]*['\"`]/", file_get_contents($path))) {
                $offenders[] = str_replace(public_path() . '/', '', $path);
            }
        }
        $this->assertSame([], $offenders, 'the import map versions modules; specifiers must not carry ?v=');
    }
}
