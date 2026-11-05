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

    /**
     * Inside the caller's transaction: share-locks these competitors' rows until it ends and returns the IDs among them
     * that are not archived. A record that starts referring to a competitor calls this first, and archiving locks the
     * row for update before it checks for references (P1-40), so neither can miss the other. No visibility filter:
     * the caller has already checked that.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function lockUnarchived(array $ids): array;
}
