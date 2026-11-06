<?php

namespace Modules\Evidence\Lookup;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use LogicException;
use Modules\Evidence\Contracts\EvidenceLinking;
use Modules\Evidence\Contracts\EvidenceSummary;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceAssetCompetitor;
use Modules\Evidence\Models\EvidenceAssetMarket;
use Modules\Evidence\Models\EvidenceCustodyEvent;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;

/** EvidenceLinking over evidence_assets, their scopes and their custody trail (P1-41, P1-36b). */
class EloquentEvidenceLinking implements EvidenceLinking
{
    public function __construct(
        private readonly Gate $gate,
        private readonly DatabaseManager $db,
        private readonly AccessScope $access,
        private readonly AuditLogger $audit,
    ) {}

    public function linkable(Authenticatable $viewer, ?string $search, int $limit): array
    {
        $query = $this->seen($viewer)->where('status', 'available');
        if ($search !== null && trim($search) !== '') {
            $query->where('title', 'like', '%'.addcslashes(trim($search), '\\%_').'%');
        }

        return array_values($query->orderByDesc('captured_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (EvidenceAsset $a): EvidenceSummary => $this->summary($a))->all());
    }

    public function lockLinkable(Authenticatable $viewer, string $ulid): ?EvidenceSummary
    {
        $this->inTransaction('Lock evidence for a link inside the linking transaction.');
        // The row first, then visibility from its current scopes (P1-36b): a plain read inside the caller's transaction
        // would see its earlier snapshot, missing scopes another link committed while this one waited for the lock.
        $asset = EvidenceAsset::query()->where('ulid', strtoupper($ulid))->lockForUpdate()->first();

        return $asset === null || $asset->status !== 'available' || ! $this->seesNow($viewer, $asset) ? null : $this->summary($asset);
    }

    public function lockSeen(Authenticatable $viewer, array $ids): array
    {
        $this->inTransaction('Lock evidence inside the transaction that changes it.');
        if ($ids === []) {
            return [];
        }

        return array_values(EvidenceAsset::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (EvidenceAsset $a): bool => $this->seesNow($viewer, $a))->pluck('id')->all());
    }

    public function available(array $ids, bool $lock = false): array
    {
        if ($lock) {
            $this->inTransaction('Lock evidence inside the transaction that relies on it.');
        }
        if ($ids === []) {
            return [];
        }
        $query = EvidenceAsset::query()->whereIn('id', $ids)->orderBy('id');
        $assets = ($lock ? $query->sharedLock() : $query)->get(['id', 'status']);

        return array_values($assets->where('status', 'available')->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    public function availableSeen(Authenticatable $viewer, array $ids): array
    {
        $this->inTransaction('Lock evidence inside the transaction that relies on it.');
        if ($ids === []) {
            return [];
        }

        return array_values(EvidenceAsset::query()->whereIn('id', $ids)->orderBy('id')->sharedLock()->get()
            ->filter(fn (EvidenceAsset $a): bool => $a->status === 'available' && $this->seesNow($viewer, $a))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    public function inherit(Authenticatable $actor, int $assetId, string $itemUlid, array $competitorIds, array $marketIds): bool
    {
        $this->inTransaction('Inherit scopes inside the transaction that links the evidence.');
        $asset = EvidenceAsset::query()->lockForUpdate()->findOrFail($assetId); // already held by the caller
        $missing = [
            'competitor' => array_values(array_diff(array_unique($competitorIds), $asset->competitorScopes()->sharedLock()->pluck('competitor_id')->all())),
            'market' => array_values(array_diff(array_unique($marketIds), $asset->marketScopes()->sharedLock()->pluck('market_id')->all())),
        ];
        if ($missing['competitor'] === [] && $missing['market'] === []) {
            return false;
        }
        $userId = (int) $actor->getAuthIdentifier();
        $now = CarbonImmutable::now('UTC');
        foreach ($missing['competitor'] as $id) {
            (new EvidenceAssetCompetitor)->forceFill(['evidence_asset_id' => $asset->id, 'competitor_id' => $id, 'origin' => 'inherited', 'added_by' => $userId, 'created_at' => $now])->save();
        }
        foreach ($missing['market'] as $id) {
            (new EvidenceAssetMarket)->forceFill(['evidence_asset_id' => $asset->id, 'market_id' => $id, 'origin' => 'inherited', 'added_by' => $userId, 'created_at' => $now])->save();
        }
        $event = new EvidenceCustodyEvent;
        $event->forceFill([
            'evidence_asset_id' => $asset->id,
            'event' => 'scope_inherited',
            'actor_type' => 'user',
            'actor_id' => $userId,
            'occurred_at' => $now,
            'details' => ['item' => $itemUlid, 'competitor_ids' => $missing['competitor'], 'market_ids' => $missing['market']],
        ])->save();
        $this->audit->record('evidence.scope_inherited', $asset, [
            'item_ulid' => $itemUlid, 'competitors' => count($missing['competitor']), 'markets' => count($missing['market']),
        ], Actor::user($userId));

        return true;
    }

    public function summaries(Authenticatable $viewer, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->seen($viewer)->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (EvidenceAsset $a): array => [$a->id => $this->summary($a)])->all();
    }

    public function recordLink(int $assetId, int $userId, bool $linked, string $itemUlid, string $claimUlid, string $relation): void
    {
        $this->inTransaction('Record a link inside the transaction that changes it.');
        $event = new EvidenceCustodyEvent;
        $event->forceFill([
            'evidence_asset_id' => $assetId,
            'event' => $linked ? 'linked' : 'unlinked',
            'actor_type' => 'user',
            'actor_id' => $userId,
            'occurred_at' => CarbonImmutable::now('UTC'),
            'details' => ['item' => $itemUlid, 'claim' => $claimUlid, 'relation' => $relation],
        ])->save();
    }

    /**
     * As on the evidence pages: evidence.view, then EvidenceAsset::seenBy.
     *
     * @return Builder<EvidenceAsset>
     */
    private function seen(Authenticatable $viewer): Builder
    {
        $query = EvidenceAsset::seenBy(EvidenceAsset::query(), $viewer, $this->gate);

        return $this->gate->forUser($viewer)->allows('evidence.view') ? $query : $query->whereRaw('1 = 0');
    }

    /** The viewer's view of a locked asset, its scopes read with locking reads (current rows). */
    private function seesNow(Authenticatable $viewer, EvidenceAsset $asset): bool
    {
        $gate = $this->gate->forUser($viewer);
        if (! $gate->allows('evidence.view')) {
            return false;
        }
        if (! in_array($asset->status, EvidenceAsset::READABLE, true) && $asset->uploaded_by !== (int) $viewer->getAuthIdentifier() && ! $gate->allows('evidence.hold')) {
            return false;
        }
        $asset->setRelation('competitorScopes', $asset->competitorScopes()->sharedLock()->get());
        $asset->setRelation('marketScopes', $asset->marketScopes()->sharedLock()->get());

        return $this->access->allows($viewer, $asset);
    }

    private function inTransaction(string $message): void
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException($message);
        }
    }

    private function summary(EvidenceAsset $asset): EvidenceSummary
    {
        return new EvidenceSummary(
            $asset->id, $asset->ulid, $asset->title, $asset->status, $asset->classification->value,
            CarbonImmutable::instance($asset->captured_at)->utc(), $asset->source_id,
        );
    }
}
