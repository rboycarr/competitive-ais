<?php

namespace Modules\Platform\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Modules\Platform\Jobs\RelayOutboxJob;
use Modules\Platform\Outbox\OutboxEvents;
use Modules\Platform\Outbox\OutboxHandler;
use Modules\Platform\Outbox\OutboxMessage;
use Modules\Platform\Outbox\OutboxPublisher;
use Modules\Platform\Outbox\OutboxRelay;
use RuntimeException;
use Tests\TestCase;

/**
 * P1-06 / docs/01 F3 and §5 / docs/09 (queue and idempotency row): events are written with the business change,
 * delivered at least once, and each handler's database work happens once per event. Runs on MySQL 8.4 as the
 * real app account.
 */
class OutboxTest extends TestCase
{
    use DatabaseMigrations;

    private OutboxProbeAggregate $aggregate;

    protected function setUp(): void
    {
        parent::setUp();

        OutboxProbeHandler::$calls = [];
        OutboxFlakyHandler::$failuresLeft = 0;
        OutboxFlakyHandler::$calls = 0;

        $events = $this->app->make(OutboxEvents::class);
        $events->define('probe.changed', ['item' => 'ulid', 'version' => 'int', 'state' => 'code']);
        $events->define('probe.unhandled');
        $events->subscribe('probe.changed', OutboxProbeHandler::class);

        $id = DB::table('users')->insertGetId(['name' => 'Aggregate', 'email' => 'aggregate@example.test', 'password' => 'x']);
        $this->aggregate = OutboxProbeAggregate::query()->findOrFail($id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_event_is_written_only_with_its_business_change(): void
    {
        try {
            DB::transaction(function () {
                $this->publish();
                throw new RuntimeException('business change failed');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(0, DB::table('outbox_events')->count());

        $ulid = DB::transaction(fn () => $this->publish(['item' => '01JC0000000000000000000000', 'version' => 3, 'state' => 'published']));
        $event = DB::table('outbox_events')->where('ulid', $ulid)->sole();
        $this->assertSame([OutboxProbeAggregate::class, $this->aggregate->getKey(), 'probe.changed', 0],
            [$event->aggregate_type, $event->aggregate_id, $event->event_type, $event->attempts]);
        $this->assertSame(['item' => '01JC0000000000000000000000', 'state' => 'published', 'version' => 3], json_decode($event->payload, true));
    }

    public function test_publishing_outside_a_transaction_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->publish();
    }

    public function test_payloads_are_typed_and_carry_no_free_text(): void
    {
        foreach ([['other' => 1], ['version' => '3'], ['state' => 'Not A Code'], ['item' => 'nope']] as $payload) {
            try {
                DB::transaction(fn () => $this->publish($payload));
                $this->fail('Expected payload '.json_encode($payload).' to be refused.');
            } catch (InvalidArgumentException) {
            }
        }

        $this->expectExceptionMessage('Unknown type [text]');
        $this->app->make(OutboxEvents::class)->define('probe.texty', ['note' => 'text']);
    }

    public function test_handlers_receive_each_event_once_and_events_are_marked_dispatched(): void
    {
        $ulid = DB::transaction(fn () => $this->publish(['version' => 1]));
        DB::transaction(fn () => $this->app->make(OutboxPublisher::class)->publish($this->aggregate, 'probe.unhandled'));

        $this->assertSame(['dispatched' => 2, 'retrying' => 0, 'dead' => 0], $this->relay());
        $this->assertSame([[$ulid, 'probe.changed', ['version' => 1], 1]], OutboxProbeHandler::$calls);
        $this->assertSame(0, DB::table('outbox_events')->whereNull('dispatched_at')->count());
        $this->assertSame(1, DB::table('outbox_receipts')->count());

        $this->assertSame(['dispatched' => 0, 'retrying' => 0, 'dead' => 0], $this->relay());
        $this->assertCount(1, OutboxProbeHandler::$calls);
    }

    public function test_a_failure_is_retried_with_backoff_and_successful_handlers_are_not_rerun(): void
    {
        $this->app->make(OutboxEvents::class)->subscribe('probe.changed', OutboxFlakyHandler::class);
        OutboxFlakyHandler::$failuresLeft = 1;
        DB::transaction(fn () => $this->publish());

        $this->assertSame(['dispatched' => 0, 'retrying' => 1, 'dead' => 0], $this->relay());
        $event = DB::table('outbox_events')->sole();
        $this->assertSame(1, $event->attempts);
        $this->assertNull($event->dispatched_at);
        $this->assertNull($event->claimed_until);
        $this->assertStringContainsString('RuntimeException: flaky', (string) $event->last_error);
        $this->assertGreaterThan(now('UTC')->format('Y-m-d H:i:s.u'), $event->available_at, 'Backoff delays the retry.');

        $this->assertSame(['dispatched' => 0, 'retrying' => 0, 'dead' => 0], $this->relay(), 'Not due yet.');

        Carbon::setTestNow(now()->addHours(2));
        $this->assertSame(['dispatched' => 1, 'retrying' => 0, 'dead' => 0], $this->relay());
        $this->assertCount(1, OutboxProbeHandler::$calls, 'The handler that succeeded first time is not run again.');
        $this->assertSame(2, OutboxFlakyHandler::$calls);
        $this->assertNull(DB::table('outbox_events')->value('last_error'));
    }

    public function test_an_event_is_dead_lettered_after_the_last_attempt(): void
    {
        config()->set('outbox.max_attempts', 2);
        $this->app->make(OutboxEvents::class)->subscribe('probe.changed', OutboxFlakyHandler::class);
        OutboxFlakyHandler::$failuresLeft = 99;
        DB::transaction(fn () => $this->publish());
        $log = Log::spy();

        $this->assertSame(['dispatched' => 0, 'retrying' => 1, 'dead' => 0], $this->relay());
        Carbon::setTestNow(now()->addHours(2));
        $this->assertSame(['dispatched' => 0, 'retrying' => 0, 'dead' => 1], $this->relay());

        $event = DB::table('outbox_events')->sole();
        $this->assertNotNull($event->failed_at);
        $this->assertNull($event->dispatched_at);
        $log->shouldHaveReceived('error')->once()->withArgs(fn (string $message): bool => $message === 'Outbox event dead-lettered');

        Carbon::setTestNow(now()->addDays(2));
        $this->assertSame(['dispatched' => 0, 'retrying' => 0, 'dead' => 0], $this->relay(), 'Dead letters are not retried automatically.');
    }

    public function test_a_claimed_event_is_left_to_its_run_until_the_lease_expires(): void
    {
        DB::transaction(fn () => $this->publish());
        $lease = now('UTC')->addMinutes(5)->format('Y-m-d H:i:s.u');
        DB::table('outbox_events')->update(['claimed_until' => $lease]);

        $this->assertSame(['dispatched' => 0, 'retrying' => 0, 'dead' => 0], $this->relay());
        $this->assertSame([], OutboxProbeHandler::$calls);

        Carbon::setTestNow(now()->addMinutes(6));
        $this->assertSame(['dispatched' => 1, 'retrying' => 0, 'dead' => 0], $this->relay());
    }

    public function test_a_concurrent_delivery_cannot_apply_a_handlers_database_changes_twice(): void
    {
        $this->app->make(OutboxEvents::class)->define('probe.raced');
        $this->app->make(OutboxEvents::class)->subscribe('probe.raced', OutboxRacedHandler::class);
        DB::transaction(fn () => $this->app->make(OutboxPublisher::class)->publish($this->aggregate, 'probe.raced'));

        // While the handler runs, "another relay" records the receipt first (OutboxRacedHandler).
        $this->assertSame(['dispatched' => 1, 'retrying' => 0, 'dead' => 0], $this->relay());

        $this->assertSame(0, DB::table('cache')->where('key', 'like', 'outbox-probe:%')->count(), "This run's changes were rolled back.");
        $this->assertSame(1, DB::table('outbox_receipts')->count());
    }

    public function test_the_command_relays_and_the_job_is_scheduled_every_minute(): void
    {
        DB::transaction(fn () => $this->publish());

        $this->assertSame(0, Artisan::call('outbox:relay'));
        $this->assertStringContainsString('1 dispatched', Artisan::output());

        $events = collect($this->app->make(Schedule::class)->events())->map(fn ($event): array => [(string) $event->description, $event->expression]);
        $this->assertContains([RelayOutboxJob::class, '* * * * *'], $events->all());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function publish(array $payload = []): string
    {
        return $this->app->make(OutboxPublisher::class)->publish($this->aggregate, 'probe.changed', $payload);
    }

    /**
     * @return array{dispatched: int, retrying: int, dead: int}
     */
    private function relay(): array
    {
        return $this->app->make(OutboxRelay::class)->run();
    }
}

/** A saved model with an integer key, used as an outbox aggregate. */
final class OutboxProbeAggregate extends Model
{
    protected $table = 'users';
}

final class OutboxProbeHandler implements OutboxHandler
{
    /** @var list<array{string, string, array<string, mixed>, int}> */
    public static array $calls = [];

    public function handle(OutboxMessage $message): void
    {
        self::$calls[] = [$message->ulid, $message->eventType, $message->payload, $message->attempt];
    }
}

final class OutboxFlakyHandler implements OutboxHandler
{
    public static int $failuresLeft = 0;

    public static int $calls = 0;

    public function handle(OutboxMessage $message): void
    {
        self::$calls++;
        if (self::$failuresLeft-- > 0) {
            throw new RuntimeException('flaky');
        }
    }
}

/** Writes a row, then simulates a second relay that records the receipt first, on its own connection. */
final class OutboxRacedHandler implements OutboxHandler
{
    public function handle(OutboxMessage $message): void
    {
        DB::table('cache')->insert(['key' => 'outbox-probe:'.$message->ulid, 'value' => 'x', 'expiration' => 0]);

        $eventId = DB::connection('migrator')->table('outbox_events')->where('ulid', $message->ulid)->value('id');
        DB::connection('migrator')->table('outbox_receipts')->insert([
            'outbox_event_id' => $eventId, 'handler' => self::class, 'handled_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
    }
}
