<?php

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Audit\AuditCheckpointer;

/** Closes the next due audit range now (P1-05); the scheduler runs the same work as CreateAuditCheckpointJob. */
class CreateAuditCheckpoint extends Command
{
    protected $signature = 'audit:checkpoint';

    protected $description = 'Close the next due audit checkpoint';

    public function handle(AuditCheckpointer $checkpointer): int
    {
        $checkpoint = $checkpointer->close();
        if ($checkpoint === null) {
            $this->components->info('No audit rows due for a checkpoint.');
        } else {
            $this->components->info("Checkpoint closed: IDs {$checkpoint['from']}-{$checkpoint['to']}, {$checkpoint['count']} events, root {$checkpoint['root']}.");
        }

        return self::SUCCESS;
    }
}
