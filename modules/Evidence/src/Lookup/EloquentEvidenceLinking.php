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
use Modules\Evidence\Models\EvidenceCustodyEvent;

/** EvidenceLinking over evidence_assets and their custody trail (P1-41). */
class EloquentEvidenceLinking implements EvidenceLinking
{
    public function __construct(private readonly Gate $gate, private readonly DatabaseManager $db) {}

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
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('Lock evidence for a link inside the linking transaction.');
        }
        $asset = $this->seen($viewer)
            ->where('ulid', strtoupper($ulid))->where('status', 'available')->sharedLock()->first();

        return $asset === null ? null : $this->summary($asset);
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
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('Record a link inside the transaction that changes it.');
        }
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

    private function summary(EvidenceAsset $asset): EvidenceSummary
    {
        return new EvidenceSummary(
            $asset->id, $asset->ulid, $asset->title, $asset->status, $asset->classification->value,
            CarbonImmutable::instance($asset->captured_at)->utc(), $asset->source_id,
        );
    }
}
