<?php

namespace Modules\Evidence\Console;

use Illuminate\Console\Command;
use Modules\Evidence\Jobs\ScanEvidenceJob;
use Modules\Evidence\Models\EvidenceAsset;

/**
 * Queues the scan again for assets left `quarantined`/`pending` for `evidence.scan_stale_minutes`: an upload whose
 * dispatch failed after it was committed, or a scan whose worker died. Scheduled every five minutes. ScanEvidenceJob is
 * unique per asset, so an asset whose scan is still queued or running is not queued twice. Assets whose scan failed
 * every try stay `error`, for the operator (`evidence.scan_failed`).
 */
class DispatchPendingScans extends Command
{
    protected $signature = 'evidence:dispatch-scans';

    protected $description = 'Queue the virus scan again for evidence left waiting for one';

    public function handle(): int
    {
        $count = 0;
        EvidenceAsset::query()
            ->where('status', 'quarantined')
            ->where('scan_status', 'pending')
            ->where('updated_at', '<', now('UTC')->subMinutes((int) config('evidence.scan_stale_minutes')))
            ->orderBy('id')
            ->chunkById(500, function ($assets) use (&$count): void {
                foreach ($assets as $asset) {
                    ScanEvidenceJob::dispatch($asset->id);
                    $count++;
                }
            });

        $this->components->info("Scans queued again: $count.");

        return self::SUCCESS;
    }
}
