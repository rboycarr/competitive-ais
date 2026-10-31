<?php

namespace Modules\Platform\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base provider for every module (ADR-0001, docs/01 §3). Loads the module's own migrations and routes by
 * convention, so a module is self-contained under modules/<Module>/:
 *
 *   database/migrations/   loaded with the application's migrations
 *   routes/web.php         "web" middleware group
 *   routes/api.php         "api" middleware group, prefixed /api/v1 (versioned public API, CLAUDE.md §9)
 *
 * Subclasses that override boot() must call parent::boot().
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (is_dir($migrations = $this->modulePath('database/migrations'))) {
            $this->loadMigrationsFrom($migrations);
        }

        if (! $this->app->routesAreCached()) {
            if (is_file($web = $this->modulePath('routes/web.php'))) {
                Route::middleware('web')->group($web);
            }
            if (is_file($api = $this->modulePath('routes/api.php'))) {
                Route::middleware('api')->prefix('api/v1')->group($api);
            }
        }
    }

    /**
     * Absolute path inside the module root (modules/<Module>).
     */
    protected function modulePath(string $path = ''): string
    {
        $root = dirname((string) (new ReflectionClass(static::class))->getFileName(), 2);

        return $path === '' ? $root : $root.DIRECTORY_SEPARATOR.$path;
    }
}
