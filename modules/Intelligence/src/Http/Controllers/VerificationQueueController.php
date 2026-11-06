<?php

namespace Modules\Intelligence\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Intelligence\Actions\IntelligenceWorkflowService;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Platform\Classification;
use Modules\Registry\Contracts\CompetitorLookup;
use Modules\Registry\Contracts\CompetitorSummary;

/**
 * The verification queue (P1-43): submitted and in-review items the viewer can see (IntelligenceItem::seenBy), oldest
 * submission first, for reviewers (intelligence.review) and directors (intelligence.publish); filtered by assignment,
 * status, classification, competitor and impact. Assigning, starting a review and deciding go through
 * IntelligenceWorkflowService, which checks everything again under the item's row lock.
 */
class VerificationQueueController
{
    private const PAGE = 50;

    public function __construct(
        private readonly Gate $gate,
        private readonly UserDirectory $users,
        private readonly CompetitorLookup $competitors,
        private readonly IntelligenceWorkflowService $workflow,
    ) {}

    public function index(Request $request): Response
    {
        $viewer = $this->viewer($request);
        $gate = $this->gate->forUser($viewer);
        if (! $gate->allows('intelligence.review') && ! $gate->allows('intelligence.publish')) {
            throw new AuthorizationException('The verification queue is for reviewers and directors.');
        }
        $filters = $request->validate([
            'assigned' => ['nullable', Rule::in(['mine', 'unassigned'])],
            'status' => ['nullable', Rule::in(IntelligenceItem::IN_QUEUE)],
            'classification' => ['nullable', Rule::enum(Classification::class)],
            'competitor' => ['nullable', 'string', 'ulid'],
            'high_impact' => ['nullable', 'boolean'],
        ]);

        $viewerId = (int) $viewer->getAuthIdentifier();
        $query = IntelligenceItem::seenBy(IntelligenceItem::query(), $viewer, $this->gate)
            ->whereIn('status', IntelligenceItem::IN_QUEUE)
            ->orderBy('submitted_at')->orderBy('id');
        match ($filters['assigned'] ?? null) {
            'mine' => $query->where('assigned_reviewer_id', $viewerId),
            'unassigned' => $query->whereNull('assigned_reviewer_id'),
            default => null,
        };
        foreach (['status' => 'status', 'classification' => 'classification'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        if (isset($filters['high_impact'])) {
            $query->where('high_impact', (bool) $filters['high_impact']);
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
        $reviewers = $this->users->summaries(array_values(array_unique(array_filter(array_map(
            fn (IntelligenceItem $i): ?int => $i->assigned_reviewer_id, $items)))));

        return Inertia::render('Intelligence/Queue', [
            'items' => array_map(fn (IntelligenceItem $i): array => [
                'ulid' => $i->ulid,
                'title' => $i->title,
                'status' => $i->status,
                'classification' => $i->classification->value,
                'highImpact' => $i->high_impact,
                'competitors' => array_values(array_filter(array_map(fn (int $id): ?string => $names[$id]->name ?? null, $i->competitorIds()))),
                'reviewer' => $i->assigned_reviewer_id === null ? null : ($reviewers[$i->assigned_reviewer_id]->name ?? null),
                'mine' => $i->assigned_reviewer_id === $viewerId,
                'submittedAt' => $i->submitted_at?->toIso8601ZuluString(),
            ], $items),
            'nextPage' => $page->nextPageUrl(),
            'filters' => array_filter($filters, fn ($v): bool => $v !== null && $v !== ''),
            'statuses' => IntelligenceItem::IN_QUEUE,
            'filterCompetitors' => array_map(fn (CompetitorSummary $c): array => ['ulid' => $c->ulid, 'name' => $c->name], $this->competitors->visibleTo($viewer)),
        ]);
    }

    // The input is validated by the service after the visibility check: validating it here would answer a hidden item
    // with 422 and a missing one with 404 (TASK-0087 #1).
    public function assign(Request $request, IntelligenceItem $item): RedirectResponse
    {
        $this->workflow->assign($this->viewer($request), $item, $request->only(['reviewer', 'rationale']));

        return redirect()->route('intelligence.show', $item)->with('status', 'Reviewer assigned.');
    }

    public function start(Request $request, IntelligenceItem $item): RedirectResponse
    {
        $this->workflow->startReview($this->viewer($request), $item);

        return redirect()->route('intelligence.show', $item)->with('status', 'Review started.');
    }

    public function decide(Request $request, IntelligenceItem $item): RedirectResponse
    {
        $data = $request->only(['decision', 'rationale', 'version']);
        $this->workflow->decide($this->viewer($request), $item, $data);

        return redirect()->route('intelligence.show', $item)->with('status', match ($data['decision'] ?? null) {
            'approve' => 'Approved. A director can now publish it.',
            'request_changes' => 'Changes requested. The author can edit and resubmit it.',
            default => 'Rejected.',
        });
    }

    private function viewer(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
