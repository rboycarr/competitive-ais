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
     * The asset with this ULID if the viewer can see it and it is available, its row locked for update so neither its
     * status nor its scopes change until the caller's transaction ends, and downloads and holds (which re-check
     * visibility under the asset's lock) wait for the scopes it inherits (P1-36b, TASK-0075 #1): call it inside that
     * transaction.
     */
    public function lockLinkable(Authenticatable $viewer, string $ulid): ?EvidenceSummary;

    /**
     * Locks these assets' rows for update, in ID order (so two callers never deadlock), and returns those the viewer
     * sees as their scopes are now (locking reads: the latest committed rows, not the caller's snapshot). Call it inside
     * the caller's transaction, after locking the item that cites them; again after inherit() to check the result.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function lockSeen(Authenticatable $viewer, array $ids): array;

    /**
     * Adds the competitors and markets the asset does not have yet as `inherited` scopes (P1-36b, docs/07 §3: evidence
     * supporting an item is at least as restricted as the item's subjects), with a `scope_inherited` custody event and
     * an `evidence.scope_inherited` audit event when any is added. Scopes are never removed here. The caller holds the
     * asset's row lock (lockLinkable or lockSeen), inside its transaction. Returns whether any scope was added.
     *
     * @param  list<int>  $competitorIds
     * @param  list<int>  $marketIds
     */
    public function inherit(Authenticatable $actor, int $assetId, string $itemUlid, array $competitorIds, array $marketIds): bool;

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
