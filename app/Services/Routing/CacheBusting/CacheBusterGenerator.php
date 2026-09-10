<?php
declare(strict_types=1);


namespace App\Services\Routing\CacheBusting;


use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;

/**
 * The single source of the ?v=<cache buster> every versioned URL carries:
 * asset(), the css.get route and the module import map all come here.
 *
 * The buster is derived from the content that is served, not from when the
 * file arrived on the host. An mtime-based buster rotated every asset on every
 * CI build (the checkout stamps all files at once), and it could not follow
 * content that never lives in a file - the custom stylesheet from the database.
 */
#[Singleton]
class CacheBusterGenerator
{
    /**
     * How long a file's content hash is kept in the application cache. The key
     * carries mtime and size, so a changed file never hits a stale entry; the
     * TTL only bounds how long keys for gone files linger.
     */
    private const CONTENT_HASH_TTL_SECONDS = 30 * 24 * 60 * 60;

    private readonly string $baseCacheBuster;

    /**
     * Per-request memo: a page render asks for the same files many times over
     * (the layout, the import map).
     * @var array<string, string>
     */
    private array $memo = [];

    public function __construct(
        #[Config('app.version')]
        private readonly string      $versionString,
        #[Config('app.cache_buster')]
        private readonly string|null $customCacheBuster,
        private readonly Application $application,
        private readonly Repository  $cache,
    )
    {
        $this->baseCacheBuster = md5(
            implode('-', [
                $this->versionString,
                $this->customCacheBuster ?? 'default',
            ])
        );
    }

    /**
     * Get the cache buster for a public/ file. It changes whenever the file's
     * content, the app version or the custom cache buster changes. A file that
     * does not exist gets the base buster (version + custom buster only).
     */
    public function getCacheBusterFor(string $file): string
    {
        return $this->memo[$file] ??= $this->computeFor($file);
    }

    /**
     * Get the cache buster for content that is served from somewhere other than
     * a file, e.g. a stylesheet kept in the database.
     */
    public function getCacheBusterForContent(string $content): string
    {
        return md5($this->baseCacheBuster . '-' . md5($content));
    }

    private function computeFor(string $file): string
    {
        $publicPath = $this->application->publicPath(ltrim($file, '/'));

        if (!File::exists($publicPath)) {
            return $this->baseCacheBuster;
        }

        // Hashing the content on every request would cost a full read per asset
        // per page; mtime and size in the key make the cached hash self-invalidating.
        // (A rewrite within the same second to the same size is not detected -
        // acceptable for files that arrive by deploy.) PHP's stat cache would hide
        // a rewrite from a long-running process, hence the explicit clear.
        clearstatcache(true, $publicPath);
        $key = sprintf(
            'cache_buster:%s:%d:%d',
            md5($publicPath),
            File::lastModified($publicPath),
            File::size($publicPath)
        );
        $contentHash = $this->cache->remember(
            $key,
            self::CONTENT_HASH_TTL_SECONDS,
            static fn() => md5_file($publicPath)
        );

        return md5($this->baseCacheBuster . '-' . $contentHash);
    }
}
