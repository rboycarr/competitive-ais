<?php

namespace Modules\Platform\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Platform\Audit\AuditCheckpointer;

/** Scheduled every five minutes (PlatformServiceProvider). One at a time; the unique keys cover races anyway. */
class CreateAuditCheckpointJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 600;

    public function handle(AuditCheckpointer $checkpointer): void
    {
        $checkpointer->close();
    }
}
