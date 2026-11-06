<?php

namespace Modules\Intelligence\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Evidence\Contracts\EvidenceLinking;
use Modules\Evidence\Contracts\EvidenceSummary;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Contracts\UserSummary;
use Modules\Intelligence\Actions\IntelligenceItemService;
use Modules\Intelligence\Actions\IntelligenceWorkflowService;
use Modules\Intelligence\Http\Requests\SaveItemRequest;
use Modules\Intelligence\Models\ClaimEvidenceLink;
use Modules\Intelligence\Models\IntelligenceClaim;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Intelligence\Models\VerificationReview;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Classification;
use Modules\Platform\Support\SafeMarkdown;
use Modules\Registry\Contracts\CompetitorLookup;
use Modules\Registry\Contracts\CompetitorSummary;
use Modules\Registry\Contracts\MarketLookup;
use Modules\Registry\Contracts\MarketSummary;
use Modules\Registry\Contracts\ProductLookup;
use Modules\Registry\Contracts\ProductSummary;
use Modules\Sources\Contracts\SourceLookup;
use Modules\Sources\Contracts\SourceSummary;

/**
 * Intelligence feed and item page (P1-40). Items are filtered by IntelligenceItem::seenBy (AccessScope and
 * statusVisible); a hidden item is 404. Subjects the viewer cannot see are counted, never named. The summary goes to
 * the page as SafeMarkdown's node tree, never as HTML; its Markdown source only to those who may edit it.
 */
class IntelligenceItemController
{
    private const PAGE = 50;

    private const EVIDENCE_OPTIONS = 25;

    public function __construct(
        private readonly AccessScope $access,
        private readonly Gate $gate,
        private readonly UserDirectory $users,
        private readonly CompetitorLookup $competitors,
        private readonly ProductLookup $products,
        private readonly MarketLookup $markets,
        private readonly EvidenceLinking $evidence,
        private readonly SourceLookup $sources,
        private readonly IntelligenceWorkflowService $workflow,
    ) {}

