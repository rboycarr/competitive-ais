<?php

namespace Modules\Evidence\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Evidence\Actions\EvidenceDownloadService;
use Modules\Evidence\Actions\EvidenceUploadService;
use Modules\Evidence\Http\Requests\UploadEvidenceRequest;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceCustodyEvent;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Classification;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Evidence (P1-32a): list and detail for evidence.view holders (EvidenceAsset::seenBy: AccessScope, and until an
 * asset is available only its uploader and evidence.hold holders; hidden assets are 404), the upload form for
 * evidence.upload holders, and downloads for evidence.download holders (P1-34, EvidenceDownloadService).
 */
class EvidenceController
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly UserDirectory $users,
        private readonly Gate $gate,
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
            ],
            // Offered only where it would succeed; EvidenceDownloadService checks again.
            'canDownload' => $asset->status === 'available' && $this->gate->forUser($viewer)->allows('evidence.download'),
            'custody' => $events->map(fn (EvidenceCustodyEvent $e): array => [
                'event' => $e->event,
                'actor' => $e->actor_id === null ? null : ($names[$e->actor_id]->name ?? null),
                'occurredAt' => $e->occurred_at->toIso8601ZuluString(),
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

        return $probe;
    }

    private function viewer(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
