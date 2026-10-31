<?php

namespace App\Providers;

use App\Database\TableGrants;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Commands that change or inspect the schema; they run as the migrator account (P1-09, docs/02 §6).
     */
    private const MIGRATOR_COMMANDS = [
        'migrate', 'migrate:fresh', 'migrate:install', 'migrate:refresh', 'migrate:reset',
        'migrate:rollback', 'migrate:status', 'schema:dump',
    ];

    private ?string $connectionBeforeMigratorCommand = null;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DB::prohibitDestructiveCommands($this->app->isProduction());

        $this->runSchemaCommandsAsMigrator();
    }

    /**
     * The app account cannot run DDL, so schema commands switch the default connection to the migrator for
     * their duration (unless --database is given) and restore it afterwards. After migrations, table grants
     * are re-applied so new tables get the app account's privileges immediately.
     */
    private function runSchemaCommandsAsMigrator(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (in_array($event->command, self::MIGRATOR_COMMANDS, true) && ! $event->input->hasParameterOption('--database')) {
                $this->connectionBeforeMigratorCommand = DB::getDefaultConnection();
                DB::setDefaultConnection('migrator');
            }
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            if ($this->connectionBeforeMigratorCommand !== null && in_array($event->command, self::MIGRATOR_COMMANDS, true)) {
                DB::setDefaultConnection($this->connectionBeforeMigratorCommand);
                $this->connectionBeforeMigratorCommand = null;
            }
        });

        Event::listen([MigrationsEnded::class, NoPendingMigrations::class], function (MigrationsEnded|NoPendingMigrations $event): void {
            if (! ($event->options['pretend'] ?? false)) {
                $this->app->make(TableGrants::class)->apply();
            }
        });
    }
}
