<?php

namespace Modules\Platform\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use InvalidArgumentException;
use Laravel\Pennant\FeatureManager;
use Modules\Platform\Audit\AuditActions;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Support\Features;
use RuntimeException;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\Support\Concurrently;
use Tests\TestCase;
use Throwable;

/**
 * Feature flags (docs/01, docs/10, TASK-0053): one global row per flag, stored off by its migration; the app account
 * only reads them; the audited feature:switch command runs as the migrator account, one switch at a time; a flag is
 * on only when stored true and its last audited switch agrees; workers read the stored state before every job.
 * Switches commit on the migrator connection, so the class uses DatabaseMigrations.
 */
class FeaturesTest extends TestCase
{
    use DatabaseMigrations;

    /** MySQL ER_TABLEACCESS_DENIED_ERROR */
    private const ACCESS_DENIED = 1142;

    private const MIGRATOR = 'migrator';

    protected function tearDown(): void
    {
        // The rollback after each test refuses while a flag is on (TASK-0053 #5).
        DB::connection(self::MIGRATOR)->table('features')->update(['value' => 'false']);

        parent::tearDown();
    }

    public function test_every_flag_is_stored_off_and_the_app_can_only_read_it(): void
    {
        $features = $this->app->make(Features::class);
        $this->assertSame(array_map(fn (string $flag): array => [$flag, '__laravel_global', 'false'], array_keys(Features::KNOWN)),
            DB::table('features')->orderBy('name')->get(['name', 'scope', 'value'])->map(fn (object $row): array => array_values((array) $row))->all());
        foreach (array_keys(Features::KNOWN) as $flag) {
            $this->assertFalse($features->enabled($flag));
        }

        foreach ([fn () => $features->enabled('colection'), fn () => $features->switch('colection', true)] as $call) {
            try {
                $call();
                $this->fail('An unknown flag was accepted.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Unknown feature flag [colection].', $e->getMessage());
            }
        }

        // The running app cannot switch a flag, not even through Pennant (TASK-0053 #1, #4).
        foreach ([
            fn () => DB::table('features')->update(['value' => 'true']),
            fn () => DB::table('features')->insert(['name' => 'other', 'scope' => '__laravel_global', 'value' => 'true']),
            fn () => DB::table('features')->delete(),
            fn () => $this->app->make(FeatureManager::class)->store()->globally()->activate(Features::COLLECTION),
            fn () => $features->switch(Features::COLLECTION, true), // outside feature:switch, on the app account
        ] as $write) {
            try {
                $write();
                $this->fail('The app account wrote a feature flag.');
            } catch (QueryException $e) {
                $this->assertSame(self::ACCESS_DENIED, $e->errorInfo[1] ?? null);
            }
        }

        // A hand-edited value must stay JSON, or every read would fail.
        try {
            DB::connection(self::MIGRATOR)->table('features')->update(['value' => 'on']);
            $this->fail('A value that is not JSON was stored.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('features_value_check', $e->getMessage());
        }
        $this->assertSame(['false'], DB::table('features')->pluck('value')->all());
    }

    public function test_switching_is_audited_and_changes_only_when_the_state_differs(): void
    {
        $this->command('feature:status')->expectsOutputToContain('collection | off')->assertSuccessful();

        $this->command('feature:switch collection on')->expectsOutputToContain('The [collection] feature is now on.')->assertSuccessful();
        $this->assertTrue($this->app->make(Features::class)->enabled(Features::COLLECTION));
        $this->command('feature:status')->expectsOutputToContain('collection | on')->assertSuccessful();
        $this->command('feature:switch collection on')->expectsOutputToContain('The [collection] feature is already on.')->assertSuccessful();
        $this->command('feature:switch collection off')->expectsOutputToContain('The [collection] feature is now off.')->assertSuccessful();
        $this->assertFalse($this->app->make(Features::class)->enabled(Features::COLLECTION));

        foreach (['feature:switch colection on', 'feature:switch collection yes', 'feature:switch collection ON'] as $command) {
            $this->command($command)->expectsOutputToContain('Give a known flag (collection) and on or off.')->assertFailed();
        }

        $this->assertSame([['system', null, null, '{"flag": "collection", "enabled": true}'], ['system', null, null, '{"flag": "collection", "enabled": false}']],
            $this->switches(['actor_type', 'actor_id', 'subject_type', 'context']));
        $this->assertSame(2, DB::table('audit_events')->count());
    }

    public function test_switching_needs_the_migrator_account(): void
    {
        $migrator = config('database.connections.'.self::MIGRATOR);
        config(['database.connections.'.self::MIGRATOR => null]);
        try {
            Artisan::call('feature:switch', ['flag' => 'collection', 'state' => 'on']);
            $this->fail('A flag was switched without the migrator account.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('[feature:switch] needs the migrator account, which is only available in the migrator service', $e->getMessage());
        } finally {
            config(['database.connections.'.self::MIGRATOR => $migrator]);
        }
        $this->assertSame(['false'], DB::table('features')->pluck('value')->all());
        $this->assertSame(0, DB::table('audit_events')->count());
    }

    public function test_production_refuses_without_confirmation(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->command('feature:switch collection on')->expectsConfirmation('Are you sure you want to run this command?', 'no')
                ->expectsOutputToContain('Command cancelled.')->assertFailed();
            $this->assertFalse($this->app->make(Features::class)->enabled(Features::COLLECTION));
            $this->assertSame(0, DB::table('audit_events')->count());

            $this->command('feature:switch collection on --force')->assertSuccessful();
            $this->assertTrue($this->app->make(Features::class)->enabled(Features::COLLECTION));
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_a_switch_whose_audit_fails_changes_nothing(): void
    {
        $features = $this->app->make(Features::class);
        $this->assertFalse($features->enabled(Features::COLLECTION));
        $this->mock(AuditLogger::class, fn ($audit) => $audit->shouldReceive('record')->andThrow(new RuntimeException('audit unavailable at db.internal.example:3306')));

        try {
            $this->asMigrator(fn () => $this->app->make(Features::class)->switch(Features::COLLECTION, true));
            $this->fail('The switch went through without an audit row.');
        } catch (RuntimeException $e) {
            $this->assertSame('audit unavailable at db.internal.example:3306', $e->getMessage());
        }

        $this->assertFalse($features->enabled(Features::COLLECTION));
        $this->assertSame(['false'], DB::table('features')->pluck('value')->all());

        // Through the command: exit code 1, and the default connection is the app account again (TASK-0056 #2).
        $default = DB::getDefaultConnection();
        $this->assertSame(1, Artisan::call('feature:switch', ['flag' => 'collection', 'state' => 'on']));
        $output = Artisan::output();
        $this->assertStringContainsString('The [collection] feature was not switched; the reason is in the application log.', $output);
        $this->assertStringNotContainsString('db.internal.example', $output); // the exception's text stays in the log (TASK-0057)
        $this->assertSame($default, DB::getDefaultConnection());
        $this->assertSame(['false'], DB::table('features')->pluck('value')->all());
    }

    public function test_switching_cannot_name_another_connection(): void
    {
        // feature:switch has no --database option, so the console refuses it before anything runs (TASK-0056 #3).
        $default = DB::getDefaultConnection();
        try {
            Artisan::call('feature:switch', ['flag' => 'collection', 'state' => 'on', '--database' => $default]);
            $this->fail('feature:switch accepted --database.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(InvalidOptionException::class, $e);
            $this->assertSame('The "--database" option does not exist.', $e->getMessage());
        }
        $this->assertSame($default, DB::getDefaultConnection());
        $this->assertSame(['false'], DB::table('features')->pluck('value')->all());
        $this->assertSame(0, DB::table('audit_events')->count());
    }

    public function test_concurrent_switches_run_one_after_the_other(): void
    {
        // Four "on" at once: one change, one audit row (TASK-0053 #2).
        $results = Concurrently::run(array_fill(0, 4, fn () => $this->asMigrator(fn () => app(Features::class)->switch(Features::COLLECTION, true))));
        $this->assertSame(['ok', 'ok', 'ok', 'ok'], array_column($results, 'outcome'));
        $this->assertSame([true], $this->switchedStates());

        // Mixed requests: every audited change is a real transition, and the last one is the stored state.
        foreach (range(1, 3) as $round) {
            $results = Concurrently::run([
                fn () => $this->asMigrator(fn () => app(Features::class)->switch(Features::COLLECTION, false)),
                fn () => $this->asMigrator(fn () => app(Features::class)->switch(Features::COLLECTION, true)),
                fn () => $this->asMigrator(fn () => app(Features::class)->switch(Features::COLLECTION, false)),
                fn () => $this->asMigrator(fn () => app(Features::class)->switch(Features::COLLECTION, true)),
            ]);
            $this->assertSame(['ok', 'ok', 'ok', 'ok'], array_column($results, 'outcome'), "round $round");
        }
        $states = $this->switchedStates();
        foreach (array_slice($states, 1) as $i => $state) {
            $this->assertNotSame($states[$i], $state, 'Two audited switches in a row set the same state.');
        }
        $this->app->make(FeatureManager::class)->flushCache();
        $this->assertSame(end($states), $this->app->make(Features::class)->enabled(Features::COLLECTION));
        $this->assertSame([json_encode(end($states))], DB::table('features')->pluck('value')->all());
    }

    public function test_a_worker_reads_the_stored_state_before_every_job(): void
    {
        $features = $this->app->make(Features::class);
        $this->asMigrator(fn () => $features->switch(Features::COLLECTION, true));
        $this->assertTrue($features->enabled(Features::COLLECTION));

        // The stored value changes elsewhere; this process keeps its earlier read until the next job starts.
        DB::connection(self::MIGRATOR)->table('features')->where('name', Features::COLLECTION)->update(['value' => 'false']);
        $this->assertTrue($features->enabled(Features::COLLECTION));

        event(new JobProcessing('sync', new SyncJob($this->app, '{"job":"probe"}', 'sync', 'default')));
        $this->assertFalse($features->enabled(Features::COLLECTION));
    }

    public function test_a_flag_changed_without_the_audited_command_reads_as_off_and_is_reported(): void
    {
        // Directly on the migrator account, e.g. through Pennant in the migrator service: no audit row (TASK-0054 #2).
        DB::connection(self::MIGRATOR)->table('features')->where('name', Features::COLLECTION)->update(['value' => 'true']);
        $features = $this->app->make(Features::class);
        $this->assertFalse($features->enabled(Features::COLLECTION));
        $this->assertFalse($features->matchesAuditTrail(Features::COLLECTION));
        $this->command('feature:status')->expectsOutputToContain('collection | off')
            ->expectsOutputToContain('Changed without feature:switch, so read as off: collection.')->assertFailed();

        // Switching on through the command repairs it (one audit row); a later unaudited "off" is reported too.
        $this->command('feature:switch collection on')->expectsOutputToContain('The [collection] feature is now on.')->assertSuccessful();
        $this->assertTrue($features->enabled(Features::COLLECTION));
        $this->command('feature:status')->expectsOutputToContain('collection | on')->assertSuccessful();
        DB::connection(self::MIGRATOR)->table('features')->where('name', Features::COLLECTION)->update(['value' => 'false']);
        $this->assertFalse($features->matchesAuditTrail(Features::COLLECTION));
        $this->assertFalse($features->enabled(Features::COLLECTION));
        $this->command('feature:switch collection off')->expectsOutputToContain('The [collection] feature is now off.')->assertSuccessful();
        $this->assertTrue($features->matchesAuditTrail(Features::COLLECTION));
        $this->assertSame([true, false], $this->switchedStates());
    }

    public function test_the_audit_lookup_follows_the_index_without_sorting(): void
    {
        // The index the lookup relies on (TASK-0056 #1).
        $this->assertSame(['action', 'occurred_at'], DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())->where('TABLE_NAME', 'audit_events')
            ->where('INDEX_NAME', 'audit_events_action_occurred_at_index')->orderBy('SEQ_IN_INDEX')->pluck('COLUMN_NAME')->all());

        // Enough other audit rows that a table scan is clearly the worse plan, a few switches, then the lookup
        // enabled() runs, explained as sent (TASK-0055 #1).
        $this->app->make(AuditActions::class)->define('probe.filler');
        DB::transaction(function (): void {
            foreach (range(1, 300) as $i) {
                $this->app->make(AuditLogger::class)->record('probe.filler');
            }
        });
        DB::statement('ANALYZE TABLE audit_events');
        $this->asMigrator(function (): void {
            foreach ([true, false, true] as $on) {
                $this->app->make(Features::class)->switch(Features::COLLECTION, $on);
            }
        });
        $this->app->make(FeatureManager::class)->flushCache();
        $lookups = [];
        DB::listen(function ($query) use (&$lookups): void {
            if (str_contains($query->sql, 'audit_events')) {
                $lookups[] = [$query->sql, $query->bindings];
            }
        });
        $this->assertTrue($this->app->make(Features::class)->enabled(Features::COLLECTION));
        $this->assertCount(1, $lookups);

        $plan = (array) DB::selectOne('EXPLAIN '.$lookups[0][0], $lookups[0][1]);
        $this->assertSame('audit_events_action_occurred_at_index', $plan['key']);
        $this->assertStringNotContainsString('filesort', (string) $plan['Extra']);
    }

    public function test_pennant_purge_is_refused_and_the_rollback_waits_until_every_flag_is_off(): void
    {
        try {
            Artisan::call('pennant:purge');
            $this->fail('pennant:purge ran.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('pennant:purge is disabled', $e->getMessage());
        }
        $this->assertSame(1, DB::table('features')->count());

        $this->asMigrator(fn () => $this->app->make(Features::class)->switch(Features::COLLECTION, true));
        $migration = require base_path('modules/Platform/database/migrations/2026_11_15_000100_create_features_table.php');
        try {
            $migration->down();
            $this->fail('The rollback dropped the features table while a flag was on.');
        } catch (RuntimeException $e) {
            $this->assertSame('A feature flag is on; switch it off with feature:switch before rolling back.', $e->getMessage());
        }
        $this->assertTrue($this->app->make(Features::class)->enabled(Features::COLLECTION));
    }

    /**
     * Runs as the migrator account, as feature:switch does (PlatformServiceProvider::MIGRATOR_COMMANDS).
     */
    private function asMigrator(callable $call): mixed
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection(self::MIGRATOR);
        try {
            return $call();
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    private function command(string $command): PendingCommand
    {
        $pending = $this->artisan($command);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    /**
     * @param  list<string>  $columns
     * @return array<int, list<mixed>>
     */
    private function switches(array $columns): array
    {
        return DB::table('audit_events')->where('action', 'feature.switched')->orderBy('id')->get($columns)
            ->map(fn (object $row): array => array_values((array) $row))->values()->all();
    }

    /**
     * The state each feature.switched row recorded, in order.
     *
     * @return list<bool>
     */
    private function switchedStates(): array
    {
        return array_values(array_map(fn (array $row): bool => str_contains((string) $row[0], '"enabled": true'), $this->switches(['context'])));
    }
}
