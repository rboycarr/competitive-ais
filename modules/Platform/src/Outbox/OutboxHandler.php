<?php

namespace Modules\Platform\Outbox;

/**
 * Consumer of outbox events (P1-06, docs/01 F3), registered with OutboxEvents::subscribe().
 *
 * Delivery is at least once. The relay runs handle() inside a transaction on the default connection and records a
 * receipt in the same transaction, so database changes made there happen exactly once per event and handler.
 * Effects outside the database (search index, broadcast, mail) must be idempotent on $message->ulid.
 */
interface OutboxHandler
{
    public function handle(OutboxMessage $message): void;
}
