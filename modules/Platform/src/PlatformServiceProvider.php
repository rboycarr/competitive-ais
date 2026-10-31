<?php

namespace Modules\Platform;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Platform\Audit\AuditActions;
use Modules\Platform\Console\ApplyDatabaseGrants;
use Modules\Platform\Console\CreateAuditCheckpoint;
use Modules\Platform\Console\RelayOutbox;
use Modules\Platform\Console\VerifyAuditTrail;
use Modules\Platform\Database\TableGrants;
use Modules\Platform\Jobs\CreateAuditCheckpointJob;
use Modules\Platform\Jobs\RelayOutboxJob;
use Modules\Platform\Jobs\VerifyAuditChainJob;
use Modules\Platform\Outbox\OutboxEvents;
use Modules\Platform\Support\ModuleServiceProvider;
use RuntimeException;

/**
 * Shared kernel (docs/01 §3): database accounts and grants, the audit trail (P1-05), and the transactional outbox
 * (P1-06); AccessScope and problem+json errors in later stories.
 */
class PlatformServiceProvider extends ModuleServiceProvider
{
    /**
     * Commands that change or inspect the schema; they run as the migrator account (P1-09, docs/02 §6).
     */
    private const MIGRATOR_COMMANDS = [
        'migrate', 'migrate:fresh', 'migrate:install', 'migrate:refresh', 'migrate:reset',
        'migrate:rollback', 'migrate:status', 'schema:dump',
    ];

    private ?string $connectionBeforeMigratorCommand = null;

    public function register(): void
    {
        $this->app->singleton(TableGrants::class);
        $this->app->singleton(AuditActions::class);
        $this->app->singleton(OutboxEvents::class);
    }

    public function boot(): void
    {
        parent::boot();

        DB::prohibitDestructiveCommands($this->app->isProduction());

        if ($this->app->runningInConsole()) {
            $this->commands([ApplyDatabaseGrants::class, CreateAuditCheckpoint::class, VerifyAuditTrail::class, RelayOutbox::class]);
        }

        $this->runSchemaCommandsAsMigrator();
        $this->scheduleJobs();
    }

    /**
     * Audit checkpoints and verification (P1-05, ADR-0006) and outbox delivery (P1-06); the jobs run on the queue.
     */
    private function scheduleJobs(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(new CreateAuditCheckpointJob)->everyFiveMinutes();
            $schedule->job(new VerifyAuditChainJob)->hourly();
            $schedule->job(new RelayOutboxJob)->everyMinute();
        });
    }

    /**
     * The app account cannot run DDL, so schema commands switch the default connection to the migrator for
     * their duration (unless --database is given) and restore it afterwards. The migrator connection exists
     * only in the `migrator` service (TASK-0003 #1); elsewhere these commands fail with instructions.
     * After migrations, table grants are re-applied so new tables get the app account's privileges immediately.
     */
    private function runSchemaCommandsAsMigrator(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (in_array($event->command, self::MIGRATOR_COMMANDS, true) && ! $event->input->hasParameterOption('--database')) {
                if (config('database.connections.migrator') === null) {
                    throw new RuntimeException(
                        "[{$event->command}] needs the migrator account, which is only available in the migrator service: "
                        ."docker compose run --rm migrator php artisan {$event->command}"
                    );
                }
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
