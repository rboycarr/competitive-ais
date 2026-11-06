<?php

namespace Modules\Sources\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Sources and their policy in force for other modules (docs/03 Sources), e.g. evidence capture (P1-37). A source is
 * seen by holders of source.view within AccessScope (its classification); a source the viewer cannot see is null,
 * like one that does not exist. Returns summaries, never the model.
 */
interface SourceLookup
{
    /**
     * Sources the viewer can see that may be used now: `approved`, with a policy in force. Ordered by name.
     *
     * @return list<SourcePolicySummary>
     */
    public function usableBy(Authenticatable $viewer): array;

    /**
     * The source with this ULID as the viewer can see it, and its policy in force (`policyId` is null unless the
     * source is approved). With $lock, the source row is locked in share mode, so its status and policy cannot change
     * until the caller's transaction ends (suspension and policy decisions lock it for update): call it inside that
     * transaction.
     */
    public function forCapture(Authenticatable $viewer, string $ulid, bool $lock = false): ?SourcePolicySummary;

    /**
     * A source and one of its policy versions, as recorded on a capture; null when the viewer cannot see the source
     * or the policy is not one of its approvals.
     */
    public function recorded(Authenticatable $viewer, int $sourceId, int $policyId): ?SourcePolicySummary;

    /**
     * The sources with these IDs that the viewer can see, keyed by ID (P1-41); the others are left out.
     *
     * @param  list<int>  $ids
     * @return array<int, SourceSummary>
     */
    public function summaries(Authenticatable $viewer, array $ids): array;
}
