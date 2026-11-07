<?php

namespace Modules\Platform;

use Aws\S3\S3Client;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\FeatureManager;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as S3Adapter;
use League\Flysystem\Filesystem;
use Modules\Platform\Audit\AuditActions;
use Modules\Platform\Console\ApplyDatabaseGrants;
use Modules\Platform\Console\CreateAuditCheckpoint;
use Modules\Platform\Console\ReanchorAuditRange;
use Modules\Platform\Console\RelayOutbox;
use Modules\Platform\Console\RunAuditor;
use Modules\Platform\Console\ShowFeatures;
use Modules\Platform\Console\SwitchFeature;
use Modules\Platform\Console\VerifyAuditTrail;
use Modules\Platform\Database\TableGrants;
use Modules\Platform\Database\TransactionDurationMonitor;
use Modules\Platform\Http\Middleware\AssignRequestId;
use Modules\Platform\Http\Middleware\HandleInertiaRequests;
use Modules\Platform\Http\Middleware\SecurityHeaders;
use Modules\Platform\Http\ProblemDetails;
use Modules\Platform\Jobs\RelayOutboxJob;
use Modules\Platform\Outbox\OutboxEvents;
use Modules\Platform\Storage\PrivateBucketVisibility;
use Modules\Platform\Support\Features;
use Modules\Platform\Support\ModuleServiceProvider;
use RuntimeException;
use Throwable;

/**
 * Shared kernel (docs/01 §3): database accounts and grants, the audit trail (P1-05), the transactional outbox
 * (P1-06), secure HTTP defaults (P1-08) and feature flags; AccessScope in a later story.
 */
class PlatformServiceProvider extends ModuleServiceProvider
{
    /**
     * Commands that change or inspect the schema, and feature:switch (the app account can only read feature flags,
     * TASK-0053 #1); they run as the migrator account (P1-09, docs/02 §6).
     */
    private const MIGRATOR_COMMANDS = [
        'migrate', 'migrate:fresh', 'migrate:install', 'migrate:refresh', 'migrate:reset',
        'migrate:rollback', 'migrate:status', 'schema:dump', 'feature:switch',
    ];

    private ?string $connectionBeforeMigratorCommand = null;

    public function register(): void
    {
        $this->app->singleton(TableGrants::class);
        $this->app->singleton(AuditActions::class);
        $this->app->singleton(OutboxEvents::class);
        $this->app->singleton(TransactionDurationMonitor::class);
    }

    public function boot(): void
    {
        parent::boot();

        DB::prohibitDestructiveCommands($this->app->isProduction());

        if ($this->app->runningInConsole()) {
            $this->commands([
                ApplyDatabaseGrants::class, CreateAuditCheckpoint::class, VerifyAuditTrail::class, ReanchorAuditRange::class,
                RunAuditor::class, RelayOutbox::class,
                ShowFeatures::class, SwitchFeature::class,
            ]);
        }

        $this->runSchemaCommandsAsMigrator();
        $this->auditAnchoring();
        $this->scheduleJobs();
        $this->secureHttpDefaults();
        $this->featureFlags();
        $this->privateObjectStorage();
    }

    /**
     * The `s3-private` filesystem driver for the private buckets (evidence, exports; ADR-0007): Laravel's S3 disk, but
     * writes send no ACL (PrivateBucketVisibility), so the application's key needs no ACL permission. Same client and
     * Laravel adapter as the `s3` driver, so pre-signed URLs work (P1-34).
     */
    private function privateObjectStorage(): void
    {
        Storage::extend('s3-private', function (Application $app, array $config): FilesystemAdapter {
            $client = new S3Client(array_filter([
                'credentials' => ($config['key'] ?? '') !== '' && ($config['secret'] ?? '') !== '' ? ['key' => $config['key'], 'secret' => $config['secret']] : null,
                'region' => $config['region'] ?? null,
                'version' => 'latest',
                'endpoint' => ($config['endpoint'] ?? '') !== '' ? $config['endpoint'] : null,
                'use_path_style_endpoint' => (bool) ($config['use_path_style_endpoint'] ?? false),
            ], fn (mixed $value): bool => $value !== null));
            $adapter = new S3Adapter($client, (string) $config['bucket'], (string) ($config['root'] ?? ''), new PrivateBucketVisibility);

            return new AwsS3V3Adapter(new Filesystem($adapter, $config), $adapter, $config, $client);
        });
    }

    /**
     * Pennant stores the flags (docs/10); Support\Features reads and switches them. Pennant forgets its cached values
     * only after a job succeeds, so a worker would keep a flag's old state after a failed job: forget them before
     * every job instead, so each job reads the stored state. Pennant's purge command would delete flag rows without
     * an audit row, so it is refused (TASK-0053 #4).
     */
    private function featureFlags(): void
    {
        $this->callAfterResolving(FeatureManager::class, fn (FeatureManager $pennant) => Features::defineDefaults($pennant));
        Event::listen(JobProcessing::class, fn () => $this->app->make(FeatureManager::class)->flushCache());
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if ($event->command === 'pennant:purge') {
                throw new RuntimeException('pennant:purge is disabled: feature flags change only through the audited feature:switch command.');
            }
        });

        // Codes only: the flag's name and its new state.
        $this->app->make(AuditActions::class)->define('feature.switched', ['flag' => 'code', 'enabled' => 'bool']);
    }

    /**
     * Request IDs, security headers and problem+json errors on every response (P1-08). The request-ID middleware runs
     * first, so even error responses carry the ID.
     */
    private function secureHttpDefaults(): void
    {
        /** @var Kernel $kernel the application's bound HTTP kernel (resolving the concrete class would build a new one) */
        $kernel = $this->app->make(HttpKernel::class);
        $kernel->prependMiddleware(AssignRequestId::class);
        $kernel->pushMiddleware(SecurityHeaders::class);
        $kernel->appendMiddlewareToGroup('web', HandleInertiaRequests::class);
        // Inertia pages live in their module (docs/01 §3); tests check that a rendered page file exists there.
        config(['inertia.pages.paths' => glob(base_path('modules/*/resources/js/Pages'), GLOB_ONLYDIR) ?: []]);

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if ($handler instanceof Handler) {
                $handler->renderable(fn (Throwable $e, Request $request) => ProblemDetails::render($e, $request));
            }
        });
    }

    /**
     * Outbox delivery (P1-06); the job runs on the queue. Audit checkpoints and verification are not scheduled here:
     * they need the signing key, so the auditor service runs them (audit:work, P1-50).
     */
    private function scheduleJobs(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(new RelayOutboxJob)->everyMinute();
        });
    }

    /**
     * Signed audit anchors (P1-50, ADR-0006): the re-anchoring audit action, and the alert on long transactions, which
     * would commit audit rows after their range closed (TASK-0010 #4).
     */
    private function auditAnchoring(): void
    {
        $this->app->make(AuditActions::class)->define('audit.reanchored', [
            'generation' => 'int', 'from' => 'int', 'to' => 'int', 'revision' => 'int', 'count' => 'int', 'previous_count' => 'int', 'reason' => 'text',
        ]);

        $monitor = $this->app->make(TransactionDurationMonitor::class);
        Event::listen(TransactionBeginning::class, $monitor->began(...));
        Event::listen(TransactionCommitted::class, $monitor->committed(...));
        Event::listen(TransactionRolledBack::class, $monitor->rolledBack(...));
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
