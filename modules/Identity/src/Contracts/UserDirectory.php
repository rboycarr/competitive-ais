<?php

namespace Modules\Identity\Contracts;

use Modules\Platform\Access\Classifiable;
use Modules\Platform\Access\Scoped;

/**
 * Read-only access to users for other modules (ADR-0001: modules use each other's Contracts only), e.g. the Registry
 * assigning an analyst to a competitor (P1-20). Returns summaries, never the User model.
 */
interface UserDirectory
{
    /**
     * Active users who hold the permission (through a role or directly), ordered by name.
     *
     * @return list<UserSummary>
     */
    public function activeHolders(string $permission): array;

    /**
     * The user, if active and holding the permission, read under a shared lock on their row. Call inside the write
     * transaction that relies on it: role and status changes lock the same row, so they cannot slip in between
     * (TASK-0031 #2).
     */
    public function lockedActiveHolder(string $ulid, string $permission): ?UserSummary;

    /**
     * Summaries by user ID, for display; unknown IDs are left out.
     *
     * @param  list<int>  $ids
     * @return array<int, UserSummary>
     */
    public function summaries(array $ids): array;

    /**
     * Whether the user with this ID may see the record, decided by AccessScope (e.g. whether a source's owner can see
     * the source, P1-30). False for an unknown ID. Answers yes or no only: the User model stays in Identity
     * (TASK-0050 #3).
     */
    public function maySee(int $id, Classifiable&Scoped $record): bool;
}
