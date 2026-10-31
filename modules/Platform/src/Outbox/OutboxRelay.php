<?php

namespace Modules\Platform\Outbox;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Modules\Platform\Support\TypedFields;
use stdClass;
use Throwable;

/**
 * Delivers outbox events to their handlers (P1-06, docs/01 F3 and §5). At least once, never twice per handler:
 *
 * 1. Claim: a short transaction picks due events with SELECT … FOR UPDATE SKIP LOCKED, so concurrent runs never
 *    take the same event, and stamps a lease (claimed_until) and the attempt count. Handlers then run outside that
 *    transaction, keeping transactions short (the audit lag window in ADR-0006 depends on it).
 * 2. Deliver: each handler runs in its own transaction together with its receipt (outbox_receipts, unique per
 *    event and handler). A handler that already has a receipt is skipped, so a redelivered event only runs the
 *    handlers that have not succeeded yet.
 * 3. Settle: all handlers done → dispatched_at. A failure → retry after exponential backoff with jitter; after
 *    max_attempts → dead letter (failed_at), logged, not retried automatically. Settling only touches an event
 *    whose lease is still this run's, so a run whose lease expired cannot overwrite a newer run's result.
 */
class OutboxRelay
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly OutboxEvents $events,
        private readonly Container $container,
        private readonly Config $config,
    ) {}

    /**
     * @return array{dispatched: int, retrying: int, dead: int, superseded: int}
     *
     * @phpstan-impure
     */
    public function run(): array
    {
        $result = ['dispatched' => 0, 'retrying' => 0, 'dead' => 0, 'superseded' => 0];
        $lease = $this->time(CarbonImmutable::now('UTC')->addSeconds((int) $this->config->get('outbox.lease_seconds')));

        foreach ($this->claim($lease) as $event) {
            $result[$this->deliver($event, $lease)]++;
        }

        return $result;
    }

    /**
     * @return list<stdClass>
     */
    private function claim(string $lease): array
    {
        $db = $this->db->connection();
        $now = $this->time(CarbonImmutable::now('UTC'));

        return $db->transaction(function () use ($db, $now, $lease): array {
            $ids = $db->table('outbox_events')
                ->whereNull('dispatched_at')
                ->whereNull('failed_at')
                ->where('available_at', '<=', $now)
                ->where(fn ($q) => $q->whereNull('claimed_until')->orWhere('claimed_until', '<', $now))
                ->orderBy('id')
                ->limit((int) $this->config->get('outbox.batch'))
                ->lock('FOR UPDATE SKIP LOCKED')
                ->pluck('id')
                ->all();
            if ($ids === []) {
                return [];
            }

            $db->table('outbox_events')->whereIn('id', $ids)->update(['claimed_until' => $lease, 'attempts' => $db->raw('attempts + 1')]);

            return array_values($db->table('outbox_events')->whereIn('id', $ids)->orderBy('id')->get()->all());
        });
    }

    /**
     * @return 'dispatched'|'retrying'|'dead'|'superseded'
     */
    private function deliver(stdClass $event, string $lease): string
    {
        try {
            $message = new OutboxMessage(
                (string) $event->ulid,
                (string) $event->event_type,
                (string) $event->aggregate_type,
                (int) $event->aggregate_id,
                $event->payload === null ? [] : (array) json_decode((string) $event->payload, true, 8, JSON_THROW_ON_ERROR),
                (int) $event->attempts,
            );
            $handlers = $this->events->handlers($message->eventType);
            if ($handlers === []) {
                // Never mark an event nobody consumed as dispatched (TASK-0012 #1); it retries, then dead-letters.
                throw new LogicException("Outbox event type [{$message->eventType}] has no subscribed handler.");
            }
            foreach ($handlers as $handler) {
                $this->runHandler((int) $event->id, $handler, $message);
            }
        } catch (Throwable $e) {
            return $this->fail($event, $lease, $e);
        }

        return $this->settle($event, $lease, ['dispatched_at' => $this->time(CarbonImmutable::now('UTC')), 'last_error' => null])
            ? 'dispatched' : 'superseded';
    }

    /**
     * @param  class-string<OutboxHandler>  $handler
     */
    private function runHandler(int $eventId, string $handler, OutboxMessage $message): void
    {
        $db = $this->db->connection();
        try {
            $db->transaction(function () use ($db, $eventId, $handler, $message): void {
                if ($db->table('outbox_receipts')->where('outbox_event_id', $eventId)->where('handler', $handler)->exists()) {
                    return; // already handled in an earlier delivery
                }
                $instance = $this->container->make($handler);
                if (! $instance instanceof OutboxHandler) {
                    throw new LogicException("Outbox handler [$handler] does not implement ".OutboxHandler::class.'.');
                }
                $instance->handle($message);
                $db->table('outbox_receipts')->insert([
                    'outbox_event_id' => $eventId,
                    'handler' => $handler,
                    'handled_at' => $this->time(CarbonImmutable::now('UTC')),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery recorded the receipt first; this run's database changes were rolled back.
        }
    }

    /**
     * Stores only the exception class and a reference in the table; the message goes to the log, with credential
     * shapes masked (TASK-0012 #2). Exception messages can hold SQL, payload values or upstream responses.
     *
     * @return 'retrying'|'dead'|'superseded'
     */
    private function fail(stdClass $event, string $lease, Throwable $e): string
    {
        $attempts = (int) $event->attempts;
        $reference = (string) Str::ulid();
        $error = $e::class." (ref $reference)";
        $diagnostic = ['ref' => $reference, 'ulid' => $event->ulid, 'event_type' => $event->event_type, 'attempts' => $attempts,
            'exception' => $e::class, 'message' => Str::limit(TypedFields::redact($e->getMessage()), 500)];

        if ($attempts >= (int) $this->config->get('outbox.max_attempts')) {
            Log::error('Outbox event dead-lettered', $diagnostic);

            return $this->settle($event, $lease, ['failed_at' => $this->time(CarbonImmutable::now('UTC')), 'last_error' => $error])
                ? 'dead' : 'superseded';
        }

        Log::warning('Outbox delivery failed; will retry', $diagnostic);
        $base = (int) $this->config->get('outbox.backoff_seconds');
        $delay = min((int) $this->config->get('outbox.backoff_max_seconds'), $base * 2 ** min($attempts - 1, 20));
        $delay = max(1, intdiv($delay * random_int(50, 100), 100));

        return $this->settle($event, $lease, ['available_at' => $this->time(CarbonImmutable::now('UTC')->addSeconds($delay)), 'last_error' => $error])
            ? 'retrying' : 'superseded';
    }

    /**
     * Applies the outcome only if this run still holds the lease. False: the lease expired and another run took
     * the event over, so this run's outcome is discarded and reported as superseded (TASK-0012 #3).
     *
     * @param  array<string, string|null>  $changes
     */
    private function settle(stdClass $event, string $lease, array $changes): bool
    {
        return $this->db->connection()->table('outbox_events')
            ->where('id', $event->id)
            ->where('claimed_until', $lease)
            ->update($changes + ['claimed_until' => null]) === 1;
    }

    private function time(CarbonImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.u');
    }
}
