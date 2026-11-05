<?php

namespace Modules\Evidence\Console;

use Illuminate\Console\Command;
use Modules\Evidence\Jobs\ScanEvidenceJob;
use Modules\Evidence\Models\EvidenceAsset;
use Throwable;

/**
 * Queues the scan again for assets left `quarantined`/`pending` for `evidence.scan_stale_minutes`: an upload whose
 * dispatch failed after it was committed, or a scan whose worker died. Scheduled every five minutes. ScanEvidenceJob is
 * unique per asset, so an asset whose scan is still queued or running is not queued twice. Assets whose scan failed
 * every try stay `error`, for the operator (`evidence.scan_failed`). One failed dispatch does not stop the rest; the
 * command then exits 1.
 */
class DispatchPendingScans extends Command
{
    protected $signature = 'evidence:dispatch-scans';

    protected $description = 'Queue the virus scan again for evidence left waiting for one';

    public function handle(): int
    {
        $queued = 0;
        $failed = 0;
        EvidenceAsset::query()
            ->where('status', 'quarantined')
            ->where('scan_status', 'pending')
            ->where('updated_at', '<', now('UTC')->subMinutes((int) config('evidence.scan_stale_minutes')))
            ->orderBy('id')
            ->chunkById(500, function ($assets) use (&$queued, &$failed): void {
                foreach ($assets as $asset) {
                    try {
                        ScanEvidenceJob::dispatch($asset->id);
                        $queued++;
                    } catch (Throwable $e) {
                        $failed++;
                        report($e);
                    }
                }
            });

        if ($failed > 0) {
            $this->components->error("Scans queued again: $queued; failed to queue: $failed.");

            return self::FAILURE;
        }
        $this->components->info("Scans queued again: $queued.");

        return self::SUCCESS;
    }
}
