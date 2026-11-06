<?php

namespace Modules\Evidence\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Evidence for claims (P1-41, docs/03 Evidence): which assets a viewer may cite, and the custody trail of each link.
 * Assets are seen as on the evidence pages (evidence.view, then EvidenceAsset::seenBy); one the viewer cannot see is left out, like one
 * that does not exist. Only `available` evidence (scanned clean, stored) can be cited. Returns summaries, never the
 * model.
 */
interface EvidenceLinking
{
    /**
     * Available assets the viewer can see, newest capture first, whose title contains $search (all when null); at
     * most $limit.
     *
     * @return list<EvidenceSummary>
     */
    public function linkable(Authenticatable $viewer, ?string $search, int $limit): array;

    /**
     * The asset with this ULID if the viewer can see it and it is available, its row locked in share mode so its
     * status cannot change until the caller's transaction ends (withdrawal and rejection lock it for update): call
     * it inside that transaction.
     */
    public function lockLinkable(Authenticatable $viewer, string $ulid): ?EvidenceSummary;

    /**
     * The assets with these IDs that the viewer can see, keyed by ID, in any status the viewer may see.
     *
     * @param  list<int>  $ids
     * @return array<int, EvidenceSummary>
     */
    public function summaries(Authenticatable $viewer, array $ids): array;

    /**
     * Records a `linked` or `unlinked` custody event on the asset (details: the item, claim and relation), inside the
     * caller's transaction, so the trail and the link change commit together.
     */
    public function recordLink(int $assetId, int $userId, bool $linked, string $itemUlid, string $claimUlid, string $relation): void;
}
