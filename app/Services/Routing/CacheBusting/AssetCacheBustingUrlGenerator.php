<?php
declare(strict_types=1);


namespace App\Services\Routing\CacheBusting;


use App\Services\Frontend\CssCache;
use App\Utils\DecoratorTrait;
use Illuminate\Routing\UrlGenerator;

class AssetCacheBustingUrlGenerator extends UrlGenerator
{
    use DecoratorTrait;

    /**
     * The route (AssetController::serveCss) for stylesheets that are not plain
     * files - the ones edited in the admin panel and kept in the database. Its
     * URLs are built with route(), never asset(), so they would miss the cache
     * buster and the browser would keep a stale stylesheet across edits.
     * Stylesheets that are files in public/css are loaded with asset() instead.
     */
    private const CSS_ROUTE = 'css.get';

    private CacheBusterGenerator $cacheBusterGenerator;
    private CssCache $cssCache;

    public function setCacheBusterGenerator(CacheBusterGenerator $cacheBusterGenerator): void
    {
        $this->cacheBusterGenerator = $cacheBusterGenerator;
    }

    public function setCssCache(CssCache $cssCache): void
    {
        $this->cssCache = $cssCache;
    }

    /**
     * @inheritDoc
     */
    public function asset($path, $secure = null): string
    {
        if ($this->isValidUrl($path)) {
            return $path;
        }

        return $this->attachCacheBusterToUrl(
            parent::asset($path, $secure),
            $this->cacheBusterGenerator->getCacheBusterFor($path)
        );
    }

    /**
     * @inheritDoc
     */
    public function route($name, $parameters = [], $absolute = true): string
    {
        $url = parent::route($name, $parameters, $absolute);

        if ($name === self::CSS_ROUTE) {
            $css = is_array($parameters) ? ($parameters['name'] ?? reset($parameters)) : $parameters;
            if (is_string($css) && $css !== '') {
                return $this->attachCacheBusterToUrl($url, $this->cssCacheBuster($css));
            }
        }

        return $url;
    }

    /**
     * The buster has to follow what serveCss will actually send: the database
     * content for a database-managed name, the public/css file otherwise.
     */
    private function cssCacheBuster(string $name): string
    {
        if (CssCache::isDatabaseManaged($name)) {
            $content = $this->cssCache->content($name);
            if ($content !== null) {
                return $this->cacheBusterGenerator->getCacheBusterForContent($content);
            }
        }

        return $this->cacheBusterGenerator->getCacheBusterFor('css/' . $name . '.css');
    }

    private function attachCacheBusterToUrl(string $url, string $cacheBuster): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . 'v=' . $cacheBuster;
    }
}
