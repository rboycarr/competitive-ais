<?php

namespace Modules\Platform\Outbox;

/** One outbox event as delivered to a handler. */
final readonly class OutboxMessage
{
    /**
     * @param  array<string, mixed>  $payload  typed values only (OutboxEvents schema)
     */
    public function __construct(
        public string $ulid,
        public string $eventType,
        public string $aggregateType,
        public int $aggregateId,
        public array $payload,
        public int $attempt,
    ) {}
}
