<?php

namespace Modules\Registry\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Registry\Actions\RetailerService;
use Modules\Registry\Models\Channel;
use Modules\Registry\Models\Market;
use Modules\Registry\Models\Retailer;

/** Admin › Retailers (P1-23b) for taxonomy.manage holders, filtered by market and channel; RetailerService checks again. */
class RetailerController
{
    public function __construct(private readonly RetailerService $retailers) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'market' => ['nullable', 'string', 'ulid'],
            'channel' => ['nullable', 'integer', 'min:1'],
        ]);
        $markets = Market::query()->orderBy('code')->get(['id', 'ulid', 'code', 'name']);
        $channels = Channel::query()->orderBy('name')->get(['id', 'name']);

        $query = Retailer::query()->orderBy('name');
        if (isset($filters['market'])) {
            $query->where('market_id', $markets->firstWhere('ulid', strtoupper((string) $filters['market']))->id ?? 0);
        }
        if (isset($filters['channel'])) {
            $query->where('channel_id', (int) $filters['channel']);
        }
        $marketOf = $markets->keyBy('id');

        return Inertia::render('Registry/Admin/Retailers', [
            'retailers' => $query->get()->map(fn (Retailer $r): array => [
                'ulid' => $r->ulid, 'name' => $r->name, 'website' => $r->website, 'channel' => $r->channel_id,
                'market' => $marketOf->get($r->market_id)?->code,
            ])->all(),
            'markets' => $markets->map(fn (Market $m): array => ['ulid' => $m->ulid, 'code' => $m->code, 'name' => $m->name])->all(),
            'channels' => $channels->map(fn (Channel $c): array => ['id' => $c->id, 'name' => $c->name])->all(),
            'filters' => array_filter($filters),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['market' => ['required', 'string', 'ulid']] + $this->rules());
        $market = Market::query()->where('ulid', strtoupper((string) $validated['market']))->first()
            ?? throw ValidationException::withMessages(['market' => 'Choose a listed market.']);
        $this->retailers->create($request->user() ?? abort(401), $market, (string) $validated['name'], (int) $validated['channel'], $this->website($validated));

        return back()->with('status', 'Retailer added.');
    }

    public function update(Request $request, Retailer $retailer): RedirectResponse
    {
        $validated = $request->validate($this->rules());
        $this->retailers->update($request->user() ?? abort(401), $retailer, (string) $validated['name'], (int) $validated['channel'], $this->website($validated));

        return back()->with('status', 'Retailer saved.');
    }

    public function destroy(Request $request, Retailer $retailer): RedirectResponse
    {
        $this->retailers->remove($request->user() ?? abort(401), $retailer);

        return back()->with('status', 'Retailer removed.');
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'channel' => ['required', 'integer', 'min:1'],
            'website' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /** @param  array<string, mixed>  $validated */
    private function website(array $validated): ?string
    {
        return isset($validated['website']) ? (string) $validated['website'] : null;
    }
}
