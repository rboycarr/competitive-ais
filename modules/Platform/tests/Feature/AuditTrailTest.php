<?php

namespace Modules\Platform\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditActions;
use Modules\Platform\Audit\AuditCheckpointer;
use Modules\Platform\Audit\AuditHasher;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Audit\AuditVerifier;
use Modules\Platform\Classification;
use Modules\Platform\Events\AuditVerificationFailed;
use Modules\Platform\Jobs\CreateAuditCheckpointJob;
use Modules\Platform\Jobs\VerifyAuditChainJob;
use RuntimeException;
use Tests\TestCase;

/**
 * P1-05 / ADR-0006 / docs/09 #6 and #18: one hashed audit row per action in the caller's transaction; checkpoints
 * over rows older than the lag window; verification reports tampering, deletion, late commits and gaps.
 * Runs on MySQL 8.4 as the real app, retention and migrator accounts.
 */
class AuditTrailTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $actions = $this->app->make(AuditActions::class);
        $actions->define('probe.recorded', ['note', 'items']);
        $actions->define('probe.http');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_one_hashed_row_is_written_in_the_callers_transaction(): void
    {
        $ulid = DB::transaction(fn () => $this->logger()->record('probe.recorded', null, ['note' => 'é/ü', 'items' => [1, 'two', true, null]]));

        $row = (array) DB::table('audit_events')->where('ulid', $ulid)->sole();
        $this->assertSame('system', $row['actor_type']);
        $this->assertNull($row['actor_id']);
        $this->assertSame('internal', $row['classification']);
        $this->assertSame($row['row_hash'], $this->app->make(AuditHasher::class)->rowHash($row)); // @phpstan-ignore argument.type
        $this->assertSame(0, DB::table('audit_event_network')->count(), 'No network data outside HTTP requests.');
    }

    public function test_a_rolled_back_change_leaves_no_audit_row(): void
    {
        try {
            DB::transaction(function () {
                $this->logger()->record('probe.recorded', null, ['note' => 'gone']);
                throw new RuntimeException('business change failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, DB::table('audit_events')->count());
    }

    public function test_recording_outside_a_transaction_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->logger()->record('probe.recorded');
    }

    public function test_undefined_actions_unlisted_keys_and_unsafe_values_are_refused(): void
    {
        $cases = [
            ['probe.undefined', []],
            ['probe.recorded', ['other' => 'x']],
            ['probe.recorded', ['note' => 1.5]],
            ['probe.recorded', ['note' => ['nested' => 'map']]],
            ['probe.recorded', ['items' => [['deep']]]],
            ['probe.recorded', ['note' => str_repeat('x', 2001)]],
        ];
        foreach ($cases as [$action, $context]) {
            try {
                DB::transaction(fn () => $this->logger()->record($action, null, $context));
                $this->fail("Expected [$action] with ".json_encode($context).' to be refused.');
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertSame(0, DB::table('audit_events')->count());
    }

    public function test_secret_like_context_keys_cannot_be_defined(): void
    {
        foreach (['password', 'api_token', 'secret_answer', 'prompt', 'session_cookie', 'signing_key'] as $key) {
            try {
                $this->app->make(AuditActions::class)->define('probe.secrets', [$key]);
                $this->fail("Expected context key [$key] to be refused.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('never stored', $e->getMessage());
            }
        }
    }

    public function test_user_actor_subject_and_classification_are_recorded(): void
    {
        $userId = DB::table('users')->insertGetId(['name' => 'Analyst', 'email' => 'analyst@example.test', 'password' => 'x']);
        $subject = AuditProbeSubject::query()->findOrFail($userId);

        $ulid = DB::transaction(fn () => $this->logger()->record('probe.recorded', $subject, [], Actor::user($userId), Classification::Confidential));

        $row = DB::table('audit_events')->where('ulid', $ulid)->sole();
        $this->assertSame(['user', $userId, AuditProbeSubject::class, $userId, 'confidential'],
            [$row->actor_type, $row->actor_id, $row->subject_type, $row->subject_id, $row->classification]);
    }

    public function test_network_data_is_kept_outside_the_hashed_row_for_routed_requests(): void
    {
        // Recorded first: the test client keeps the last HTTP request bound afterwards.
        $this->recordAt(-60, 'older');
        Route::get('/_test/audit-probe', fn () => DB::transaction(fn () => $this->logger()->record('probe.http')));

        $ulid = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->withHeader('User-Agent', 'ProbeAgent/1.0')
            ->get('/_test/audit-probe')
            ->assertOk()
            ->getContent();

        $event = DB::table('audit_events')->where('ulid', $ulid)->sole();
        $network = DB::table('audit_event_network')->where('audit_event_id', $event->id)->sole();
        $this->assertSame('198.51.100.7', $network->ip_address);
        $this->assertSame(hash('sha256', 'ProbeAgent/1.0'), $network->user_agent_hash);

        // Retention may delete it; the hashed trail stays valid.
        $this->app->make(AuditCheckpointer::class)->close();
        $this->assertSame(1, DB::connection('retention')->table('audit_event_network')->delete());
        $this->assertSame([], $this->verify());
    }

    public function test_the_app_cannot_change_audit_rows_and_retention_cannot_delete_them(): void
    {
        $this->recordAt(-60, 'kept');

        foreach ([fn () => DB::table('audit_events')->update(['action' => 'probe.changed']),
            fn () => DB::table('audit_events')->delete(),
            fn () => DB::connection('retention')->table('audit_events')->delete()] as $change) {
            try {
                $change();
                $this->fail('Expected the change to be denied.');
            } catch (QueryException $e) {
                $this->assertContains($e->errorInfo[1] ?? null, [1142, 1044], $e->getMessage());
            }
        }
        $this->assertSame(1, DB::table('audit_events')->count());
    }

    public function test_checkpoints_close_only_rows_older_than_the_lag_window_and_are_idempotent(): void
    {
        $checkpointer = $this->app->make(AuditCheckpointer::class);
        $this->recordAt(-20, 'a');
        $this->recordAt(-16, 'b');
        $recent = $this->recordAt(-5, 'c');

        $checkpoint = $checkpointer->close();
        $this->assertNotNull($checkpoint);
        $this->assertSame([1, 2], [$checkpoint['from'], $checkpoint['count']]);
        $this->assertLessThan($this->idOf($recent), $checkpoint['to']);
        $this->assertNull($checkpointer->close(), 'Nothing new is due.');

        Carbon::setTestNow(now()->addMinutes(11));
        $next = $checkpointer->close();
        $this->assertNotNull($next);
        $this->assertSame([$checkpoint['to'] + 1, $this->idOf($recent), 1], [$next['from'], $next['to'], $next['count']]);
        $this->assertSame(2, DB::table('audit_checkpoints')->count());
        $this->assertSame([], $this->verify());
    }

    public function test_an_edited_row_is_detected(): void
    {
        $ulid = $this->recordAt(-60, 'original');
        $this->recordAt(-59, 'second');
        $this->app->make(AuditCheckpointer::class)->close();

        DB::connection('migrator')->table('audit_events')->where('ulid', $ulid)->update(['context' => json_encode(['note' => 'forged'])]);

        $this->assertSame(['row_tampered'], array_column($this->verify(), 'type'));
    }

    public function test_an_edited_row_with_a_recomputed_hash_breaks_the_checkpoint_root(): void
    {
        $ulid = $this->recordAt(-60, 'original');
        $this->app->make(AuditCheckpointer::class)->close();

        $row = (array) DB::table('audit_events')->where('ulid', $ulid)->sole();
        $row['context'] = ['note' => 'forged'];
        $hash = $this->app->make(AuditHasher::class)->rowHash($row); // @phpstan-ignore argument.type
        DB::connection('migrator')->table('audit_events')->where('ulid', $ulid)->update(['context' => json_encode(['note' => 'forged']), 'row_hash' => $hash]);

        $this->assertSame(['root_mismatch'], array_column($this->verify(), 'type'));
    }

    public function test_a_deleted_row_is_detected(): void
    {
        $ulid = $this->recordAt(-60, 'deleted');
        $this->recordAt(-59, 'kept');
        $this->app->make(AuditCheckpointer::class)->close();

        DB::connection('migrator')->table('audit_events')->where('ulid', $ulid)->delete();

        $this->assertSame(['rows_missing'], array_column($this->verify(), 'type'));
    }

    public function test_a_late_commit_inside_a_closed_range_is_reported(): void
    {
        $this->recordAt(-60, 'first');
        Carbon::setTestNow(now()->subMinutes(59));
        try {
            DB::transaction(function () {
                $this->logger()->record('probe.recorded', null, ['note' => 'rolled back']);
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }
        Carbon::setTestNow();
        $this->recordAt(-58, 'third');
        $checkpoint = $this->app->make(AuditCheckpointer::class)->close();
        $this->assertNotNull($checkpoint);
        $this->assertSame(2, $checkpoint['count'], 'A rolled-back ID is a gap, not a missing row.');
        $this->assertSame([], $this->verify());

        // A long transaction commits a valid row into the gap after the range was closed.
        $gap = DB::table('audit_events')->min('id') + 1;
        $row = ['ulid' => (string) Str::ulid(), 'occurred_at' => now('UTC')->subMinutes(59)->format('Y-m-d H:i:s.u'),
            'actor_type' => 'system', 'actor_id' => null, 'action' => 'probe.recorded', 'subject_type' => null, 'subject_id' => null,
            'subject_ulid' => null, 'classification' => 'internal', 'request_id' => null, 'context' => null];
        DB::connection('migrator')->table('audit_events')->insert(['id' => $gap] + $row + ['row_hash' => $this->app->make(AuditHasher::class)->rowHash($row)]);

        $this->assertSame([['late_commit', 'high']], array_map(fn (array $i): array => [$i['type'], $i['severity']], $this->verify()));
    }

    public function test_a_removed_checkpoint_is_reported_as_a_chain_gap(): void
    {
        $checkpointer = $this->app->make(AuditCheckpointer::class);
        $this->recordAt(-60, 'a');
        $first = $checkpointer->close();
        $this->recordAt(-50, 'b');
        $checkpointer->close();
        $this->assertNotNull($first);

        DB::connection('migrator')->table('audit_checkpoints')->where('to_audit_id', $first['to'])->delete();

        $this->assertSame(['chain_gap'], array_column($this->verify(), 'type'));
    }

    public function test_the_command_and_the_job_report_failures(): void
    {
        $ulid = $this->recordAt(-60, 'original');
        $this->assertSame(0, Artisan::call('audit:checkpoint'));
        $this->assertSame(0, Artisan::call('audit:verify'));

        DB::connection('migrator')->table('audit_events')->where('ulid', $ulid)->update(['action' => 'probe.forged']);

        $this->assertSame(1, Artisan::call('audit:verify'));
        $this->assertStringContainsString('row_tampered', Artisan::output());

        Event::fake([AuditVerificationFailed::class]);
        $this->app->call([new VerifyAuditChainJob, 'handle']);
        Event::assertDispatched(AuditVerificationFailed::class, fn (AuditVerificationFailed $e): bool => $e->issues[0]['type'] === 'row_tampered');
    }

    public function test_checkpoint_and_verification_jobs_are_scheduled(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())->map(fn ($event): array => [(string) $event->description, $event->expression]);

        $this->assertContains([CreateAuditCheckpointJob::class, '*/5 * * * *'], $events->all());
        $this->assertContains([VerifyAuditChainJob::class, '0 * * * *'], $events->all());
    }

    private function logger(): AuditLogger
    {
        return $this->app->make(AuditLogger::class);
    }

    /** Records an event as if it happened $minutes from now (negative = in the past). */
    private function recordAt(int $minutes, string $note): string
    {
        Carbon::setTestNow(now()->addMinutes($minutes));
        try {
            return DB::transaction(fn () => $this->logger()->record('probe.recorded', null, ['note' => $note]));
        } finally {
            Carbon::setTestNow();
        }
    }

    private function idOf(string $ulid): int
    {
        return (int) DB::table('audit_events')->where('ulid', $ulid)->value('id');
    }

    /**
     * @return list<array{type: string, severity: string, detail: string}>
     */
    private function verify(): array
    {
        return $this->app->make(AuditVerifier::class)->verify();
    }
}

/** A saved model with an integer key, used as an audit subject. */
final class AuditProbeSubject extends Model
{
    protected $table = 'users';
}
