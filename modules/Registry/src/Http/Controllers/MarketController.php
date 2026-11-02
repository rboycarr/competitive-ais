<?php

namespace Modules\Registry\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Registry\Actions\MarketService;
use Modules\Registry\Actions\RegionService;
use Modules\Registry\Models\Currency;
use Modules\Registry\Models\Market;
use Modules\Registry\Models\Region;

/** Admin › Markets (P1-23a): markets and their regions for taxonomy.manage holders; the services check again. */
class MarketController
{
    public function __construct(
        private readonly MarketService $markets,
        private readonly RegionService $regions,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Registry/Admin/Markets', [
            'markets' => Market::query()->withCount('regions')->orderBy('code')->get()->map(fn (Market $m): array => [
                'ulid' => $m->ulid, 'code' => $m->code, 'name' => $m->name, 'country' => $m->country, 'currency' => $m->default_currency,
                'regions' => (int) $m->getAttribute('regions_count'),
            ])->all(),
            'currencies' => $this->currencies(),
        ]);
    }

    public function show(Market $market): Response
    {
        $children = Region::query()->where('market_id', $market->id)->get()->groupBy(fn (Region $r): int => (int) $r->parent_id);
        $rows = [];
        // Depth first, siblings by name, so the page can indent by depth.
        $walk = function (int $parentKey, int $depth) use (&$walk, &$rows, $children): void {
            foreach ($children->get($parentKey, collect())->sortBy(fn (Region $r): string => mb_strtolower($r->name)) as $region) {
                $rows[] = ['id' => $region->id, 'name' => $region->name, 'level' => $region->level, 'depth' => $depth];
                $walk($region->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return Inertia::render('Registry/Admin/Market', [
            'market' => ['ulid' => $market->ulid, 'code' => $market->code, 'name' => $market->name, 'country' => $market->country, 'currency' => $market->default_currency],
            'regions' => $rows,
            'levels' => array_keys(Region::LEVELS),
            'currencies' => $this->currencies(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:20']] + $this->rules());
        $market = $this->markets->create($request->user() ?? abort(401), $this->strings($validated));

        return redirect()->route('admin.markets.show', $market)->with('status', "Market {$market->code} added.");
    }

    public function update(Request $request, Market $market): RedirectResponse
    {
        $this->markets->update($request->user() ?? abort(401), $market, $this->strings($request->validate($this->rules())));

        return back()->with('status', 'Market saved.');
    }

    public function storeRegion(Request $request, Market $market): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'level' => ['required', 'string'],
            'parent' => ['nullable', 'integer', 'min:1'],
        ]);
        $this->regions->create($request->user() ?? abort(401), $market, (string) $validated['name'], (string) $validated['level'], isset($validated['parent']) ? (int) $validated['parent'] : null);

        return back()->with('status', 'Region added.');
    }

    public function updateRegion(Request $request, Market $market, Region $region): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $this->regions->rename($request->user() ?? abort(401), $market, $region, (string) $validated['name']);

        return back()->with('status', 'Region renamed.');
    }

    public function destroyRegion(Request $request, Market $market, Region $region): RedirectResponse
    {
        $this->regions->remove($request->user() ?? abort(401), $market, $region);

        return back()->with('status', 'Region removed.');
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'size:2'],
            'currency' => ['required', 'string', 'size:3'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, ?string>
     */
    private function strings(array $validated): array
    {
        return array_map(fn (mixed $value): ?string => $value === null ? null : (string) $value, $validated);
    }

    /** @return list<array{code: string, name: string}> */
    private function currencies(): array
    {
        /** @var Collection<int, Currency> $currencies */
        $currencies = Currency::query()->orderBy('code')->get();

        return array_values($currencies->map(fn (Currency $c): array => ['code' => $c->code, 'name' => $c->name])->all());
    }
}
