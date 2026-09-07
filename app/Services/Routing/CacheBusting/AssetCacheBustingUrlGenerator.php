<?php
declare(strict_types=1);


namespace App\Services\Routing\CacheBusting;


use App\Utils\DecoratorTrait;
use Illuminate\Routing\UrlGenerator;

class AssetCacheBustingUrlGenerator extends UrlGenerator
{
    use DecoratorTrait;

    /**
     * The dynamic CSS route (AssetController::serveCss) serves public/css/<name>.css
     * by name. Its URLs are built with route(), never asset(), so they would miss
     * the cache buster and the browser would keep a stale stylesheet across
     * deploys - visible as icons painted with the old rules until a hard reload.
     */
    private const CSS_ROUTE = 'css.get';

    private CacheBusterGenerator $cacheBusterGenerator;

    public function setCacheBusterGenerator(CacheBusterGenerator $cacheBusterGenerator): void
    {
        $this->cacheBusterGenerator = $cacheBusterGenerator;
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
            $path
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
                return $this->attachCacheBusterToUrl($url, 'css/' . $css . '.css');
            }
        }

        return $url;
    }

    private function attachCacheBusterToUrl(string $url, string $path): string
    {
        $cacheBuster = $this->cacheBusterGenerator->getCacheBusterFor($path);
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . 'v=' . $cacheBuster;
    }
}
