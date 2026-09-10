<?php
declare(strict_types=1);


namespace App\Services\Frontend;


use App\Models\AppCss;
use App\Utils\AbstractCache;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
class CssCache extends AbstractCache
{
    /**
     * Stylesheets whose content lives in the app_css table (edited in the admin
     * panel) instead of public/css. Both the css.get controller and the cache
     * buster of its URLs decide by this list, so what is served and what is
     * versioned can never come from different sources.
     */
    public const DATABASE_MANAGED = ['custom-styles'];

    public static function isDatabaseManaged(string $name): bool
    {
        return in_array($name, self::DATABASE_MANAGED, true);
    }

    /**
     * The active content of a database-managed stylesheet, or null when there
     * is none (or the name is not database-managed at all).
     */
    public function content(string $name): ?string
    {
        if (!self::isDatabaseManaged($name)) {
            return null;
        }

        return $this->rememberForeverIfNotNull(
            $name,
            static fn() => AppCss::getByName($name)
        );
    }

    /**
     * Forget all CSS caches
     * @return void
     */
    public function forgetAll(): void
    {
        foreach (AppCss::all() as $css) {
            $this->forget($css->name);
        }
    }
}
