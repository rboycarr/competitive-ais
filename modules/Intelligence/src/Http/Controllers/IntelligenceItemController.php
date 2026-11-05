<?php

namespace Modules\Intelligence\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Intelligence\Actions\IntelligenceItemService;
use Modules\Intelligence\Http\Requests\SaveItemRequest;
use Modules\Intelligence\Models\IntelligenceClaim;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Classification;
use Modules\Platform\Support\SafeMarkdown;
use Modules\Registry\Contracts\CompetitorLookup;
use Modules\Registry\Contracts\CompetitorSummary;
use Modules\Registry\Contracts\MarketLookup;
use Modules\Registry\Contracts\MarketSummary;
use Modules\Registry\Contracts\ProductLookup;
use Modules\Registry\Contracts\ProductSummary;

/**
 * Intelligence feed and item page (P1-40). Items are filtered by IntelligenceItem::seenBy (AccessScope and
 * statusVisible); a hidden item is 404. Subjects the viewer cannot see are counted, never named. The summary goes to
 * the page as SafeMarkdown's node tree, never as HTML; its Markdown source only to those who may edit it.
 */
class IntelligenceItemController
{
    private const PAGE = 50;

    public function __construct(
        private readonly AccessScope $access,
        private readonly Gate $gate,
        private readonly UserDirectory $users,
        private readonly CompetitorLookup $competitors,
        private readonly ProductLookup $products,
        private readonly MarketLookup $markets,
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
        $markets = $this->markets->summaries($marketIds);
        $claims = $item->claims()->orderBy('position')->orderBy('id')->get();
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
            ])->values()->all(),
            'canEdit' => $canEdit,
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
