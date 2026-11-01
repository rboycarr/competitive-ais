<?php

namespace Modules\Registry\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Competitors for other modules (docs/03 Registry), always within what the viewer may see (AccessScope), e.g. the
 * competitor picker for access grants (P1-20a). Returns summaries, never the model.
 */
interface CompetitorLookup
{
    public function findVisible(Authenticatable $viewer, string $ulid): ?CompetitorSummary;

    /**
     * Every competitor the viewer can see, archived ones included, ordered by name.
     *
     * @return list<CompetitorSummary>
     */
    public function visibleTo(Authenticatable $viewer): array;

    /**
     * Summaries by ID for the competitors among them the viewer can see; the others are left out.
     *
     * @param  list<int>  $ids
     * @return array<int, CompetitorSummary>
     */
    public function summaries(Authenticatable $viewer, array $ids): array;
}
