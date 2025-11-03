<?php

namespace App\Http\Controllers;

use App\Models\AppCss;
use Illuminate\Support\Facades\Cache;

/**
 * AppCssController - Dynamic CSS Delivery System
 * 
 * This controller manages CSS delivery with intelligent caching and hash-based cache busting.
 * 
 * CSS Loading Priority:
 * 1. Database (editable in admin) - for custom-styles.css and any CSS managed through admin panel
 * 2. Static files in public/css_v2.1.0/ - for all other CSS files
 * 
 * Cache Strategy:
 * - Hash-based cache busting: MD5 hash of file content for automatic invalidation
 * - Controller automatically detects changes and clears outdated cache
 * - Similar to JavaScript hash-based versioning system
 * 
 * Update Scenarios:
 * - Admin edits custom-styles.css → Database updated_at changes → Cache cleared → Immediate effect
 * - Deploy new CSS files → Hash changes → Cache cleared → Immediate effect
 * - No manual cache clearing needed!
 */
class AppCssController extends Controller
{
    /**
     * Get CSS by name with automatic hash-based cache busting
     *
     * @return \Illuminate\Http\Response
     */
    public function getByName(string $name)
    {
        // Check if CSS exists in database
        $dbCss = AppCss::where('name', $name)->first();

        if ($dbCss) {
            // CSS from database (editable in admin, e.g., custom-styles)
            return $this->serveDatabaseCss($name, $dbCss);
        }

        // Fallback to static file
        $cssFilePath = public_path("css_v2.1.0/{$name}.css");
        
        if (file_exists($cssFilePath)) {
            return $this->serveStaticCss($name, $cssFilePath);
        }

        // CSS not found anywhere
        $content = "/* CSS file '{$name}' not found in database or static files */";
        return response($content)->header('Content-Type', 'text/css');
    }

    /**
     * Serve CSS from database with hash-based cache busting
     *
     * @param string $name
     * @param \App\Models\AppCss $dbCss
     * @return \Illuminate\Http\Response
     */
    private function serveDatabaseCss(string $name, $dbCss)
    {
        $cacheKey = "css_db_{$name}";
        $hashKey = "css_db_hash_{$name}";
        
        // Calculate current content hash
        $currentHash = md5($dbCss->content);
        $cachedHash = Cache::get($hashKey);

        // If hash changed, clear old cache
        if ($cachedHash && $cachedHash !== $currentHash) {
            Cache::forget($cacheKey);
            \Log::info("CSS cache cleared for '{$name}' due to content change (DB)");
        }

        // Get or create cached response
        $content = Cache::remember($cacheKey, now()->addDay(), function () use ($dbCss) {
            return $dbCss->content;
        });

        // Store current hash
        Cache::put($hashKey, $currentHash, now()->addDay());

        return response($content)->header('Content-Type', 'text/css');
    }

    /**
     * Serve CSS from static file with hash-based cache busting
     *
     * @param string $name
     * @param string $cssFilePath
     * @return \Illuminate\Http\Response
     */
    private function serveStaticCss(string $name, string $cssFilePath)
    {
        $cacheKey = "css_file_{$name}";
        $hashKey = "css_file_hash_{$name}";
        
        // Calculate current file hash
        $currentHash = md5_file($cssFilePath);
        $cachedHash = Cache::get($hashKey);

        // If hash changed, clear old cache
        if ($cachedHash && $cachedHash !== $currentHash) {
            Cache::forget($cacheKey);
            \Log::info("CSS cache cleared for '{$name}' due to file change (static)");
        }

        // Get or create cached response
        $content = Cache::remember($cacheKey, now()->addDay(), function () use ($cssFilePath) {
            return file_get_contents($cssFilePath);
        });

        // Store current hash
        Cache::put($hashKey, $currentHash, now()->addDay());

        return response($content)->header('Content-Type', 'text/css');
    }

    /**
     * Update CSS in database and clear cache
     */
    public static function updateCss(string $name, string $content): bool
    {
        try {
            AppCss::updateOrCreate(
                ['name' => $name],
                ['content' => $content]
            );

            // Clear both content cache and hash
            Cache::forget("css_db_{$name}");
            Cache::forget("css_db_hash_{$name}");

            return true;
        } catch (\Exception $e) {
            \Log::error("Error updating CSS {$name}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Clear all CSS caches (both content and hashes)
     */
    public static function clearCaches(): void
    {
        // Clear database CSS caches
        $cssItems = AppCss::all();
        foreach ($cssItems as $css) {
            Cache::forget("css_db_{$css->name}");
            Cache::forget("css_db_hash_{$css->name}");
        }

        // Clear common static file caches
        $staticCssFiles = [
            'style',
            'home-style',
            'chat_modules',
            'login_style',
            'settings_style',
            'handshake_style',
            'print_styles',
            'hljs_custom',
        ];

        foreach ($staticCssFiles as $fileName) {
            Cache::forget("css_file_{$fileName}");
            Cache::forget("css_file_hash_{$fileName}");
        }

        \Log::info('All CSS caches cleared');
    }
}
