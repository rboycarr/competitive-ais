<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\Kernel as FoundationConsoleKernel;
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
        $kernel = $app->make(ConsoleKernel::class);
        if (! $kernel instanceof FoundationConsoleKernel) {
            throw new RuntimeException('Unexpected console kernel; cannot enable command events for tests.');
        }
        $kernel->rerouteSymfonyCommandEvents();

        return $app;
    }
}
