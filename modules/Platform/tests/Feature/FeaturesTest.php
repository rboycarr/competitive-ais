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
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Support\Features;
use RuntimeException;
use Tests\Support\Concurrently;
use Tests\TestCase;

/**
 * Feature flags (docs/01, docs/10, TASK-0053): one global row per flag, stored off by its migration; the app account
 * only reads them; the audited feature:switch command writes on the migrator account, one switch at a time; only a
 * stored true is on; workers read the stored state before every job. Switches commit on the migrator connection,
 * so the class uses DatabaseMigrations.
 */
class FeaturesTest extends TestCase
{
    use DatabaseMigrations;

    /** MySQL ER_TABLEACCESS_DENIED_ERROR */
    private const ACCESS_DENIED = 1142;

    protected function tearDown(): void
    {
        // The rollback after each test refuses while a flag is on (TASK-0053 #5).
        DB::connection(Features::WRITER)->table('features')->update(['value' => 'false']);

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
            DB::connection(Features::WRITER)->table('features')->update(['value' => 'on']);
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
        $migrator = config('database.connections.'.Features::WRITER);
        config(['database.connections.'.Features::WRITER => null]);
        try {
            $this->command('feature:switch collection on')
                ->expectsOutputToContain('Switching a flag needs the migrator account: docker compose run --rm migrator php artisan feature:switch collection on')
                ->assertFailed();
            try {
                $this->app->make(Features::class)->switch(Features::COLLECTION, true);
                $this->fail('A flag was switched without the migrator account.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('needs the migrator account', $e->getMessage());
            }
        } finally {
            config(['database.connections.'.Features::WRITER => $migrator]);
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
        $this->mock(AuditLogger::class, function ($audit): void {
            $audit->shouldReceive('on')->andReturnSelf();
            $audit->shouldReceive('record')->andThrow(new RuntimeException('audit unavailable'));
        });

        try {
            $this->app->make(Features::class)->switch(Features::COLLECTION, true);
            $this->fail('The switch went through without an audit row.');
        } catch (RuntimeException $e) {
            $this->assertSame('audit unavailable', $e->getMessage());
        }

        $this->assertFalse($features->enabled(Features::COLLECTION));
        $this->assertSame(['false'], DB::table('features')->pluck('value')->all());
    }

    public function test_concurrent_switches_run_one_after_the_other(): void
    {
        // Four "on" at once: one change, one audit row (TASK-0053 #2).
        $results = Concurrently::run(array_fill(0, 4, fn () => app(Features::class)->switch(Features::COLLECTION, true)));
        $this->assertSame(['ok', 'ok', 'ok', 'ok'], array_column($results, 'outcome'));
        $this->assertSame([true], $this->switchedStates());

        // Mixed requests: every audited change is a real transition, and the last one is the stored state.
        foreach (range(1, 3) as $round) {
            $results = Concurrently::run([
                fn () => app(Features::class)->switch(Features::COLLECTION, false),
                fn () => app(Features::class)->switch(Features::COLLECTION, true),
                fn () => app(Features::class)->switch(Features::COLLECTION, false),
                fn () => app(Features::class)->switch(Features::COLLECTION, true),
            ]);
            $this->assertSame(['ok', 'ok', 'ok', 'ok'], array_column($results, 'outcome'), "round $round");
        }
        $states = $this->switchedStates();
        foreach (array_slice($states, 1) as $i => $state) {
            $this->assertNotSame($states[$i], $state, 'Two audited switches in a row set the same state.');
        }
        $this->assertSame(end($states), $this->app->make(Features::class)->enabled(Features::COLLECTION));
    }

    public function test_a_worker_reads_the_stored_state_before_every_job(): void
    {
        $features = $this->app->make(Features::class);
        $this->assertFalse($features->enabled(Features::COLLECTION));

        // An operator switches the flag on; this process keeps its earlier read until the next job starts.
        DB::connection(Features::WRITER)->table('features')->where('name', Features::COLLECTION)->update(['value' => 'true']);
        $this->assertFalse($features->enabled(Features::COLLECTION));

        event(new JobProcessing('sync', new SyncJob($this->app, '{"job":"probe"}', 'sync', 'default')));
        $this->assertTrue($features->enabled(Features::COLLECTION));
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

        $this->app->make(Features::class)->switch(Features::COLLECTION, true);
        $migration = require base_path('modules/Platform/database/migrations/2026_11_15_000100_create_features_table.php');
        try {
            $migration->down();
            $this->fail('The rollback dropped the features table while a flag was on.');
        } catch (RuntimeException $e) {
            $this->assertSame('A feature flag is on; switch it off with feature:switch before rolling back.', $e->getMessage());
        }
        $this->assertTrue($this->app->make(Features::class)->enabled(Features::COLLECTION));
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
