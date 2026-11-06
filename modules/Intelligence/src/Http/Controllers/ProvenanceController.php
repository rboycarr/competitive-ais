<?php

namespace Modules\Intelligence\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Evidence\Contracts\EvidenceProvenance;
use Modules\Evidence\Contracts\EvidenceTrace;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Intelligence\Models\ClaimEvidenceLink;
use Modules\Intelligence\Models\IntelligenceClaim;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Intelligence\Models\VerificationReview;
use Modules\Intelligence\Support\IntelligenceContent;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditEvent;
use Modules\Platform\Audit\AuditLogger;
use Modules\Sources\Contracts\SourceLookup;

/**
 * The provenance view (P1-45, docs/03 Intelligence): an item traced to its origins, for anyone who sees the item:
 * claims → the evidence each cites (as the viewer may see it: hidden evidence is counted, never described, docs/07 §3)
 * → its stored content's SHA-256 → the source and the policy version recorded at capture → its chain of custody; then
 * the item's reviews (each bound to a version and content hash, compared with the content now), publication,
 * retraction and supersession; and, for holders of audit.view, the item's audit trail (rows filtered by AccessScope like
 * the audit log). Showing the audit trail is itself audited, as an audit search is.
 */
class ProvenanceController
{
    /** The audit trail shown at most; the audit log has the rest. */
    public const AUDIT_LIMIT = 200;

    public function __construct(
        private readonly AccessScope $access,
        private readonly Gate $gate,
        private readonly UserDirectory $users,
        private readonly EvidenceProvenance $evidence,
        private readonly SourceLookup $sources,
        private readonly AuditLogger $audit,
        private readonly DatabaseManager $db,
    ) {}

    public function show(Request $request, IntelligenceItem $item): Response
    {
        $viewer = $request->user() ?? abort(401);
        $item->ensureSeenBy($viewer, $this->gate, $this->access);

        $claims = $item->claims()->with('evidenceLinks')->orderBy('position')->orderBy('id')->get();
        $traces = $this->evidence->traces($viewer, array_values(array_unique($claims->flatMap(
            fn (IntelligenceClaim $c) => $c->evidenceLinks->pluck('evidence_asset_id'))->map(fn ($id): int => (int) $id)->all())));
        $reviews = $item->reviews()->orderBy('decided_at')->orderBy('id')->get();
        $retraction = $item->retraction()->first();
        $trail = $this->gate->forUser($viewer)->allows('audit.view')
            ? AuditEvent::query()->visibleTo($viewer)->where('subject_ulid', $item->ulid)->orderBy('id')->limit(self::AUDIT_LIMIT + 1)->get()
            : null;

        $names = $this->users->summaries(array_values(array_unique(array_filter(array_merge(
            [$item->author_id, $item->owner_id, $retraction?->retracted_by],
            $reviews->pluck('reviewer_id')->all(), $reviews->pluck('assignee_id')->all(),
            array_map(fn (EvidenceTrace $t): int => $t->uploadedBy, $traces),
            array_merge(...array_map(fn (EvidenceTrace $t): array => array_column($t->custody, 'actorId'), $traces) ?: [[]]),
            $trail?->pluck('actor_id')->all() ?? [],
        )))));
        $name = fn (?int $id): ?string => $id === null ? null : ($names[$id]->name ?? null);
        $link = function (?int $id) use ($viewer): ?array {
            $linked = $id === null ? null : IntelligenceItem::seenBy(IntelligenceItem::query(), $viewer, $this->gate)->whereKey($id)->first();

            return $linked === null ? null : ['ulid' => $linked->ulid, 'title' => $linked->title, 'status' => $linked->status];
        };
        $currentHash = IntelligenceContent::hash($item);
        $approval = $reviews->where('decision', 'approve')->where('item_version', $item->version)->last();
        $supersededBy = $item->status === 'superseded'
            ? IntelligenceItem::query()->where('supersedes_id', $item->id)->whereIn('status', IntelligenceItem::RELEASED)->value('id')
            : null;

        if ($trail !== null) {
            $this->db->transaction(fn () => $this->audit->record('intelligence.provenance_viewed', $item,
                ['events' => min($trail->count(), self::AUDIT_LIMIT)], Actor::user((int) $viewer->getAuthIdentifier())));
        }

        return Inertia::render('Intelligence/Provenance', [
            'item' => [
                'ulid' => $item->ulid,
                'title' => $item->title,
                'status' => $item->status,
                'classification' => $item->classification->value,
                'version' => $item->version,
                'author' => $name($item->author_id),
                'owner' => $name($item->owner_id),
                'submittedAt' => $item->submitted_at?->toIso8601ZuluString(),
                'verifiedAt' => $item->verified_at?->toIso8601ZuluString(),
                'releasedAt' => $item->released_at?->toIso8601ZuluString(),
            ],
            // The content now, and whether it is what the current version's approval saw.
            'content' => [
                'hash' => $currentHash,
                'approvedHash' => $approval?->content_hash,
                'matchesApproval' => $approval === null ? null : hash_equals($approval->content_hash, $currentHash),
            ],
            'claims' => $claims->map(fn (IntelligenceClaim $c): array => [
                'ulid' => $c->ulid,
                'statement' => $c->statement,
                'type' => $c->claim_type,
                'reliability' => $c->source_reliability,
                'credibility' => (int) $c->info_credibility,
                'status' => $c->status,
                'evidence' => $c->evidenceLinks->sortBy('id')->filter(fn (ClaimEvidenceLink $l): bool => isset($traces[$l->evidence_asset_id]))
                    ->map(fn (ClaimEvidenceLink $l): array => $this->citation($viewer, $l, $traces[$l->evidence_asset_id], $name))->values()->all(),
                // Counted, never described (docs/07 §3).
                'hiddenEvidence' => $c->evidenceLinks->reject(fn (ClaimEvidenceLink $l): bool => isset($traces[$l->evidence_asset_id]))->count(),
            ])->values()->all(),
            'reviews' => $reviews->map(fn (VerificationReview $r): array => [
                'ulid' => $r->ulid,
                'decision' => $r->decision,
                'by' => $name($r->reviewer_id),
                'assignee' => $name($r->assignee_id),
                'rationale' => $r->rationale,
                'version' => $r->item_version,
                'contentHash' => $r->content_hash,
                'decidedAt' => $r->decided_at->toIso8601ZuluString(),
            ])->values()->all(),
            'retraction' => $retraction === null ? null : [
                'reason' => $retraction->reason,
                'by' => $name($retraction->retracted_by),
                'at' => $retraction->retracted_at->toIso8601ZuluString(),
                'replacement' => $link($retraction->replacement_item_id),
            ],
            'supersedes' => $link($item->supersedes_id),
            'supersededBy' => $link($supersededBy === null ? null : (int) $supersededBy),
            'audit' => $trail === null ? null : [
                'events' => $trail->take(self::AUDIT_LIMIT)->map(fn (AuditEvent $e): array => [
                    'ulid' => $e->ulid,
                    'action' => $e->action,
                    'actor' => $e->actor_id !== null ? ($name($e->actor_id) ?? 'Unknown user') : $e->actor_type,
                    'occurredAt' => $e->occurred_at->toIso8601ZuluString('microsecond'),
                    'context' => $e->context ?? (object) [],
                ])->values()->all(),
                'more' => $trail->count() > self::AUDIT_LIMIT,
            ],
        ]);
    }

