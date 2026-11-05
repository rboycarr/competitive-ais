<?php

namespace Modules\Evidence\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use LogicException;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceBlob;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Storage\LockedObjects;

/**
 * Keeps a blob's records and its stored version in step with the assets that reference it (P1-38, ADR-0007, TASK-0074;
 * PO decision O38: the safeguards now, the deleting job with withdrawal or retention expiry).
 * - Retention: a blob is kept until the latest `retention_until` of its assets, never shorter. The stored version's
 *   governance retention is extended first, then the row, so the store never keeps less than the records say.
 *   An asset's date is its last day; the lock runs to the start of the next day, UTC (`objectRetainUntil`).
 * - Hold: the blob's hold is recomputed from its assets (OR) and a missing object hold is set. An object held while
 *   no asset is held is reported (`object_hold` = `unexpected`) and left held: it may be a hold placed outside the
 *   application, or a release that failed (EvidenceHoldService); it is released only on an operator's explicit
 *   `--release-unheld`.
 * - Deletion: `deletable()` is the check any deleting job must pass, in the transaction that deletes: under the blob's
 *   row lock it reconciles first, then requires no asset referencing it, and reads the stored version's own hold and
 *   retention, never `evidence_blobs.legal_hold` alone.
 * Every change runs under a lock on the blob row (asset changes that affect a blob, in EvidenceHoldService and
 * EvidenceScanService, take that lock too), so assets read after it are the committed ones. Repairs are audited as
 * `evidence.blob_reconciled` (codes only) and logged.
 */
final class EvidenceBlobReconciler
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Filesystems $storage,
        private readonly AuditLogger $audit,
    ) {}

    /** When the lock of a version kept through $lastDay (Y-m-d) may lapse: the start of the next day, UTC. */
    public static function objectRetainUntil(string $lastDay): CarbonImmutable
    {
        return CarbonImmutable::parse($lastDay, 'UTC')->startOfDay()->addDay();
    }

    /**
     * Under the caller's lock on the blob row: extends its retention to the latest date of its assets, the stored
     * version first. Never shortens it. Returns whether it changed.
     */
    public function extendToAssets(EvidenceBlob $blob): bool
    {
        $latest = EvidenceAsset::query()->where('evidence_blob_id', $blob->id)->max('retention_until');
        $current = $blob->retention_until?->toDateString();
        if (! is_string($latest) || ($current !== null && $latest <= $current)) {
            return false;
        }

        LockedObjects::extendRetention($this->storage->disk($blob->storage_disk), $blob->storage_key, $blob->object_version_id, self::objectRetainUntil($latest));
        EvidenceBlob::query()->whereKey($blob->id)->update(['retention_until' => $latest]);
        $blob->setRawAttributes(['retention_until' => $latest] + $blob->getAttributes(), true);

        return true;
    }

    /**
     * Reconciles one blob in its own transaction. Returns what was changed or found (codes); a store error is raised
     * (a missing version is never taken for an unlocked one).
     *
     * @return array<string, string>
     */
    public function reconcile(int $blobId, bool $releaseUnheld = false): array
    {
        return $this->db->transaction(function () use ($blobId, $releaseUnheld): array {
            $blob = EvidenceBlob::query()->lockForUpdate()->find($blobId);

            return $blob === null ? [] : $this->reconcileLocked($blob, $releaseUnheld);
        });
    }

    /**
     * Whether the blob may be deleted now. Must run inside the transaction that deletes it, so the row lock taken
     * here is held until then. Reconciles first; then no asset may reference the blob, and the stored version must
     * be readable, not held, and past its retention, as must the row.
     */
    public function deletable(int $blobId, CarbonImmutable $now): bool
    {
        if ($this->db->connection()->transactionLevel() === 0) {
            throw new LogicException('Check whether a blob is deletable inside the transaction that deletes it.');
        }
        $blob = EvidenceBlob::query()->lockForUpdate()->find($blobId);
        if ($blob === null) {
            return false;
        }
        $this->reconcileLocked($blob, false);
        if (EvidenceAsset::query()->where('evidence_blob_id', $blob->id)->exists()) {
            return false;
        }

        // Read again after the reconciliation, from the store itself.
        $state = LockedObjects::state($this->storage->disk($blob->storage_disk), $blob->storage_key, $blob->object_version_id);
        if ($state === null || $state['legalHold']) {
            return false; // unknown (test fakes) or held: never deletable
        }
        $retention = $blob->retention_until?->toDateString();
        if ($retention !== null && $retention >= $now->utc()->toDateString()) {
            return false;
        }

        return $state['retainUntil'] === null || $state['retainUntil']->lessThanOrEqualTo($now);
    }

    /** @return array<string, string> */
    private function reconcileLocked(EvidenceBlob $blob, bool $releaseUnheld): array
    {
        $changes = [];
        $held = EvidenceAsset::query()->where('evidence_blob_id', $blob->id)->where('legal_hold', true)->exists();
        if ($blob->legal_hold !== $held) {
            EvidenceBlob::query()->whereKey($blob->id)->update(['legal_hold' => $held]);
            $blob->legal_hold = $held;
            $blob->syncOriginalAttribute('legal_hold');
            $changes['record_hold'] = $held ? 'on' : 'off';
        }
        if ($this->extendToAssets($blob)) {
            $changes['retention'] = 'extended';
        }

        $disk = $this->storage->disk($blob->storage_disk);
        $state = LockedObjects::state($disk, $blob->storage_key, $blob->object_version_id);
        if ($state !== null) {
            if ($held && ! $state['legalHold']) {
                LockedObjects::legalHold($disk, $blob->storage_key, $blob->object_version_id, true);
                $changes['object_hold'] = 'on';
            } elseif (! $held && $state['legalHold']) {
                if ($releaseUnheld) {
                    LockedObjects::legalHold($disk, $blob->storage_key, $blob->object_version_id, false);
                }
                $changes['object_hold'] = $releaseUnheld ? 'off' : 'unexpected';
            }
            $retention = $blob->retention_until?->toDateString();
            if ($retention !== null && ($state['retainUntil'] === null || $state['retainUntil']->lessThan(self::objectRetainUntil($retention)))) {
                LockedObjects::extendRetention($disk, $blob->storage_key, $blob->object_version_id, self::objectRetainUntil($retention));
                $changes['object_retention'] = 'extended';
            }
        }

        if ($changes !== []) {
            $this->audit->record('evidence.blob_reconciled', $blob, $changes, Actor::system());
            Log::warning('evidence.blob_reconciled', ['evidence_blob_id' => $blob->id] + $changes);
        }

        return $changes;
    }
}
