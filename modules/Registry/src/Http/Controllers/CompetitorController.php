<?php

namespace Modules\Registry\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Contracts\UserSummary;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Classification;
use Modules\Registry\Actions\CompetitorService;
use Modules\Registry\Http\Requests\CompetitorRequest;
use Modules\Registry\Models\Competitor;

/**
 * Competitors (P1-20): list and profile for competitor.view holders, filtered by AccessScope (hidden entries are 404);
 * create, edit and lifecycle changes for competitor.manage holders.
 */
class CompetitorController
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly UserDirectory $users,
        private readonly Gate $gate,
    ) {}

    public function index(Request $request): Response
    {
        $viewer = $this->viewer($request);
        $filters = $request->validate([
            'lifecycle' => ['nullable', Rule::in(Competitor::LIFECYCLES)],
            'tier' => ['nullable', Rule::in(Competitor::TIERS)],
            'analyst' => ['nullable', 'string', 'ulid'],
        ]);

        $query = Competitor::query()->visibleTo($viewer)->orderBy('name');
        // Archived entries only when asked for: they stay referenced, but leave the working list.
        isset($filters['lifecycle']) ? $query->where('lifecycle', $filters['lifecycle']) : $query->where('lifecycle', '<>', 'archived');
        if (isset($filters['tier'])) {
            $query->where('tier', $filters['tier']);
        }
        $analysts = $this->users->activeHolders('competitor.manage');
        if (isset($filters['analyst'])) {
            $match = array_values(array_filter($analysts, fn (UserSummary $a): bool => $a->ulid === strtoupper((string) $filters['analyst'])));
            $query->where('assigned_analyst_id', $match[0]->id ?? 0);
        }
        $competitors = $query->get();
        $names = $this->users->summaries(array_values(array_unique(array_filter($competitors->pluck('assigned_analyst_id')->all()))));

        return Inertia::render('Registry/Competitors/Index', [
            'competitors' => $competitors->map(fn (Competitor $c): array => [
                'ulid' => $c->ulid,
                'name' => $c->name,
                'lifecycle' => $c->lifecycle,
                'tier' => $c->tier,
                'hqCountry' => $c->hq_country,
                'classification' => $c->classification->value,
                'analyst' => $c->assigned_analyst_id !== null ? ($names[$c->assigned_analyst_id]->name ?? null) : null,
            ])->all(),
            'filters' => array_filter($filters),
        ] + $this->formOptions($viewer, $analysts));
    }

    public function show(Request $request, Competitor $competitor): Response
    {
        $viewer = $this->viewer($request);
        $this->access->ensureVisible($viewer, $competitor);
        $analysts = $this->users->activeHolders('competitor.manage');
        $analyst = $competitor->assigned_analyst_id !== null ? ($this->users->summaries([$competitor->assigned_analyst_id])[$competitor->assigned_analyst_id] ?? null) : null;

        return Inertia::render('Registry/Competitors/Show', [
            'competitor' => [
                'ulid' => $competitor->ulid,
                'name' => $competitor->name,
                'legalName' => $competitor->legal_name,
                'website' => $competitor->website,
                'hqCountry' => $competitor->hq_country,
                'lifecycle' => $competitor->lifecycle,
                'tier' => $competitor->tier,
                'analyst' => $analyst === null ? null : ['ulid' => $analyst->ulid, 'name' => $analyst->name],
                'classification' => $competitor->classification->value,
                'description' => $competitor->description,
            ],
            'lifecycles' => Competitor::LIFECYCLES,
        ] + $this->formOptions($viewer, $analysts));
    }

    public function store(CompetitorRequest $request, CompetitorService $competitors): RedirectResponse
    {
        $competitor = $competitors->create($this->viewer($request), $this->data($request));

        return redirect()->route('competitors.show', $competitor)->with('status', "Competitor {$competitor->name} registered.");
    }

    public function update(CompetitorRequest $request, Competitor $competitor, CompetitorService $competitors): RedirectResponse
    {
        $competitors->update($this->viewer($request), $competitor, $this->data($request));

        return back()->with('status', 'Changes saved.');
    }

    public function lifecycle(Request $request, Competitor $competitor, CompetitorService $competitors): RedirectResponse
    {
        $validated = $request->validate(['lifecycle' => ['required', Rule::in(Competitor::LIFECYCLES)]]);
        $competitors->setLifecycle($this->viewer($request), $competitor, (string) $validated['lifecycle']);

        return back()->with('status', "Lifecycle set to {$validated['lifecycle']}.");
    }

    /**
     * @param  list<UserSummary>  $analysts
     * @return array<string, mixed>
     */
    private function formOptions(Authenticatable $viewer, array $analysts): array
    {
        $canManage = $this->gate->forUser($viewer)->allows('competitor.manage');

        return [
            'canManage' => $canManage,
            'tiers' => Competitor::TIERS,
            'analysts' => array_map(fn (UserSummary $a): array => ['ulid' => $a->ulid, 'name' => $a->name], $analysts),
            // Offered only up to what the viewer can see; the service enforces it.
            'classifications' => $canManage ? array_values(array_map(
                fn (Classification $c): string => $c->value,
                array_filter(Classification::cases(), fn (Classification $c): bool => $this->access->allows($viewer, $this->probe($c))),
            )) : [],
        ];
    }

    private function probe(Classification $classification): Competitor
    {
        $probe = new Competitor;
        $probe->forceFill(['classification' => $classification]);

        return $probe;
    }

    /** @return array{name: string, legal_name?: ?string, website?: ?string, hq_country?: ?string, tier?: ?string, analyst?: ?string, classification: string, description?: ?string} */
    private function data(CompetitorRequest $request): array
    {
        /** @var array{name: string, legal_name?: ?string, website?: ?string, hq_country?: ?string, tier?: ?string, analyst?: ?string, classification: string, description?: ?string} $data */
        $data = $request->validated();

        return $data;
    }

    private function viewer(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
