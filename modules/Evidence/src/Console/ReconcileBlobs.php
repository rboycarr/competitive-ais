<?php

namespace Modules\Evidence\Console;

use Illuminate\Console\Command;
use Modules\Evidence\Actions\EvidenceBlobReconciler;
use Modules\Evidence\Models\EvidenceBlob;
use Throwable;

/**
 * Brings every blob's records and stored version in step with its assets (P1-38, TASK-0074; EvidenceBlobReconciler):
 * hold = OR of its assets' holds, retention = their latest date, both pushed to the stored version (extended, never
 * shortened). An object held while none of its assets is held is reported and left held, unless the operator passes
 * --release-unheld. One blob that fails (e.g. its version is missing from the store) does not stop the rest. Exits 1
 * when any blob failed or was found held unexpectedly, for the operator. Scheduled daily; any deleting job runs it
 * first (O38).
 */
class ReconcileBlobs extends Command
{
    protected $signature = 'evidence:reconcile-blobs {--release-unheld : Release object holds that no evidence asset holds}';

    protected $description = 'Reconcile evidence blobs with their assets and the object store';

    public function handle(EvidenceBlobReconciler $reconciler): int
    {
        $checked = $repaired = $unexpected = $failed = 0;
        $release = (bool) $this->option('release-unheld');
        EvidenceBlob::query()->select('id')->orderBy('id')->chunkById(500, function ($blobs) use ($reconciler, $release, &$checked, &$repaired, &$unexpected, &$failed): void {
            foreach ($blobs as $blob) {
                $checked++;
                try {
                    $changes = $reconciler->reconcile($blob->id, $release);
                } catch (Throwable $e) {
                    $failed++;
                    report($e);

                    continue;
                }
                if (($changes['object_hold'] ?? null) === 'unexpected') {
                    $unexpected++;
                    unset($changes['object_hold']);
                }
                if ($changes !== []) {
                    $repaired++;
                }
            }
        });

        $summary = "Blobs checked: $checked; repaired: $repaired; held without a held asset: $unexpected; failed: $failed.";
        if ($failed > 0 || $unexpected > 0) {
            $this->components->error($summary);

            return self::FAILURE;
        }
        $this->components->info($summary);

        return self::SUCCESS;
    }
}
