<?php

namespace Modules\Intelligence\Lookup;

use Modules\Intelligence\Contracts\IntelligenceReferences;
use Modules\Intelligence\Models\IntelligenceItem;

/** IntelligenceReferences over the subject pivots (P1-40). */
class EloquentIntelligenceReferences implements IntelligenceReferences
{
    public function hasOpenItemsAbout(int $competitorId): bool
    {
        return IntelligenceItem::query()->whereIn('status', IntelligenceItem::OPEN)
            ->whereHas('competitorSubjects', fn ($q) => $q->where('competitor_id', $competitorId))->exists();
    }
}
