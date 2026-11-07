<?php

namespace Modules\Evidence\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Database\DatabaseManager;
use Modules\Evidence\Events\EvidenceEvents;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceBlob;
use Modules\Evidence\Models\EvidenceCustodyEvent;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Outbox\OutboxPublisher;
use Modules\Platform\Storage\LockedObjects;

/**
 * The integrity re-hash (P1-48, docs/03 Evidence: VerifyEvidenceIntegrityJob; PO decision 2026-11-07): reads a blob's
 * stored version, outside any transaction, and compares its SHA-256 with the one recorded when it was stored.
 * - match: `last_verified_at` is set;
 * - mismatch, or the object or version missing from the store: the blob is marked `mismatch` for good (nothing here
 *   turns it back), and each available asset on it gets an `integrity_failed` custody event, an
 *   `evidence.integrity_failed` audit event and an `evidence.integrity_failed` outbox event, in one transaction, so
 *   Intelligence flags the items citing it for re-review. Such an asset is no longer downloadable or citable, and no
 *   longer counts as support (EvidenceLinking).
 * A store that cannot be reached raises (LockedObjects::readVersion), so it is never taken for a missing object.
 * Rows are locked assets (ID order), then the blob: the order of holds, withdrawals and scans.
 */
class EvidenceIntegrityService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly Filesystems $storage,
        private readonly OutboxPublisher $outbox,
    ) {}

    /**
     * @return 'ok'|'mismatch'|'missing'|'skipped' skipped: the blob was already marked
     */
    public function verify(int $blobId): string
    {
        $blob = EvidenceBlob::query()->findOrFail($blobId);
        if ($blob->integrity_status !== 'ok') {
            return 'skipped';
        }
        $found = $this->hash($blob);
        $result = match (true) {
            $found === null => 'missing',
            hash_equals($blob->sha256, $found) => 'ok',
            default => 'mismatch',
        };

        return $this->db->transaction(function () use ($blob, $result): string {
            $ids = EvidenceAsset::query()->where('evidence_blob_id', $blob->id)->orderBy('id')->pluck('id')->all();
            $assets = EvidenceAsset::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $locked = EvidenceBlob::query()->lockForUpdate()->findOrFail($blob->id);
            if ($locked->integrity_status !== 'ok') {
                return 'skipped';
            }
            $now = CarbonImmutable::now('UTC');
            if ($result === 'ok') {
                $locked->forceFill(['last_verified_at' => $now])->save();

                return 'ok';
            }

            $locked->forceFill(['integrity_status' => 'mismatch', 'last_verified_at' => $now])->save();
            // Assets that took this blob after the first read (their scan committed meanwhile) are locked too; none can
            // take it from now on without this blob's lock.
            $late = EvidenceAsset::query()->where('evidence_blob_id', $locked->id)->whereNotIn('id', $assets->keys()->all())->orderBy('id')->lockForUpdate()->get();
            foreach ($assets->values()->concat($late) as $asset) {
                if ($asset->evidence_blob_id !== $locked->id || $asset->status !== 'available') {
                    continue; // withdrawn while the bytes were read
                }
                $event = new EvidenceCustodyEvent;
                $event->forceFill([
                    'evidence_asset_id' => $asset->id,
                    'event' => 'integrity_failed',
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'occurred_at' => $now,
                    'details' => ['result' => $result, 'sha256' => $locked->sha256],
                ])->save();
                $this->audit->record('evidence.integrity_failed', $asset, ['result' => $result, 'sha256' => $locked->sha256], Actor::system());
                $this->outbox->publish($asset, EvidenceEvents::INTEGRITY_FAILED, ['evidence_ulid' => $asset->ulid]);
            }

            return $result;
        });
    }

    /** The SHA-256 of the stored version, or null when the store has no such object. */
    private function hash(EvidenceBlob $blob): ?string
    {
        $stream = LockedObjects::readVersion($this->storage->disk($blob->storage_disk), $blob->storage_key, $blob->object_version_id);
        if ($stream === null) {
            return null;
        }
        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}