    public function index(Request $request): Response
    {
        $viewer = $this->viewer($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(IntelligenceItem::STATUSES)],
            'type' => ['nullable', Rule::in(IntelligenceItem::TYPES)],
            'classification' => ['nullable', Rule::enum(Classification::class)],
            'competitor' => ['nullable', 'string', 'ulid'],
        ]);

        $query = IntelligenceItem::seenBy(IntelligenceItem::query(), $viewer, $this->gate)
            ->orderByDesc('updated_at')->orderByDesc('id');
        foreach (['status' => 'status', 'type' => 'item_type', 'classification' => 'classification'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        if (isset($filters['competitor'])) {
            $competitor = $this->competitors->findVisible($viewer, $filters['competitor']);
            $query->whereHas('competitorSubjects', fn ($q) => $q->where('competitor_id', $competitor->id ?? 0));
        }
        $page = $query->with('competitorSubjects')->cursorPaginate(self::PAGE)->withQueryString();
        /** @var list<IntelligenceItem> $items */
        $items = $page->items();
        $names = $this->competitors->summaries($viewer, array_values(array_unique(array_merge(...array_map(
            fn (IntelligenceItem $i): array => $i->competitorIds(), $items) ?: [[]]))));

        return Inertia::render('Intelligence/Index', [
            'items' => array_map(fn (IntelligenceItem $i): array => [
                'ulid' => $i->ulid,
                'title' => $i->title,
                'type' => $i->item_type,
                'status' => $i->status,
                'classification' => $i->classification->value,
                'highImpact' => $i->high_impact,
                'competitors' => array_values(array_filter(array_map(fn (int $id): ?string => $names[$id]->name ?? null, $i->competitorIds()))),
                'updatedAt' => $i->updated_at?->toIso8601ZuluString(),
            ], $items),
            'nextPage' => $page->nextPageUrl(),
            'filters' => array_filter($filters),
            'statuses' => IntelligenceItem::STATUSES,
            'types' => IntelligenceItem::TYPES,
            'filterCompetitors' => array_map(fn (CompetitorSummary $c): array => ['ulid' => $c->ulid, 'name' => $c->name], $this->competitors->visibleTo($viewer)),
        ] + $this->formOptions($viewer));
    }

    public function show(Request $request, IntelligenceItem $item): Response
    {
        $viewer = $this->viewer($request);
        $item->ensureSeenBy($viewer, $this->gate, $this->access);
        $competitorIds = $item->competitorIds();
        $productIds = $item->productIds();
        $marketIds = $item->marketIds();
        $competitors = $this->competitors->summaries($viewer, $competitorIds);
        $products = $this->products->summaries($viewer, $productIds);
        $hidden = count($competitorIds) - count($competitors) + count($productIds) - count($products);
        // As IntelligenceItemService checks: no editing an item about something the viewer cannot see.
        $viewerId = (int) $viewer->getAuthIdentifier();
        $canEdit = $item->isEditable() && $hidden === 0 && in_array($viewerId, [$item->author_id, $item->owner_id], true)
            && $this->gate->forUser($viewer)->allows('intelligence.create');
        // P1-42: the author or owner of an editable item, with intelligence.submit, sees what still blocks submission.
        $canSubmit = $item->isEditable() && in_array($viewerId, [$item->author_id, $item->owner_id], true)
            && $this->gate->forUser($viewer)->allows('intelligence.submit');
        $markets = $this->markets->summaries($marketIds);
        $claims = $item->claims()->with('evidenceLinks')->orderBy('position')->orderBy('id')->get();
        $assets = $this->evidence->summaries($viewer, array_values(array_unique($claims->flatMap(
            fn (IntelligenceClaim $c) => $c->evidenceLinks->pluck('evidence_asset_id'))->all())));
        $sources = $this->sources->summaries($viewer, array_values(array_unique(array_filter(array_map(
            fn (EvidenceSummary $e): ?int => $e->sourceId, $assets)))));
        $search = $request->validate(['evidence_search' => ['nullable', 'string', 'max:100']])['evidence_search'] ?? null;
        $names = $this->users->summaries(array_values(array_unique([$item->author_id, $item->owner_id])));
        $date = fn (string $field): ?string => $item->{$field}?->toIso8601ZuluString();

        return Inertia::render('Intelligence/Show', [
            'item' => [
                'ulid' => $item->ulid,
                'title' => $item->title,
                'summary' => SafeMarkdown::tree($item->summary),
                'type' => $item->item_type,
                'classification' => $item->classification->value,
                'highImpact' => $item->high_impact,
                'status' => $item->status,
                'version' => $item->version,
                'author' => $names[$item->author_id]->name ?? null,
                'owner' => $names[$item->owner_id]->name ?? null,
                'observedAt' => $date('observed_at'),
                'publishedAt' => $date('published_at'),
                'collectedAt' => $date('collected_at'),
                'createdAt' => $date('created_at'),
                'updatedAt' => $date('updated_at'),
            ],
            'subjects' => [
                'competitors' => array_values(array_map(fn (CompetitorSummary $c): array => ['ulid' => $c->ulid, 'name' => $c->name], $competitors)),
                'products' => array_values(array_map(fn (ProductSummary $p): array => ['ulid' => $p->ulid, 'name' => $p->name], $products)),
                'markets' => array_values(array_map(fn (MarketSummary $m): array => ['ulid' => $m->ulid, 'code' => $m->code, 'name' => $m->name], $markets)),
                // Counted, never named (docs/07 §3).
                'hidden' => $hidden,
            ],
            'claims' => $claims->map(fn (IntelligenceClaim $c): array => [
                'ulid' => $c->ulid,
                'statement' => $c->statement,
                'type' => $c->claim_type,
                'reliability' => $c->source_reliability,
                'credibility' => $c->info_credibility,
                'status' => $c->status,
            ] + $this->citations($c, $assets, $sources))->values()->all(),
            'canEdit' => $canEdit,
            'submission' => $canSubmit ? ['problems' => $this->workflow->problems($item)] : null,
            'review' => $this->review($viewer, $item),
            'publication' => $this->publication($viewer, $item),
            'relations' => ClaimEvidenceLink::RELATIONS,
            // P1-41: what the editor may cite; the search is a partial reload of this prop.
            'evidenceOptions' => $canEdit ? array_map(fn (EvidenceSummary $e): array => [
                'ulid' => $e->ulid, 'title' => $e->title, 'capturedAt' => $e->capturedAt->toIso8601ZuluString(),
            ], $this->evidence->linkable($viewer, $search, self::EVIDENCE_OPTIONS)) : [],
            'evidenceSearch' => $search,
            // The editor's starting values: only for those who may edit.
            'form' => $canEdit ? [
                'title' => $item->title,
                'summary' => $item->summary,
                'item_type' => $item->item_type,
                'classification' => $item->classification->value,
                'high_impact' => $item->high_impact,
                'observed_at' => $date('observed_at'),
                'published_at' => $date('published_at'),
                'collected_at' => $date('collected_at'),
                // A product's competitor is added by the service, so only competitors chosen for themselves are listed.
                'competitors' => array_values(array_map(fn (CompetitorSummary $c): string => $c->ulid, array_diff_key($competitors, array_flip(array_filter(array_map(fn (ProductSummary $p): ?int => $p->competitorId, $products)))))),
                'products' => array_values(array_map(fn (ProductSummary $p): string => $p->ulid, $products)),
                'markets' => array_values(array_map(fn (MarketSummary $m): string => $m->ulid, $markets)),
            ] : null,
            'claimTypes' => IntelligenceClaim::TYPES,
            'reliabilities' => IntelligenceClaim::RELIABILITIES,
            'credibilities' => IntelligenceClaim::CREDIBILITIES,
        ] + ($canEdit ? $this->formOptions($viewer) : ['canCreate' => false]));
    }

    public function store(SaveItemRequest $request, IntelligenceItemService $items): RedirectResponse
    {
        $item = $items->create($this->viewer($request), $request->validated());

        return redirect()->route('intelligence.show', $item)->with('status', 'Draft saved. Add its claims next.');
    }

    public function update(SaveItemRequest $request, IntelligenceItem $item, IntelligenceItemService $items): RedirectResponse
    {
        $items->update($this->viewer($request), $item, $request->validated());

        return redirect()->route('intelligence.show', $item)->with('status', 'Item saved.');
    }

    /** P1-42: draft or changes requested to submitted, when every claim is evidence-backed. */
    public function submit(Request $request, IntelligenceItem $item): RedirectResponse
    {
        // Validated by the service after the visibility check, so a hidden item is 404 whatever the input (TASK-0087 #1).
        $this->workflow->submit($this->viewer($request), $item, $request->only('version'));

        return redirect()->route('intelligence.show', $item)->with('status', 'Submitted for review. The item is frozen until a reviewer decides.');
    }

    // P1-44. As for submit, the input is validated by the service after the visibility check (TASK-0087 #1).
    public function publish(Request $request, IntelligenceItem $item): RedirectResponse
    {
        $this->workflow->publish($this->viewer($request), $item, $request->only('version'));

        return redirect()->route('intelligence.show', $item)->with('status', 'Published.');
    }

    public function retract(Request $request, IntelligenceItem $item): RedirectResponse
    {
        $this->workflow->retract($this->viewer($request), $item, $request->only(['reason', 'replacement']));

        return redirect()->route('intelligence.show', $item)->with('status', 'Retracted. It stays readable, marked as retracted.');
    }

    public function replacement(Request $request, IntelligenceItem $item, IntelligenceItemService $items): RedirectResponse
    {
        $draft = $items->draftReplacement($this->viewer($request), $item);

        return redirect()->route('intelligence.show', $draft)->with('status', 'Replacement drafted. Add its claims; once published, it supersedes the original.');
    }

    /**
     * A claim's citations (P1-41) as the viewer may see them: the asset's title, status and source (with its default
     * reliability, ADR-0010) only when the viewer can see the asset (and the source); the others are counted, never
     * described, locator and excerpt included.
     *
     * @param  array<int, EvidenceSummary>  $assets
     * @param  array<int, SourceSummary>  $sources
     * @return array{evidence: list<array<string, mixed>>, hiddenEvidence: int}
     */
    private function citations(IntelligenceClaim $claim, array $assets, array $sources): array
    {
        $shown = [];
        $hidden = 0;
        foreach ($claim->evidenceLinks->sortBy('id') as $link) {
            $asset = $assets[$link->evidence_asset_id] ?? null;
            if ($asset === null) {
                $hidden++;

                continue;
            }
            $source = $asset->sourceId === null ? null : ($sources[$asset->sourceId] ?? false);
            $shown[] = [
                'ulid' => $asset->ulid,
                'title' => $asset->title,
                'status' => $asset->status,
                'capturedAt' => $asset->capturedAt->toIso8601ZuluString(),
                'relation' => $link->relation,
                'locator' => $link->locator,
                'excerpt' => $link->excerpt,
                // null: no registered source (O12); visible false: one the viewer cannot see, never named.
                'source' => match (true) {
                    $source === null => null,
                    $source === false => ['visible' => false],
                    default => ['visible' => true, 'name' => $source->name, 'reliability' => $source->defaultReliability],
                },
            ];
        }

        return ['evidence' => $shown, 'hiddenEvidence' => $hidden];
    }

    /**
     * The item's verification (P1-43), for anyone who sees the item: its reviewer, the current submission's time and
     * the review history (rationales included: they are part of the item's record). What the viewer may do, as
     * IntelligenceWorkflowService checks it: a director assigns (to reviewers who may review it); the assigned reviewer
     * starts the review, then decides, seeing what still blocks an approval.
     *
     * @return array<string, mixed>
     */
    private function review(Authenticatable $viewer, IntelligenceItem $item): array
    {
        $viewerId = (int) $viewer->getAuthIdentifier();
        $gate = $this->gate->forUser($viewer);
        $inQueue = in_array($item->status, IntelligenceItem::IN_QUEUE, true);
        $assigned = $item->assigned_reviewer_id === $viewerId && ! in_array($viewerId, [$item->author_id, $item->owner_id], true)
            && $gate->allows('intelligence.review');
        $canAssign = $inQueue && $gate->allows('intelligence.publish');
        $canDecide = $assigned && $item->status === 'in_review';
        $reviews = $item->reviews()->orderBy('decided_at')->orderBy('id')->get();
        $names = $this->users->summaries(array_values(array_unique(array_filter(array_merge(
            [$item->assigned_reviewer_id], $reviews->pluck('reviewer_id')->all(), $reviews->pluck('assignee_id')->all())))));
        $name = fn (?int $id): ?string => $id === null ? null : ($names[$id]->name ?? null);

        return [
            'reviewer' => $name($item->assigned_reviewer_id),
            'submittedAt' => $item->submitted_at?->toIso8601ZuluString(),
            'verifiedAt' => $item->verified_at?->toIso8601ZuluString(),
            'history' => $reviews->map(fn (VerificationReview $r): array => [
                'ulid' => $r->ulid,
                'decision' => $r->decision,
                'by' => $name($r->reviewer_id),
                'assignee' => $name($r->assignee_id),
                'rationale' => $r->rationale,
                'version' => $r->item_version,
                'decidedAt' => $r->decided_at->toIso8601ZuluString(),
            ])->values()->all(),
            'canAssign' => $canAssign,
            // Who may be assigned: active reviewers, not the author or owner, who can see the item.
            'reviewers' => $canAssign ? array_values(array_map(fn (UserSummary $u): array => ['ulid' => $u->ulid, 'name' => $u->name],
                array_filter($this->users->activeHolders('intelligence.review'), fn (UserSummary $u): bool => ! in_array($u->id, [$item->author_id, $item->owner_id], true)
                    && $u->id !== $item->assigned_reviewer_id && $this->users->maySee($u->id, $item)))) : [],
            'canStart' => $assigned && $item->status === 'submitted',
            'canDecide' => $canDecide,
            'decisions' => VerificationReview::DECISIONS,
            'approvalProblems' => $canDecide ? $this->workflow->problems($item, reviewer: $viewer) : [],
        ];
    }

    /**
     * The item's publication (P1-44): when it was released, the item it replaces and the one that replaced it, its
     * retraction; linked items are named only when the viewer can see them. What the viewer may do, as
     * IntelligenceWorkflowService checks it: publish an approved item they did not write, own or approve (with what still
     * blocks it), retract a published one (naming a published replacement), or draft a replacement.
     *
     * @return array<string, mixed>
     */
    private function publication(Authenticatable $viewer, IntelligenceItem $item): array
    {
        $viewerId = (int) $viewer->getAuthIdentifier();
        $gate = $this->gate->forUser($viewer);
        $link = function (?int $id) use ($viewer): ?array {
            $linked = $id === null ? null : IntelligenceItem::seenBy(IntelligenceItem::query(), $viewer, $this->gate)->whereKey($id)->first();

            return $linked === null ? null : ['ulid' => $linked->ulid, 'title' => $linked->title, 'status' => $linked->status];
        };
        $retraction = $item->retraction()->first();
        $supersededBy = $item->status === 'superseded'
            ? IntelligenceItem::query()->where('supersedes_id', $item->id)->whereIn('status', IntelligenceItem::RELEASED)->value('id')
            : null;
        $canPublish = $item->status === 'approved' && $gate->allows('intelligence.publish') && ! in_array($viewerId, [$item->author_id, $item->owner_id], true);
        $canRetract = $item->status === 'published' && $gate->allows('intelligence.retract');
        $names = $retraction === null ? [] : $this->users->summaries([$retraction->retracted_by]);

        return [
            'releasedAt' => $item->released_at?->toIso8601ZuluString(),
            'supersedes' => $link($item->supersedes_id),
            'supersededBy' => $link($supersededBy === null ? null : (int) $supersededBy),
            'retraction' => $retraction === null ? null : [
                'reason' => $retraction->reason,
                'by' => $names[$retraction->retracted_by]->name ?? null,
                'at' => $retraction->retracted_at->toIso8601ZuluString(),
                'replacement' => $link($retraction->replacement_item_id),
            ],
            'canPublish' => $canPublish,
            'publishProblems' => $canPublish ? $this->workflow->publicationProblems($item, $viewer) : [],
            'canRetract' => $canRetract,
            'replacementOptions' => $canRetract ? IntelligenceItem::seenBy(IntelligenceItem::query(), $viewer, $this->gate)
                ->where('status', 'published')->whereKeyNot($item->id)->orderByDesc('released_at')->limit(self::PAGE)->get(['id', 'ulid', 'title'])
                ->map(fn (IntelligenceItem $i): array => ['ulid' => $i->ulid, 'title' => $i->title])->values()->all() : [],
            'canReplace' => $item->status === 'published' && $gate->allows('intelligence.create'),
        ];
    }

    /** @return array<string, mixed> */
    private function formOptions(Authenticatable $viewer): array
    {
        $canCreate = $this->gate->forUser($viewer)->allows('intelligence.create');

        return [
            'canCreate' => $canCreate,
            'types' => IntelligenceItem::TYPES,
            // Every level is offered; the service refuses one the writer could not read back with the chosen subjects.
            'classifications' => $canCreate ? array_map(fn (Classification $c): string => $c->value, Classification::cases()) : [],
            'competitorOptions' => $canCreate ? array_map(fn (CompetitorSummary $c): array => ['ulid' => $c->ulid, 'name' => $c->name], $this->competitors->visibleTo($viewer)) : [],
            'productOptions' => $canCreate ? array_map(fn (ProductSummary $p): array => ['ulid' => $p->ulid, 'name' => $p->name], $this->products->visibleTo($viewer)) : [],
            'marketOptions' => $canCreate ? array_map(fn (MarketSummary $m): array => ['ulid' => $m->ulid, 'name' => "$m->code · $m->name"], $this->markets->all()) : [],
        ];
    }

    private function viewer(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
