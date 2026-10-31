<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Discovers modules (ADR-0001): every directory modules/<Module> must provide Modules\<Module>\<Module>ServiceProvider
 * (autoloaded via composer.json "Modules\\<Module>\\": "modules/<Module>/src/"). Platform registers first, the rest
 * alphabetically. A module directory without its provider is an error, not something to skip silently.
 */
class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach (self::discover($this->app->basePath('modules')) as $provider) {
            $this->app->register($provider);
        }
    }

    /**
     * @return list<class-string<ServiceProvider>>
     */
    public static function discover(string $modulesPath): array
    {
        $names = array_map('basename', glob($modulesPath.'/*', GLOB_ONLYDIR) ?: []);
        usort($names, fn (string $a, string $b): int => [$a !== 'Platform', $a] <=> [$b !== 'Platform', $b]);

        $providers = [];
        foreach ($names as $name) {
            if (! preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
                throw new RuntimeException("Module directory [modules/$name] must be a StudlyCase name.");
            }

            $provider = "Modules\\$name\\{$name}ServiceProvider";
            if (! class_exists($provider)) {
                throw new RuntimeException("Module [$name] has no $provider. Check modules/$name/src and the composer.json autoload entry.");
            }

            $providers[] = $provider;
        }

        return $providers;
    }
}
