<?php

namespace Modules\Platform\Outbox;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Modules\Platform\Support\TypedFields;

/**
 * Records a domain event in outbox_events inside the caller's transaction (P1-06, docs/01 F3, docs/03 Platform),
 * so the event exists exactly when the business change commits. RelayOutboxJob delivers it afterwards.
 */
class OutboxPublisher
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly OutboxEvents $events,
    ) {}

    /**
     * @param  Model  $aggregate  the saved record the event is about
     * @param  array<string, mixed>  $payload  keys and types as defined for the event type (OutboxEvents)
     * @return string the event's ULID, which handlers use for idempotency
     */
    public function publish(Model $aggregate, string $eventType, array $payload = []): string
    {
        $connection = $this->db->connection();
        if ($connection->transactionLevel() === 0) {
            throw new LogicException("OutboxPublisher::publish() must run inside the caller's database transaction ([$eventType]).");
        }

        $payload = TypedFields::validate("outbox event [$eventType]", $this->events->payload($eventType), $payload);
        if ($this->events->handlers($eventType) === []) {
            // An event nobody consumes would be marked dispatched and lost (TASK-0012 #1).
            throw new LogicException("Outbox event [$eventType] has no subscribed handler; register one with OutboxEvents::subscribe().");
        }
        $type = $aggregate->getMorphClass();
        $id = $aggregate->getKey();
        if (strlen($type) > 100 || ! (is_int($id) || (is_string($id) && ctype_digit($id)))) {
            throw new InvalidArgumentException('Outbox aggregates need a saved model with an integer key and a type of at most 100 characters.');
        }

        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        $ulid = (string) Str::ulid();
        $connection->table('outbox_events')->insert([
            'ulid' => $ulid,
            'aggregate_type' => $type,
            'aggregate_id' => (int) $id,
            'event_type' => $eventType,
            'payload' => $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'available_at' => $now,
            'created_at' => $now,
        ]);

        return $ulid;
    }
}
