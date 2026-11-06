<?php

namespace Modules\Intelligence\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Evidence\Contracts\EvidenceLinking;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Intelligence\Models\ClaimEvidenceLink;
use Modules\Intelligence\Models\IntelligenceClaim;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Intelligence\Models\VerificationReview;
use Modules\Intelligence\Support\IntelligenceContent;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;

/**
 * The intelligence workflow (docs/03 Intelligence, state machine), one method per transition. Every transition: an item
 * the actor cannot see is 404 before anything else (TASK-0087 #1), then the permission; then, under the item's row
 * lock, the item is seen again and the transition's guard checked. Assignments and decisions are append-only
 * `verification_reviews` rows bound to the version and content hash (IntelligenceContent), audited without user text.
 * - submit (P1-42): draft or changes_requested to submitted; intelligence.submit; the author or owner; from the version
 *   read; at least one claim, and every claim not withdrawn has at least one `supports` link to an `available` asset
 *   (whoever can see it), the cited assets share-locked after the item (so none is withdrawn or rejected meanwhile).
 *   Content does not change, so the version stays; it is frozen until changes are requested. The queue's age starts.
 * - assign (P1-43): a director (intelligence.publish) names the reviewer of a submitted or in-review item: an active
 *   intelligence.review holder (read under a share lock on their row), not its author or owner, who can see it. The
 *   first is an `assign` row; any later one a `reassign` row with a reason, and an item under review goes back to
 *   submitted for the new reviewer.
 * - startReview (P1-43): submitted to in_review, by the assigned reviewer only.
 * - decide (P1-43): in_review to approved, changes_requested or rejected, by the assigned reviewer only (never the
 *   author or owner), from the version read; a reason for requesting changes or rejecting. Approval also needs every
 *   claim supported by available evidence the reviewer can see, share-locked after the item; it sets verified_at.
 */
