<?php

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Outbox\OutboxRelay;

/** Delivers due outbox events now (P1-06); the scheduler runs the same work as RelayOutboxJob. */
class RelayOutbox extends Command
{
    protected $signature = 'outbox:relay';

    protected $description = 'Deliver due outbox events to their handlers';

    public function handle(OutboxRelay $relay): int
    {
        $result = $relay->run();
        $this->components->info("Outbox: {$result['dispatched']} dispatched, {$result['retrying']} retrying, {$result['dead']} dead-lettered, {$result['superseded']} superseded.");

        return self::SUCCESS;
    }
}
