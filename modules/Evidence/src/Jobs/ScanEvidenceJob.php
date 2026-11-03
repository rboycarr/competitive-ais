<?php

namespace Modules\Evidence\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Evidence\Actions\EvidenceScanService;

final class ScanEvidenceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $assetId) {}

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
}
