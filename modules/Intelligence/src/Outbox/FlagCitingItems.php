<?php

namespace Modules\Intelligence\Outbox;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use LogicException;
use Modules\Evidence\Events\EvidenceEvents;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Intelligence\Models\RereviewFlag;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Outbox\OutboxHandler;
use Modules\Platform\Outbox\OutboxMessage;

/**
 * Flags for re-review every approved or published item that cites evidence just withdrawn or found corrupted (P1-48,
 * docs/03 Evidence "Evidence state after publication"; PO decisions O13 and 2026-11-07): a `rereview_flags` row per
 * item, `rereview_since` set on the item if it had no open flag, and `intelligence.rereview_flagged` audited (by the
 * system). Nothing changes status: a published item stays readable with its banner, and nothing is retracted.
 *
 * "Cites": any link (supports, contradicts or context) from a claim not withdrawn. The outbox aggregate is the asset
 * (its ID); the relay runs this inside a transaction with its receipt, so it happens once per event. The items' rows
 * are locked in ID order, as the workflow locks them, and their status read again under the lock; the asset is not
 * locked (it is already withdrawn or marked, which is final). An item that reaches approval later is not flagged, and
 * need not be: approving and publishing already refuse evidence that is withdrawn or failed its integrity check.
 */
class FlagCitingItems implements OutboxHandler
{
    private const CAUSES = [
        EvidenceEvents::WITHDRAWN => 'evidence_withdrawn',
        EvidenceEvents::INTEGRITY_FAILED => 'evidence_integrity_failed',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(OutboxMessage $message): void
    {
        $cause = self::CAUSES[$message->eventType] ?? throw new LogicException("FlagCitingItems does not handle [{$message->eventType}].");
        $assetId = $message->aggregateId;
        $evidenceUlid = (string) ($message->payload['evidence_ulid'] ?? '');

        $ids = IntelligenceItem::query()
            ->whereIn('status', IntelligenceItem::FLAGGABLE)
            ->whereHas('claims', fn ($claims) => $claims->where('status', '!=', 'withdrawn')
                ->whereHas('evidenceLinks', fn ($links) => $links->where('evidence_asset_id', $assetId)))
            ->orderBy('id')->pluck('id')->all();
        if ($ids === []) {
            return;
        }

        $now = CarbonImmutable::now('UTC');
        foreach (IntelligenceItem::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get() as $item) {
            if (! in_array($item->status, IntelligenceItem::FLAGGABLE, true)
                || $item->rereviewFlags()->where('evidence_asset_id', $assetId)->where('cause', $cause)->exists()) {
                continue;
            }
            $flag = new RereviewFlag;
            $flag->forceFill([
                'ulid' => (string) Str::ulid(),
                'intelligence_item_id' => $item->id,
                'evidence_asset_id' => $assetId,
                'cause' => $cause,
                'item_version' => $item->version,
                'raised_at' => $now,
            ])->save();
            if ($item->rereview_since === null) {
                $item->forceFill(['rereview_since' => $now])->save();
            }
            $this->audit->record('intelligence.rereview_flagged', $item, [
                'cause' => $cause, 'evidence_ulid' => $evidenceUlid, 'flag_ulid' => $flag->ulid, 'version' => $item->version,
            ], Actor::system());
        }
    }
}