class IntelligenceWorkflowService
{
    public const MAX_RATIONALE = 5000;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly AccessScope $access,
        private readonly Gate $gate,
        private readonly EvidenceLinking $evidence,
        private readonly UserDirectory $users,
    ) {}

    /** @param  array<string, mixed>  $data  version */
    public function submit(Authenticatable $actor, IntelligenceItem $item, array $data): void
    {
        // Visibility first: a hidden item is 404 whatever the actor's permissions (TASK-0087 #1).
        $item->ensureSeenBy($actor, $this->gate, $this->access);
        if (! $this->gate->forUser($actor)->allows('intelligence.submit')) {
            throw new AuthorizationException('You are not allowed to submit intelligence for review.');
        }

        $this->db->transaction(function () use ($actor, $item, $data): void {
            $locked = IntelligenceItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $locked->ensureSeenBy($actor, $this->gate, $this->access);
            IntelligenceItemService::assertMayEdit($actor, $locked, $data['version'] ?? null);
            $problems = $this->problems($locked, lock: true);
            if ($problems !== []) {
                throw ValidationException::withMessages(['claims' => $problems]);
            }
            $claims = $locked->claims()->where('status', '!=', 'withdrawn')->count();
            $locked->forceFill(['status' => 'submitted', 'submitted_at' => CarbonImmutable::now('UTC')])->save();
            $this->audit->record('intelligence.submitted', $locked, ['version' => $locked->version, 'claims' => $claims], Actor::user((int) $actor->getAuthIdentifier()));
            $item->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** @param  array<string, mixed>  $data  reviewer (user ULID), rationale (required to reassign) */
    public function assign(Authenticatable $actor, IntelligenceItem $item, array $data): void
    {
        $item->ensureSeenBy($actor, $this->gate, $this->access);
        if (! $this->gate->forUser($actor)->allows('intelligence.publish')) {
            throw new AuthorizationException('Only a director assigns reviewers.');
        }
        $rationale = self::rationale($data['rationale'] ?? null);

        $this->db->transaction(function () use ($actor, $item, $data, $rationale): void {
            $locked = IntelligenceItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $locked->ensureSeenBy($actor, $this->gate, $this->access);
            if (! in_array($locked->status, IntelligenceItem::IN_QUEUE, true)) {
                throw ValidationException::withMessages(['item' => 'Only an item waiting for or under review can be assigned.']);
            }
            $ulid = is_string($data['reviewer'] ?? null) ? trim($data['reviewer']) : '';
            $reviewer = ($ulid === '' ? null : $this->users->lockedActiveHolder($ulid, 'intelligence.review'))
                ?? throw ValidationException::withMessages(['reviewer' => 'The reviewer must be an active user who reviews intelligence.']);
            if (in_array($reviewer->id, [$locked->author_id, $locked->owner_id], true)) {
                throw ValidationException::withMessages(['reviewer' => 'The author or owner of an item cannot review it.']);
            }
            if (! $this->users->maySee($reviewer->id, $locked)) {
                throw ValidationException::withMessages(['reviewer' => 'The reviewer must be able to see this item.']);
            }
            if ($reviewer->id === $locked->assigned_reviewer_id) {
                throw ValidationException::withMessages(['reviewer' => 'This reviewer is already assigned.']);
            }
            $decision = $locked->assigned_reviewer_id === null ? 'assign' : 'reassign';
            if ($decision === 'reassign' && $rationale === null) {
                throw ValidationException::withMessages(['rationale' => 'Give the reason for reassigning the review.']);
            }

            $locked->forceFill(['assigned_reviewer_id' => $reviewer->id, 'status' => 'submitted'])->save();
            $review = $this->record($actor, $locked, $decision, $rationale, $reviewer->id);
            $this->audit->record('intelligence.reviewer_assigned', $locked, [
                'decision' => $decision, 'reviewer_ulid' => $reviewer->ulid, 'review_ulid' => $review->ulid, 'version' => $locked->version,
            ], Actor::user((int) $actor->getAuthIdentifier()));
            $item->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function startReview(Authenticatable $actor, IntelligenceItem $item): void
    {
        $item->ensureSeenBy($actor, $this->gate, $this->access);
        $this->ensureReviewer($actor);

        $this->db->transaction(function () use ($actor, $item): void {
            $locked = IntelligenceItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $locked->ensureSeenBy($actor, $this->gate, $this->access);
            self::assertAssigned($actor, $locked);
            if ($locked->status !== 'submitted') {
                throw ValidationException::withMessages(['item' => 'Only a submitted item can be taken into review.']);
            }
            $locked->forceFill(['status' => 'in_review'])->save();
            $this->audit->record('intelligence.review_started', $locked, ['version' => $locked->version], Actor::user((int) $actor->getAuthIdentifier()));
            $item->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** @param  array<string, mixed>  $data  decision (approve, request_changes, reject), rationale, version */
    public function decide(Authenticatable $actor, IntelligenceItem $item, array $data): void
    {
        $item->ensureSeenBy($actor, $this->gate, $this->access);
        $this->ensureReviewer($actor);
        $decision = $data['decision'] ?? null;
        if (! is_string($decision) || ! in_array($decision, VerificationReview::DECISIONS, true)) {
            throw ValidationException::withMessages(['decision' => 'Choose approve, request changes or reject.']);
        }
        $rationale = self::rationale($data['rationale'] ?? null);
        if ($rationale === null && in_array($decision, VerificationReview::NEEDS_RATIONALE, true)) {
            throw ValidationException::withMessages(['rationale' => 'Give the reason for this decision.']);
        }

        $this->db->transaction(function () use ($actor, $item, $data, $decision, $rationale): void {
            $locked = IntelligenceItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $locked->ensureSeenBy($actor, $this->gate, $this->access);
            self::assertAssigned($actor, $locked);
            if ($locked->status !== 'in_review') {
                throw ValidationException::withMessages(['item' => 'This item is not under review.']);
            }
            $version = $data['version'] ?? null;
            if (! is_numeric($version) || (int) $version !== $locked->version) {
                throw ValidationException::withMessages(['version' => 'This item changed since you opened it. Reload it and review it again.']);
            }
            if ($decision === 'approve') {
                $problems = $this->problems($locked, lock: true, reviewer: $actor);
                if ($problems !== []) {
                    throw ValidationException::withMessages(['claims' => $problems]);
                }
            }

            $status = ['approve' => 'approved', 'request_changes' => 'changes_requested', 'reject' => 'rejected'][$decision];
            $locked->forceFill(['status' => $status] + ($decision === 'approve' ? ['verified_at' => CarbonImmutable::now('UTC')] : []))->save();
            $review = $this->record($actor, $locked, $decision, $rationale);
            $this->audit->record('intelligence.reviewed', $locked, [
                'decision' => $decision, 'review_ulid' => $review->ulid, 'version' => $locked->version,
            ], Actor::user((int) $actor->getAuthIdentifier()));
            $item->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * What keeps the item from being submitted, in words (empty: nothing). With $reviewer, what keeps that reviewer
     * from approving it: the supporting evidence must also be evidence they can see. With $lock, the cited assets are
     * share-locked: call it inside the transaction, holding the item's lock.
     *
     * @return list<string>
     */
    public function problems(IntelligenceItem $item, bool $lock = false, ?Authenticatable $reviewer = null): array
    {
        $claims = $item->claims()->where('status', '!=', 'withdrawn')->orderBy('position')->orderBy('id')
            ->with(['evidenceLinks' => fn ($q) => $q->where('relation', 'supports')])->get();
        if ($claims->isEmpty()) {
            return ['Add at least one claim.'];
        }
        $cited = array_values(array_unique($claims->flatMap(fn (IntelligenceClaim $c) => $c->evidenceLinks->pluck('evidence_asset_id'))->map(fn ($id): int => (int) $id)->all()));
        sort($cited);
        $usable = match (true) {
            $reviewer === null => $this->evidence->available($cited, $lock),
            $lock => $this->evidence->availableSeen($reviewer, $cited),
            default => array_values(array_intersect($this->evidence->available($cited), array_keys($this->evidence->summaries($reviewer, $cited)))),
        };

        $problems = [];
        foreach ($claims->values() as $index => $claim) {
            $supported = $claim->evidenceLinks->contains(fn (ClaimEvidenceLink $l): bool => in_array($l->evidence_asset_id, $usable, true));
            if (! $supported) {
                $problems[] = 'Claim '.($index + 1).($reviewer === null
                    ? ' cites no available evidence that supports it.'
                    : ' cites no available evidence that you can see and that supports it.');
            }
        }

        return $problems;
    }

    private function ensureReviewer(Authenticatable $actor): void
    {
        if (! $this->gate->forUser($actor)->allows('intelligence.review')) {
            throw new AuthorizationException('You are not allowed to review intelligence.');
        }
    }

    /** Only the assigned reviewer decides, and never on an item they wrote or own (docs/07 §3, segregation of duties). */
    private static function assertAssigned(Authenticatable $actor, IntelligenceItem $locked): void
    {
        $actorId = (int) $actor->getAuthIdentifier();
        if ($locked->assigned_reviewer_id !== $actorId || in_array($actorId, [$locked->author_id, $locked->owner_id], true)) {
            throw new AuthorizationException('Only the assigned reviewer may review this item.');
        }
    }

    /** Trimmed plain text, null when empty; at most MAX_RATIONALE characters. */
    private static function rationale(mixed $value): ?string
    {
        if ($value !== null && ! is_string($value)) {
            throw ValidationException::withMessages(['rationale' => 'The reason must be text.']);
        }
        $value = trim((string) $value);
        if (mb_strlen($value) > self::MAX_RATIONALE) {
            throw ValidationException::withMessages(['rationale' => 'The reason may be at most '.self::MAX_RATIONALE.' characters.']);
        }

        return $value === '' ? null : $value;
    }

    /** An append-only review row, inside the transaction holding the item's lock. */
    private function record(Authenticatable $actor, IntelligenceItem $locked, string $decision, ?string $rationale, ?int $assignee = null): VerificationReview
    {
        $review = new VerificationReview;
        $review->forceFill([
            'ulid' => (string) Str::ulid(),
            'intelligence_item_id' => $locked->id,
            'item_version' => $locked->version,
            'reviewer_id' => (int) $actor->getAuthIdentifier(),
            'assignee_id' => $assignee,
            'decision' => $decision,
            'rationale' => $rationale,
            'content_hash' => IntelligenceContent::hash($locked),
            'decided_at' => CarbonImmutable::now('UTC'),
        ])->save();

        return $review;
    }
}
