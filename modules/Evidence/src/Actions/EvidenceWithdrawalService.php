<?php

namespace Modules\Evidence\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Modules\Evidence\Events\EvidenceEvents;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceBlob;
use Modules\Evidence\Models\EvidenceCustodyEvent;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Outbox\OutboxPublisher;

/**
 * Withdrawing evidence (P1-48, docs/03 Evidence; PO decision 2026-11-07): the asset is no longer to be relied on, but
 * is kept, readable for provenance (EvidenceAsset::READABLE), never deleted.
 * - needs evidence.hold (directors), and the asset must be one the actor sees (hidden = "not found");
 * - only available evidence, and not while it is on legal hold (the hold says "keep it as it is"; release it first);
 *   withdrawal is final;
 * - a reason is required (EvidenceHoldService::reason); it is kept in the custody trail, never in the audit context;
 * - the asset lets go of its blob (`evidence_assets_blob_check`: only an available asset has one), so it is never served
 *   again; the blob's SHA-256 is kept in the custody event. Nothing is deleted: the blob and its stored version stay
 *   under their own retention (PO decision O38: the deleting job comes later).
 * Rows are locked asset, then blob (the order of holds and scans). The `evidence.withdrawn` outbox event is written in
 * the same transaction; Intelligence flags the items citing the asset for re-review when it is delivered.
 */
class EvidenceWithdrawalService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly Gate $gate,
        private readonly OutboxPublisher $outbox,
    ) {}

    public function withdraw(Authenticatable $actor, EvidenceAsset $asset, mixed $reason): void
    {
        if (! $this->gate->forUser($actor)->allows('evidence.hold')) {
            throw new AuthorizationException('You are not allowed to withdraw evidence.');
        }
        if (! EvidenceAsset::seenBy(EvidenceAsset::query(), $actor, $this->gate)->whereKey($asset->id)->exists()) {
            throw (new ModelNotFoundException)->setModel(EvidenceAsset::class, [$asset->ulid]);
        }
        $reason = EvidenceHoldService::reason($reason);
        $userId = (int) $actor->getAuthIdentifier();

        $this->db->transaction(function () use ($actor, $asset, $reason, $userId): void {
            $current = EvidenceAsset::query()->lockForUpdate()->findOrFail($asset->id);
            // Visibility again, under the lock (TASK-0075 #1).
            if (! EvidenceAsset::seenBy(EvidenceAsset::query(), $actor, $this->gate)->whereKey($current->id)->exists()) {
                throw (new ModelNotFoundException)->setModel(EvidenceAsset::class, [$current->ulid]);
            }
            if ($current->status !== 'available' || $current->evidence_blob_id === null) {
                throw ValidationException::withMessages(['withdraw' => 'Only available evidence can be withdrawn.']);
            }
            if ($current->legal_hold) {
                throw ValidationException::withMessages(['withdraw' => 'This evidence is on legal hold. Release the hold before withdrawing it.']);
            }
            $blob = EvidenceBlob::query()->lockForUpdate()->findOrFail($current->evidence_blob_id);

            $current->forceFill(['status' => 'withdrawn', 'evidence_blob_id' => null])->save();
            $event = new EvidenceCustodyEvent;
            $event->forceFill([
                'evidence_asset_id' => $current->id,
                'event' => 'withdrawn',
                'actor_type' => 'user',
                'actor_id' => $userId,
                'occurred_at' => CarbonImmutable::now('UTC'),
                'details' => ['reason' => $reason, 'sha256' => $blob->sha256],
            ])->save();
            $this->audit->record('evidence.withdrawn', $current, ['sha256' => $blob->sha256], Actor::user($userId));
            $this->outbox->publish($current, EvidenceEvents::WITHDRAWN, ['evidence_ulid' => $current->ulid]);
            $asset->setRawAttributes($current->getAttributes(), true);
        });
    }
}
