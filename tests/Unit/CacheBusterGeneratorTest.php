<?php

namespace Tests\Unit;

use App\Services\Routing\CacheBusting\CacheBusterGenerator;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The buster must follow the served content: a file that arrives with a new
 * mtime but the same bytes keeps its URL (warm caches survive a release), a
 * file with new bytes gets a new one, and content that lives in the database
 * gets one at all.
 */
class CacheBusterGeneratorTest extends TestCase
{
    private string $publicPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publicPath = sys_get_temp_dir() . '/hawki-cache-buster-' . uniqid();
        File::makeDirectory($this->publicPath . '/js', 0777, true);
        $this->app->usePublicPath($this->publicPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicPath);
        parent::tearDown();
    }

    private function generator(): CacheBusterGenerator
    {
        // A fresh instance drops the per-request memo, like a new request would.
        $this->app->forgetInstance(CacheBusterGenerator::class);
        return $this->app->make(CacheBusterGenerator::class);
    }

    public function test_the_buster_follows_the_content_not_the_mtime(): void
    {
        $file = $this->publicPath . '/js/a.js';
        File::put($file, 'one');
        touch($file, 1_000_000);
        $first = $this->generator()->getCacheBusterFor('js/a.js');

        // Same bytes, a later mtime (a fresh checkout on a CI build): same URL.
        touch($file, 2_000_000);
        $this->assertSame($first, $this->generator()->getCacheBusterFor('js/a.js'));

        File::put($file, 'two');
        $this->assertNotSame($first, $this->generator()->getCacheBusterFor('js/a.js'));
    }

    public function test_a_missing_file_gets_the_base_buster(): void
    {
        $generator = $this->generator();

        $this->assertSame(
            $generator->getCacheBusterFor('js/missing.js'),
            $generator->getCacheBusterFor('css/also-missing.css')
        );
    }

    public function test_the_content_buster_changes_with_the_content_and_the_version(): void
    {
        $generator = $this->generator();

        $this->assertSame($generator->getCacheBusterForContent('a{}'), $generator->getCacheBusterForContent('a{}'));
        $this->assertNotSame($generator->getCacheBusterForContent('a{}'), $generator->getCacheBusterForContent('b{}'));

        config(['app.cache_buster' => 'rotated']);
        $this->assertNotSame($generator->getCacheBusterForContent('a{}'), $this->generator()->getCacheBusterForContent('a{}'));
    }

    public function test_the_per_request_memo_is_used_for_repeated_lookups(): void
    {
        $file = $this->publicPath . '/js/a.js';
        File::put($file, 'one');
        $generator = $this->generator();
        $first = $generator->getCacheBusterFor('js/a.js');

        // Within one request the answer is fixed even if the file changes underneath;
        // the next request sees the new content.
        File::put($file, 'two, longer');
        $this->assertSame($first, $generator->getCacheBusterFor('js/a.js'));
        $this->assertNotSame($first, $this->generator()->getCacheBusterFor('js/a.js'));
    }
}
