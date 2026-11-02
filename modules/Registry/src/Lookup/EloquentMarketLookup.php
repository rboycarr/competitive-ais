<?php

namespace Modules\Registry\Lookup;

use Modules\Registry\Contracts\MarketLookup;
use Modules\Registry\Contracts\MarketSummary;
use Modules\Registry\Models\Market;

/** MarketLookup over the markets table (P1-23c). */
class EloquentMarketLookup implements MarketLookup
{
    public function find(string $ulid): ?MarketSummary
    {
        $market = Market::query()->where('ulid', strtoupper($ulid))->first();

        return $market === null ? null : $this->summary($market);
    }

    public function all(): array
    {
        return array_values(Market::query()->orderBy('code')->get(['id', 'ulid', 'code', 'name'])
            ->map(fn (Market $m): MarketSummary => $this->summary($m))->all());
    }

    public function summaries(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Market::query()->whereIn('id', $ids)->get(['id', 'ulid', 'code', 'name'])
            ->mapWithKeys(fn (Market $m): array => [$m->id => $this->summary($m)])->all();
    }

    private function summary(Market $market): MarketSummary
    {
        return new MarketSummary($market->id, $market->ulid, $market->code, $market->name);
    }
}
