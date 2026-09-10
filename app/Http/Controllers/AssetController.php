<?php
declare(strict_types=1);


namespace App\Http\Controllers;


use App\Models\AppCss;
use App\Models\AppSystemImage;
use App\Services\Frontend\CssCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AssetController extends Controller
{
    public function getSystemImage(Request $request): RedirectResponse
    {
        $name = $request->route('name');

        $image = AppSystemImage::getByName($name);
        if ($image) {
            return redirect(asset($image->file_path));
        }

        // Fallback to static files
        $fallback = [
            'favicon' => 'favicon.ico',
            'logo_svg' => 'img/logo.svg'
        ];

        return redirect(asset($fallback[$name] ?? 'img/logo.svg'));
    }

    public function serveCss(
        Request  $request,
        string   $name,
        CssCache $cache
    ): Response
    {
        // Database-managed stylesheets (edited in the admin panel) come from the
        // cache-backed table; everything else is a file in public/css. The cache
        // buster of the URL follows the same split, see AssetCacheBustingUrlGenerator.
        $css = $cache->content($name);
        if ($css === null && CssCache::isDatabaseManaged($name)) {
            $css = '/* CSS not found */';
        }
        if ($css === null) {
            $cssPath = public_path("css/{$name}.css");
            if (!file_exists($cssPath)) {
                return response('/* CSS file not found: ' . $name . ' */')
                    ->header('Content-Type', 'text/css')
                    ->setStatusCode(404);
            }
            $css = file_get_contents($cssPath);
        }

        $response = response($css)->header('Content-Type', 'text/css');

        // A versioned URL (?v=<cache buster>, attached by AssetCacheBustingUrlGenerator)
        // changes whenever the content does, so its content may be cached indefinitely.
        // An unversioned URL must be revalidated on every load, otherwise a deploy
        // leaves browsers on the stale stylesheet until they clear their cache.
        if ($request->query('v') !== null) {
            $response->setCache(['public' => true, 'max_age' => 31536000, 'immutable' => true]);
        } else {
            $response->setCache(['no_cache' => true]);
            $response->setEtag(md5($css));
        }

        return $response;
    }
}
