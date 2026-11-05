<?php

namespace Modules\Evidence\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Modules\Evidence\Actions\EvidenceScanService;
use Throwable;

/**
 * Scans one uploaded asset (P1-32b, docs/03 Evidence): queue `evidence`, unique per asset, 3 tries with backoff of 30,
 * 120 and 600 s. Each failed try leaves the scan `error`; the next try sets it `pending` again. After the last try
 * the asset stays in quarantine with scan `error`, and `evidence.scan_failed` is logged for the operator (its
 * quarantine object is kept: the asset still waits for it, so evidence:sweep-quarantine leaves it, P1-38). No
 * content, file name or title is logged.
 */
final class ScanEvidenceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    // Just over the longest life of a job (3 tries × 300 s + 750 s of backoff = 1650 s): if its worker dies, the
    // lock lapses within 30 minutes and evidence:dispatch-scans can queue the scan again (TASK-0071).
    public int $uniqueFor = 1800;

    public function __construct(public readonly int $assetId)
    {
        $this->onQueue('evidence');
    }

    public function uniqueId(): string
    {
        return (string) $this->assetId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(EvidenceScanService $scanner): void
    {
        $scanner->scan($this->assetId);
    }

    public function failed(?Throwable $error): void
    {
        Log::error('evidence.scan_failed', ['asset_id' => $this->assetId, 'error' => $error === null ? null : $error::class]);
    }
}