    /**
     * One citation of evidence the viewer can see, with its content, capture source and custody.
     *
     * @param  callable(?int): ?string  $name
     * @return array<string, mixed>
     */
    private function citation(Authenticatable $viewer, ClaimEvidenceLink $link, EvidenceTrace $trace, callable $name): array
    {
        $source = null;
        if ($trace->sourceId !== null) {
            $recorded = $trace->sourcePolicyId === null ? null : $this->sources->recorded($viewer, $trace->sourceId, $trace->sourcePolicyId);
            // A source the viewer cannot see is a placeholder, never named.
            $source = $recorded === null ? ['visible' => false] : [
                'visible' => true, 'ulid' => $recorded->sourceUlid, 'name' => $recorded->sourceName,
                'policyVersion' => $recorded->policyVersion, 'policyDecidedAt' => $recorded->policyDecidedAt?->toIso8601ZuluString(),
            ];
        }

        return [
            'relation' => $link->relation,
            'locator' => $link->locator,
            'excerpt' => $link->excerpt,
            'asset' => [
                'ulid' => $trace->ulid,
                'title' => $trace->title,
                'status' => $trace->status,
                'classification' => $trace->classification,
                'capturedAt' => $trace->capturedAt->toIso8601ZuluString(),
                'uploadedBy' => $name($trace->uploadedBy),
                'sha256' => $trace->sha256,
                'sizeBytes' => $trace->sizeBytes,
                'mime' => $trace->detectedMime,
                'integrity' => $trace->integrityStatus,
                'source' => $source,
                'custody' => array_map(fn (array $e): array => [
                    'event' => $e['event'], 'actor' => $name($e['actorId']), 'occurredAt' => $e['occurredAt']->toIso8601ZuluString(),
                ], $trace->custody),
            ],
        ];
    }
}
