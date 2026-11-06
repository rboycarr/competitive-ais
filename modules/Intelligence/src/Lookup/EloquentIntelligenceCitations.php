<?php

namespace Modules\Intelligence\Lookup;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Intelligence\Contracts\IntelligenceCitations;
use Modules\Intelligence\Models\ClaimEvidenceLink;
use Modules\Intelligence\Models\IntelligenceItem;

/** IntelligenceCitations over the claim evidence links (P1-45). */
class EloquentIntelligenceCitations implements IntelligenceCitations
{
    public function __construct(private readonly Gate $gate) {}

    public function forEvidence(Authenticatable $viewer, int $assetId): array
    {
        $relations = [];
        foreach (ClaimEvidenceLink::query()->where('evidence_asset_id', $assetId)->with('claim:id,intelligence_item_id')->get() as $link) {
            $relations[(int) $link->claim?->intelligence_item_id][$link->relation] = true;
        }
        if ($relations === []) {
            return ['items' => [], 'hidden' => 0];
        }
        $seen = IntelligenceItem::seenBy(IntelligenceItem::query(), $viewer, $this->gate)
            ->whereIn('id', array_keys($relations))->orderByDesc('updated_at')->orderByDesc('id')->get(['id', 'ulid', 'title', 'status']);

        return [
            'items' => array_values($seen->map(function (IntelligenceItem $i) use ($relations): array {
                $names = array_keys($relations[$i->id]);
                sort($names);

                return ['ulid' => $i->ulid, 'title' => $i->title, 'status' => $i->status, 'relations' => $names];
            })->all()),
            'hidden' => count($relations) - $seen->count(),
        ];
    }
}
