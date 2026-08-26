<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Force an isolated in-memory sqlite database for every test run,
     * regardless of the host environment's DB_CONNECTION/DB_DATABASE.
     *
     * phpunit.xml's `<env force="true">` block is supposed to do this via
     * env vars, but in some container setups $_SERVER keeps the original
     * docker-injected values even though getenv()/$_ENV get overridden
     * correctly — Laravel's environment/config resolution reads $_SERVER,
     * so tests silently ran RefreshDatabase (migrate:fresh) against the
     * real dev MySQL database instead of sqlite, wiping it. Setting config
     * directly here, after the app boots, bypasses that env-var propagation
     * entirely instead of depending on it.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');

        // Same $_SERVER propagation problem as above, different symptom:
        // with APP_ENV stuck at the docker-injected value, the app doesn't
        // consider itself to be running unit tests, so VerifyCsrfToken
        // stays active and every POST-based feature test dies with a 419.
        $app['env'] = 'testing';
        $app['config']->set('app.env', 'testing');

        return $app;
    }
}
