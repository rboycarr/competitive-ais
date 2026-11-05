<?php

namespace Modules\Evidence\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Evidence\Actions\EvidenceDownloadService;
use Modules\Evidence\Actions\EvidenceHoldService;
use Modules\Evidence\Actions\EvidenceUploadService;
use Modules\Evidence\Http\Requests\UploadEvidenceRequest;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceCustodyEvent;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Classification;
use Modules\Registry\Contracts\CompetitorLookup;
use Modules\Registry\Contracts\CompetitorSummary;
use Modules\Registry\Contracts\MarketLookup;
use Modules\Registry\Contracts\MarketSummary;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Evidence (P1-32a): list and detail for evidence.view holders (EvidenceAsset::seenBy: AccessScope, and until an
 * asset is available only its uploader and evidence.hold holders; hidden assets are 404), the upload form for
 * evidence.upload holders, downloads for evidence.download holders (P1-34, EvidenceDownloadService), and legal hold
 * for evidence.hold holders (P1-35, EvidenceHoldService).
 */
class EvidenceController
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly UserDirectory $users,
        private readonly Gate $gate,
        private readonly CompetitorLookup $competitors,
        private readonly MarketLookup $markets,
    ) {}

    public function index(Request $request): Response
    {
        $viewer = $this->viewer($request);
        $filters = $request->validate(['status' => ['nullable', Rule::in(EvidenceAsset::STATUSES)]]);

        $query = EvidenceAsset::seenBy(EvidenceAsset::query(), $viewer, $this->gate)->orderByDesc('captured_at')->orderByDesc('id');
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        $assets = $query->limit(500)->get();
        $names = $this->users->summaries(array_values(array_unique($assets->pluck('uploaded_by')->all())));
        $canUpload = $this->gate->forUser($viewer)->allows('evidence.upload');

        return Inertia::render('Evidence/Index', [
            'assets' => $assets->map(fn (EvidenceAsset $a): array => [
                'ulid' => $a->ulid,
                'title' => $a->title,
                'status' => $a->status,
                'capturedAt' => $a->captured_at->toIso8601ZuluString(),
                'uploadedBy' => $names[$a->uploaded_by]->name ?? null,
                'classification' => $a->classification->value,
            ])->all(),
            'filters' => array_filter($filters),
            'statuses' => EvidenceAsset::STATUSES,
            'canUpload' => $canUpload,
            // Offered only up to what the viewer can see; the service enforces it.
            'classifications' => $canUpload ? array_values(array_map(
                fn (Classification $c): string => $c->value,
                array_filter(Classification::cases(), fn (Classification $c): bool => $this->access->allows($viewer, $this->probe($c))),
            )) : [],
            'maxBytes' => (int) config('evidence.max_bytes'),
            // P1-36a: what the evidence is about. Competitors the viewer can see; markets are unclassified.
            'competitors' => $canUpload ? array_map(fn (CompetitorSummary $c): array => ['value' => $c->ulid, 'label' => $c->name], $this->competitors->visibleTo($viewer)) : [],
            'markets' => $canUpload ? array_map(fn (MarketSummary $m): array => ['value' => $m->ulid, 'label' => "{$m->name} ({$m->code})"], $this->markets->all()) : [],
        ]);
    }

    public function show(Request $request, EvidenceAsset $asset): Response
    {
        $viewer = $this->viewer($request);
        // The same rule as the list: the asset must be among those the viewer may see.
        $seen = EvidenceAsset::seenBy(EvidenceAsset::query(), $viewer, $this->gate)->whereKey($asset->id)->exists();
        abort_unless($seen, 404);
        $events = $asset->custodyEvents()->orderBy('occurred_at')->orderBy('id')->get();
        $names = $this->users->summaries(array_values(array_unique(array_filter([$asset->uploaded_by, ...$events->pluck('actor_id')->all()]))));

        return Inertia::render('Evidence/Show', [
            'asset' => [
                'ulid' => $asset->ulid,
                'title' => $asset->title,
                'description' => $asset->description,
                'status' => $asset->status,
                'scanStatus' => $asset->scan_status,
                'originalFilename' => $asset->original_filename,
                'sizeBytes' => $asset->size_bytes,
                'sourceUrl' => $asset->source_url,
                'capturedAt' => $asset->captured_at->toIso8601ZuluString(),
                'uploadedBy' => $names[$asset->uploaded_by]->name ?? null,
                'uploadedAt' => $asset->created_at?->toIso8601ZuluString(),
                'classification' => $asset->classification->value,
                'legalHold' => $asset->legal_hold,
                // P1-36a: a viewer who sees the asset is covered for all its scopes; names only of competitors the
                // viewer can see (a competitor may be classified above the asset).
                'competitors' => array_values(array_map(fn (CompetitorSummary $c): string => $c->name, $this->competitors->summaries($viewer, $asset->competitorIds()))),
                'markets' => array_values(array_map(fn (MarketSummary $m): string => "{$m->name} ({$m->code})", $this->markets->summaries($asset->marketIds()))),
            ],
            // Offered only where it would succeed; EvidenceDownloadService and EvidenceHoldService check again.
            'canDownload' => $asset->status === 'available' && $this->gate->forUser($viewer)->allows('evidence.download'),
            'canHold' => $asset->status === 'available' && $this->gate->forUser($viewer)->allows('evidence.hold'),
            'custody' => $events->map(fn (EvidenceCustodyEvent $e): array => [
                'event' => $e->event,
                'actor' => $e->actor_id === null ? null : ($names[$e->actor_id]->name ?? null),
                'occurredAt' => $e->occurred_at->toIso8601ZuluString(),
                // The reason a holder gave for a legal-hold change (P1-35, O35); shown as text.
                'reason' => in_array($e->event, ['hold_set', 'hold_released'], true) && is_string($e->details['reason'] ?? null) ? $e->details['reason'] : null,
            ])->all(),
        ]);
    }

    /**
     * A POST, so a link from another site cannot record a download in someone's name; the browser is sent to the
     * short-lived link (Inertia::location: a full-page visit, never an XHR that would expose it to scripts).
     */
    public function download(Request $request, EvidenceAsset $asset, EvidenceDownloadService $downloads): SymfonyResponse
    {
        return Inertia::location($downloads->link($this->viewer($request), $asset));
    }

    /** P1-35: places or releases a legal hold, with the reason (EvidenceHoldService checks and validates). */
    public function hold(Request $request, EvidenceAsset $asset, EvidenceHoldService $holds): RedirectResponse
    {
        $data = $request->validate([
            'hold' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:'.EvidenceHoldService::MAX_REASON],
        ]);
        $holds->change($this->viewer($request), $asset, (bool) $data['hold'], $data['reason']);

        return redirect()->route('evidence.show', $asset)->with('status', $data['hold'] ? 'Placed on legal hold.' : 'Legal hold released.');
    }

    public function store(UploadEvidenceRequest $request, EvidenceUploadService $uploads): RedirectResponse
    {
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $asset = $uploads->upload($this->viewer($request), $file, $request->safe()->except('file'));

        return redirect()->route('evidence.show', $asset)->with('status', "{$asset->title} uploaded. It stays in quarantine until it has been scanned.");
    }

    private function probe(Classification $classification): EvidenceAsset
    {
        $probe = new EvidenceAsset;
        $probe->forceFill(['classification' => $classification]);

        return $probe->setRelation('competitorScopes', new Collection)->setRelation('marketScopes', new Collection);
    }

    private function viewer(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
