<?php

namespace Modules\Registry\Lookup;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Registry\Contracts\CompetitorLookup;
use Modules\Registry\Contracts\CompetitorSummary;
use Modules\Registry\Models\Competitor;

/** CompetitorLookup over the competitors table, filtered by visibleTo (P1-20a). */
class EloquentCompetitorLookup implements CompetitorLookup
{
    public function findVisible(Authenticatable $viewer, string $ulid): ?CompetitorSummary
    {
        $competitor = Competitor::query()->visibleTo($viewer)->where('ulid', strtoupper($ulid))->first();

        return $competitor === null ? null : $this->summary($competitor);
    }

    public function visibleTo(Authenticatable $viewer): array
    {
        return array_values(Competitor::query()->visibleTo($viewer)->orderBy('name')->get(['id', 'ulid', 'name', 'classification'])
            ->map(fn (Competitor $c): CompetitorSummary => $this->summary($c))->all());
    }

    public function summaries(Authenticatable $viewer, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Competitor::query()->visibleTo($viewer)->whereIn('id', $ids)->get(['id', 'ulid', 'name', 'classification'])
            ->mapWithKeys(fn (Competitor $c): array => [$c->id => $this->summary($c)])->all();
    }

    private function summary(Competitor $competitor): CompetitorSummary
    {
        return new CompetitorSummary($competitor->id, $competitor->ulid, $competitor->name);
    }
}
