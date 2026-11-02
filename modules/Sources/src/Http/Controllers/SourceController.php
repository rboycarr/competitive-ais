<?php

namespace Modules\Sources\Http\Controllers;

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
use Modules\Platform\Support\Features;
use Modules\Sources\Actions\SourceService;
use Modules\Sources\Http\Requests\ProposeSourceRequest;
use Modules\Sources\Models\Source;
use Modules\Sources\Models\SourcePolicy;

/**
 * Sources (P1-30): list and detail for source.view holders, filtered by AccessScope (hidden sources are 404); the
 * proposal form for source.propose holders. The detail page shows the policy history and, when allowed, the submit
 * button and the decision form (P1-31a).
 */
class SourceController
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly UserDirectory $users,
        private readonly Gate $gate,
        private readonly Features $features,
    ) {}

    public function index(Request $request): Response
    {
        $viewer = $this->viewer($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Source::STATUSES)],
            'type' => ['nullable', Rule::in(Source::TYPES)],
        ]);

        $query = Source::query()->visibleTo($viewer)->orderBy('name')->orderBy('id');
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['type'])) {
            $query->where('source_type', $filters['type']);
        }
        $sources = $query->get();
        $names = $this->users->summaries(array_values(array_unique($sources->pluck('owner_id')->all())));

        return Inertia::render('Sources/Index', [
            'sources' => $sources->map(fn (Source $s): array => [
                'ulid' => $s->ulid,
                'name' => $s->name,
                'type' => $s->source_type,
                'host' => $s->host,
                'method' => $s->method,
                'status' => $s->status,
                'reliability' => $s->default_reliability,
                'owner' => $names[$s->owner_id]->name ?? null,
                'classification' => $s->classification->value,
            ])->all(),
            'filters' => array_filter($filters),
            'statuses' => Source::STATUSES,
            'types' => Source::TYPES,
        ] + $this->formOptions($viewer));
    }

    public function show(Request $request, Source $source): Response
    {
        $viewer = $this->viewer($request);
        $this->access->ensureVisible($viewer, $source);
        $policies = $source->policies()->orderByDesc('version')->get(); // after the visibility check: see Source::policies()
        $names = $this->users->summaries(array_values(array_unique([$source->owner_id, $source->proposed_by, ...$policies->pluck('decided_by')->all()])));
        $viewerId = (int) $viewer->getAuthIdentifier();
        $can = $this->gate->forUser($viewer);

        return Inertia::render('Sources/Show', [
            'source' => [
                'ulid' => $source->ulid,
                'name' => $source->name,
                'type' => $source->source_type,
                'baseUrl' => $source->base_url,
                'host' => $source->host,
                'method' => $source->method,
                'status' => $source->status,
                'reliability' => $source->default_reliability,
                'owner' => $names[$source->owner_id]->name ?? null,
                'proposedBy' => $names[$source->proposed_by]->name ?? null,
                'proposedAt' => $source->created_at?->toIso8601ZuluString(),
                'classification' => $source->classification->value,
                'description' => $source->description,
                'currentPolicyVersion' => $policies->firstWhere('id', $source->current_policy_id)?->version,
            ],
            // Newest first; the policy follows its source's visibility. SourcePolicyService checks every action again.
            'policies' => $policies->map(fn (SourcePolicy $p): array => [
                'version' => $p->version,
                'decision' => $p->decision,
                'termsUrl' => $p->terms_url,
                'termsSummary' => $p->terms_summary,
                'termsReviewedOn' => $p->terms_reviewed_on?->format('Y-m-d'),
                'robotsDecision' => $p->robots_decision,
                'license' => $p->license,
                'allowedCadence' => $p->allowed_cadence,
                'rateBudgetPerHour' => $p->rate_budget_per_hour,
                'retentionDays' => $p->retention_days,
                'containsPersonalData' => $p->contains_personal_data,
                'personalDataBasis' => $p->personal_data_basis,
                'notes' => $p->notes,
                'decidedBy' => $names[$p->decided_by]->name ?? null,
                'decidedAt' => $p->decided_at->toIso8601ZuluString(),
            ])->all(),
            'canSubmit' => $source->status === 'proposed' && $can->allows('source.propose')
                && in_array($viewerId, [$source->owner_id, $source->proposed_by], true),
            'canDecide' => $source->status === 'policy_review' && $viewerId !== $source->proposed_by && $can->allows('source.approve'),
            'decidingOwnProposal' => $source->status === 'policy_review' && $viewerId === $source->proposed_by && $can->allows('source.approve'),
            'basedOnVersion' => (int) ($policies->first()->version ?? 0),
            'decisions' => SourcePolicy::DECISIONS,
            'robotsDecisions' => SourcePolicy::ROBOTS,
            'robotsBound' => in_array($source->method, SourcePolicy::ROBOTS_BOUND_METHODS, true),
        ]);
    }

    public function store(ProposeSourceRequest $request, SourceService $sources): RedirectResponse
    {
        /** @var array{name: string, source_type: string, base_url?: ?string, method?: ?string, default_reliability?: ?string, owner?: ?string, classification: string, description?: ?string} $data */
        $data = $request->validated();
        $source = $sources->propose($this->viewer($request), $data);

        return redirect()->route('sources.show', $source)->with('status', "Source {$source->name} proposed for policy review.");
    }

    /** @return array<string, mixed> */
    private function formOptions(Authenticatable $viewer): array
    {
        $canPropose = $this->gate->forUser($viewer)->allows('source.propose');

        return [
            'canPropose' => $canPropose,
            // Only `manual` while automated collection is off; the service enforces it.
            'methods' => $this->features->enabled(Features::COLLECTION) ? Source::METHODS : ['manual'],
            'reliabilities' => Source::RELIABILITIES,
            'owners' => $canPropose ? array_map(fn (UserSummary $u): array => ['ulid' => $u->ulid, 'name' => $u->name], $this->users->activeHolders('source.propose')) : [],
            // Offered only up to what the viewer can see; the service enforces it.
            'classifications' => $canPropose ? array_values(array_map(
                fn (Classification $c): string => $c->value,
                array_filter(Classification::cases(), fn (Classification $c): bool => $this->access->allows($viewer, $this->probe($c))),
            )) : [],
        ];
    }

    private function probe(Classification $classification): Source
    {
        $probe = new Source;
        $probe->forceFill(['classification' => $classification]);

        return $probe;
    }

    private function viewer(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
