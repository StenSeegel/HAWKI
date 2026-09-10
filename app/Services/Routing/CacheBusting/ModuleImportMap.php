<?php
declare(strict_types=1);


namespace App\Services\Routing\CacheBusting;


use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;

/**
 * Builds the import map that gives ES module imports the asset cache buster.
 *
 * A module entry point is loaded with asset(), so its URL carries ?v=<buster>,
 * but everything it imports ("./translate/UIManager.js") is fetched by the
 * specifier alone. Whether the browser may keep such a file is then up to the
 * web server's cache headers - and with none sent, browsers cache heuristically.
 * After a deploy a page could run a fresh entry point against stale modules:
 * on production the translate page rendered the new create-mode button while
 * the cached UIManager.js from an older release never bound it.
 *
 * The map lists every .js file under public/js - not only the ones known to be
 * modules, because an import is resolved by path and a module directory that
 * nobody added to a list would silently escape the versioning. Each is keyed by
 * the path a relative import resolves to and mapped to its asset() URL. Import
 * maps rewrite resolved URLs, so no import statement has to change and every
 * module goes through the same versioning as any other asset - independent of
 * what the web server does about caching (the nginx templates in _docker send
 * no-cache for unversioned static files as a second line of defence). Classic
 * scripts in the map are inert: import maps only affect module resolution.
 */
class ModuleImportMap
{
    /**
     * public/ relative directories whose .js files are mapped. All of public/js,
     * so a module may live anywhere below it. The single source for the partial
     * and the tests.
     */
    public const DIRECTORIES = ['js'];

    /**
     * Packages loaded from a CDN (the text editor of the translate page). They
     * live in this map because a page may declare only one import map, and the
     * map sits in the shared layout.
     */
    public const CDN_PACKAGES = [
        '@tiptap/core' => 'https://esm.sh/@tiptap/core@3',
        '@tiptap/starter-kit' => 'https://esm.sh/@tiptap/starter-kit@3',
        '@tiptap/markdown' => 'https://esm.sh/@tiptap/markdown@3',
        '@tiptap/extension-table' => 'https://esm.sh/@tiptap/extension-table@3',
        '@tiptap/extension-table-row' => 'https://esm.sh/@tiptap/extension-table-row@3',
        '@tiptap/extension-table-cell' => 'https://esm.sh/@tiptap/extension-table-cell@3',
        '@tiptap/extension-table-header' => 'https://esm.sh/@tiptap/extension-table-header@3',
        '@tiptap/extension-code' => 'https://esm.sh/@tiptap/extension-code@3',
        '@tiptap/extension-code-block-lowlight' => 'https://esm.sh/@tiptap/extension-code-block-lowlight@3',
        '@tiptap/extension-mathematics' => 'https://esm.sh/@tiptap/extension-mathematics@3',
        'lowlight' => 'https://esm.sh/lowlight@3',
    ];

    public function __construct(
        private readonly Application $application,
    ) {
    }

    /**
     * @return array{imports: array<string, string>}
     */
    public function build(): array
    {
        return ['imports' => self::CDN_PACKAGES + $this->modules()];
    }

    /**
     * The map as JSON for a <script type="importmap">. Encoding failures throw
     * instead of silently emitting an empty map, which would take the CDN
     * packages down with it.
     */
    public function toJson(): string
    {
        return json_encode(
            $this->build(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /**
     * @return array<string, string>
     */
    private function modules(): array
    {
        $imports = [];
        foreach (self::DIRECTORIES as $directory) {
            $path = $this->application->publicPath($directory);
            if (! File::isDirectory($path)) {
                continue;
            }
            foreach (File::allFiles($path) as $file) {
                if ($file->getExtension() !== 'js') {
                    continue;
                }
                $url = asset($directory . '/' . $file->getRelativePathname());
                // The key must be exactly what a relative import resolves to, so
                // it is taken from the URL itself - that keeps the map correct
                // when the app is served under a path prefix.
                $imports[parse_url($url, PHP_URL_PATH)] = $url;
            }
        }

        return $imports;
    }
}
