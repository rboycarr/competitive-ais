<?php

namespace Modules\Evidence\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceBlob;
use Modules\Evidence\Models\EvidenceCustodyEvent;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Storage\LockedObjects;
use Throwable;

/**
 * Legal hold on evidence (P1-35, ADR-0007; PO decisions O35, O36, 2026-11-05):
 * - needs evidence.hold, and the asset must be one the holder sees (hidden = "not found");
 * - only available evidence can be held (the database refuses any other status), and a reason is required both to
 *   set and to release a hold; it is kept in the custody trail, never in the audit context (codes only);
 * - the blob's hold is the OR of its assets' holds, recomputed under row locks (asset, then blob: one order, so two
 *   holders of assets sharing a blob cannot deadlock) and pushed to the stored object version as an S3 legal hold,
 *   which keeps the version from being deleted whatever its retention date.
 * The object must never be released while the records say held: a hold that turns the blob's hold on is pushed
 * inside the transaction (a store failure undoes it); a release that turns it off is pushed after the commit, as
 * the blob's state at that moment under its row lock, so a hold set meanwhile on another asset sharing the blob is
 * never overwritten. If that push fails, the object stays held (the safe side), and it is logged for the operator.
 */
class EvidenceHoldService
{
    public const MAX_REASON = 1000;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly Gate $gate,
        private readonly Filesystems $storage,
    ) {}

    public function change(Authenticatable $actor, EvidenceAsset $asset, bool $hold, mixed $reason): void
    {
        if (! $this->gate->forUser($actor)->allows('evidence.hold')) {
            throw new AuthorizationException('You are not allowed to change legal holds.');
        }
        if (! EvidenceAsset::seenBy(EvidenceAsset::query(), $actor, $this->gate)->whereKey($asset->id)->exists()) {
            throw (new ModelNotFoundException)->setModel(EvidenceAsset::class, [$asset->ulid]);
        }
        $reason = self::reason($reason);
        $userId = (int) $actor->getAuthIdentifier();

        $released = $this->db->transaction(function () use ($actor, $asset, $hold, $reason, $userId): ?int {
            $current = EvidenceAsset::query()->lockForUpdate()->findOrFail($asset->id);
            // Visibility again, under the lock (TASK-0075 #1): a scope added after the first check narrows who sees it.
            if (! EvidenceAsset::seenBy(EvidenceAsset::query(), $actor, $this->gate)->whereKey($current->id)->exists()) {
                throw (new ModelNotFoundException)->setModel(EvidenceAsset::class, [$current->ulid]);
            }
            if ($current->status !== 'available' || $current->evidence_blob_id === null) {
                throw ValidationException::withMessages(['hold' => 'Only available evidence can be placed on legal hold.']);
            }
            if ($current->legal_hold === $hold) {
                throw ValidationException::withMessages(['hold' => $hold ? 'This evidence is already on legal hold.' : 'This evidence is not on legal hold.']);
            }
            $blob = EvidenceBlob::query()->lockForUpdate()->findOrFail($current->evidence_blob_id);

            $current->forceFill(['legal_hold' => $hold])->save();
            $blobHold = EvidenceAsset::query()->where('evidence_blob_id', $blob->id)->where('legal_hold', true)->exists();
            $blobChanged = $blob->legal_hold !== $blobHold;
            if ($blobChanged) {
                $blob->forceFill(['legal_hold' => $blobHold])->save();
                if ($blobHold) {
                    LockedObjects::legalHold($this->storage->disk($blob->storage_disk), $blob->storage_key, $blob->object_version_id, true);
                }
            }

            $event = new EvidenceCustodyEvent;
            $event->forceFill([
                'evidence_asset_id' => $current->id,
                'event' => $hold ? 'hold_set' : 'hold_released',
                'actor_type' => 'user',
                'actor_id' => $userId,
                'occurred_at' => CarbonImmutable::now('UTC'),
                'details' => ['reason' => $reason],
            ])->save();
            $this->audit->record($hold ? 'evidence.hold_set' : 'evidence.hold_released', $current, ['blob_hold' => $blobHold ? 'on' : 'off'], Actor::user($userId));

            return $blobChanged && ! $blobHold ? $blob->id : null;
        });

        if ($released !== null) {
            try {
                $this->pushBlobHold($released);
            } catch (Throwable $e) {
                Log::warning('Evidence legal hold released in the records, but the stored object is still held.', ['evidence_blob_id' => $released]);
                report($e);
            }
        }
    }

    /** Pushes the blob's current hold to its stored version, under the blob's row lock. */
    public function pushBlobHold(int $blobId): void
    {
        $this->db->transaction(function () use ($blobId): void {
            $blob = EvidenceBlob::query()->lockForUpdate()->findOrFail($blobId);
            LockedObjects::legalHold($this->storage->disk($blob->storage_disk), $blob->storage_key, $blob->object_version_id, $blob->legal_hold);
        });
    }

    /**
     * Required, at most MAX_REASON characters, valid UTF-8, no control characters other than line breaks and tabs (also
     * the rule for a withdrawal's reason, P1-48).
     */
    public static function reason(mixed $value): string
    {
        $reason = is_string($value) ? trim($value) : '';
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Give the reason for this change.']);
        }
        if (! mb_check_encoding($reason, 'UTF-8') || preg_match('/[^\P{Cc}\t\r\n]/u', $reason) === 1) {
            throw ValidationException::withMessages(['reason' => 'The reason contains characters that cannot be stored.']);
        }
        if (mb_strlen($reason) > self::MAX_REASON) {
            throw ValidationException::withMessages(['reason' => 'Use at most '.self::MAX_REASON.' characters.']);
        }

        return $reason;
    }
}
