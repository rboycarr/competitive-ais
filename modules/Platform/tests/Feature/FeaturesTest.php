<?php

namespace Modules\Platform\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use InvalidArgumentException;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Support\Features;
use RuntimeException;
use Tests\TestCase;

/**
 * Feature flags (docs/01, docs/10): stored by Pennant for everyone, off until an operator switches them with the
 * audited feature:switch command; only a stored true is on; workers read the stored state before every job.
 */
class FeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_flag_is_off_until_switched_and_unknown_flags_are_errors(): void
    {
        $features = $this->app->make(Features::class);
        $this->assertFalse($features->enabled(Features::COLLECTION));
        // Pennant stores the default on first read, for everyone (global scope).
        $this->assertSame([['collection', '__laravel_global', 'false']],
            DB::table('features')->get(['name', 'scope', 'value'])->map(fn (object $row): array => array_values((array) $row))->all());

        foreach ([fn () => $features->enabled('colection'), fn () => $features->switch('colection', true)] as $call) {
            try {
                $call();
                $this->fail('An unknown flag was accepted.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Unknown feature flag [colection].', $e->getMessage());
            }
        }

        // A hand-edited value must stay JSON, or every read would fail.
        $this->expectException(QueryException::class);
        DB::table('features')->update(['value' => 'on']);
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
            DB::table('audit_events')->where('action', 'feature.switched')->orderBy('id')->get(['actor_type', 'actor_id', 'subject_type', 'context'])
                ->map(fn (object $row): array => array_values((array) $row))->all());
        $this->assertSame(2, DB::table('audit_events')->count());
    }

    public function test_production_refuses_without_confirmation(): void
    {
        $this->app['env'] = 'production';
        try {
            // Unattended (no terminal to answer the question), the answer is no.
            $this->command('feature:switch collection on')->expectsOutputToContain('Command cancelled.')->assertFailed();
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
        $this->mock(AuditLogger::class, fn ($audit) => $audit->shouldReceive('record')->andThrow(new RuntimeException('audit unavailable')));

        try {
            $this->app->make(Features::class)->switch(Features::COLLECTION, true);
            $this->fail('The switch went through without an audit row.');
        } catch (RuntimeException $e) {
            $this->assertSame('audit unavailable', $e->getMessage());
        }

        // Neither the stored row nor this process's cached copy kept the new state.
        $this->assertFalse($this->app->make(Features::class)->enabled(Features::COLLECTION));
        $this->assertSame(['false'], DB::table('features')->pluck('value')->all());
    }

    public function test_a_worker_reads_the_stored_state_before_every_job(): void
    {
        $features = $this->app->make(Features::class);
        $this->assertFalse($features->enabled(Features::COLLECTION));

        // Another process switches the flag on; this one keeps its earlier read until the next job starts.
        DB::table('features')->where('name', Features::COLLECTION)->update(['value' => 'true']);
        $this->assertFalse($features->enabled(Features::COLLECTION));

        event(new JobProcessing('sync', new SyncJob($this->app, '{"job":"probe"}', 'sync', 'default')));
        $this->assertTrue($features->enabled(Features::COLLECTION));
    }

    private function command(string $command): PendingCommand
    {
        $pending = $this->artisan($command);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
