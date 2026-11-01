<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests drop and recreate tables. Refuse to boot unless every connection points at a *_test database.
     * This runs before the database traits (RefreshDatabase, DatabaseMigrations) touch anything.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        foreach (['mysql', 'migrator', 'retention'] as $connection) {
            $database = (string) $app['config']->get("database.connections.$connection.database");
            if (! str_ends_with($database, '_test')) {
                throw new RuntimeException("Refusing to run tests: connection [$connection] points at [$database], not a *_test database.");
            }
        }

        // Laravel skips console command events in unit tests. Schema commands rely on them to run as the
        // migrator account (AppServiceProvider), so tests use the same path as the real CLI.
        $app->make(ConsoleKernel::class)->rerouteSymfonyCommandEvents();

        // Argon2id at the production cost takes about a second per hash; tests lower it here, in code only, so no
        // environment variable can weaken production hashing (TASK-0017 #4).
        $app['config']->set(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);

        return $app;
    }

    /**
     * Tests that need real commits (grants, audit, outbox) use DatabaseMigrations and roll every table back
     * afterwards; the others use RefreshDatabase (migrate once, one transaction per test). After a
     * DatabaseMigrations test, RefreshDatabase must migrate again, so its "already migrated" flag is reset.
     */
    protected function tearDown(): void
    {
        $usesMigrations = in_array(DatabaseMigrations::class, class_uses_recursive($this), true);

        parent::tearDown();

        if ($usesMigrations) {
            RefreshDatabaseState::$migrated = false;
        }
    }
}
