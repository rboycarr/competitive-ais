<?php

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Audit\AuditAnchor;

/**
 * Exports pending manifests, closes the next due audit range, and signs and exports it (P1-05, P1-50). Runs where the
 * signing key is, in the auditor service (audit:work runs it every five minutes).
 */
class CreateAuditCheckpoint extends Command
{
    protected $signature = 'audit:checkpoint';

    protected $description = 'Close the next due audit checkpoint and export its signed manifest';

    public function handle(AuditAnchor $anchor): int
    {
        $result = $anchor->checkpoint();
        $checkpoint = $result['checkpoint'];
        if ($checkpoint === null) {
            $this->components->info('No audit rows due for a checkpoint.');
        } else {
            $this->components->info("Checkpoint closed: IDs {$checkpoint['from']}-{$checkpoint['to']}, {$checkpoint['count']} events, root {$checkpoint['root']}.");
        }
        $this->components->info("Manifests exported: {$result['anchored']}.");

        return self::SUCCESS;
    }
}
