<?php

namespace Modules\Platform\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Platform\Outbox\OutboxRelay;

/** Scheduled every minute (PlatformServiceProvider). Concurrent runs are safe (SKIP LOCKED); unique avoids pile-up. */
class RelayOutboxJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public function handle(OutboxRelay $relay): void
    {
        $relay->run();
    }
}
