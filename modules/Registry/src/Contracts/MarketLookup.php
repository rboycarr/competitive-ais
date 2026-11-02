<?php

namespace Modules\Registry\Contracts;

/**
 * Markets for other modules (docs/03 Registry), e.g. the market picker for access grants (P1-23c). Markets are
 * unclassified reference data, so there is no viewer filter. Returns summaries, never the model.
 */
interface MarketLookup
{
    public function find(string $ulid): ?MarketSummary;

    /**
     * Every market, ordered by code.
     *
     * @return list<MarketSummary>
     */
    public function all(): array;

    /**
     * Summaries by ID for the given markets that exist.
     *
     * @param  list<int>  $ids
     * @return array<int, MarketSummary>
     */
    public function summaries(array $ids): array;
}
