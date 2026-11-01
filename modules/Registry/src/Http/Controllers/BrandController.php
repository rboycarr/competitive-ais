<?php

namespace Modules\Registry\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Registry\Actions\BrandService;
use Modules\Registry\Models\Brand;
use Modules\Registry\Models\Competitor;

/**
 * Brands (P1-22a): our brands and those of competitors the viewer can see, for product.view holders; changes for
 * product.manage holders through BrandService (a brand of a hidden competitor is 404).
 */
class BrandController
{
    public function __construct(
        private readonly BrandService $brands,
        private readonly Gate $gate,
    ) {}

    public function index(Request $request): Response
    {
        $viewer = $request->user() ?? abort(401);
        $competitors = Competitor::query()->visibleTo($viewer)->orderBy('name')->get(['id', 'ulid', 'name'])->keyBy('id');
        $brands = Brand::query()->visibleTo($viewer)->get()
            ->sortBy(fn (Brand $b): string => ($b->competitor_id === null ? '0' : '1'.mb_strtolower((string) $competitors->get($b->competitor_id)?->name)).' '.mb_strtolower($b->name))
            ->values();
        $canManage = $this->gate->forUser($viewer)->allows('product.manage');

        return Inertia::render('Registry/Brands/Index', [
            'brands' => $brands->map(fn (Brand $b): array => [
                'ulid' => $b->ulid,
                'name' => $b->name,
                'owner' => ($owner = $competitors->get((int) $b->competitor_id)) === null ? null : ['ulid' => $owner->ulid, 'name' => $owner->name],
            ])->all(),
            'canManage' => $canManage,
            // Owners a new brand may get: every competitor the viewer can see, archived ones included.
            'competitors' => $canManage ? $competitors->map(fn (Competitor $c): array => ['ulid' => $c->ulid, 'name' => $c->name])->values()->all() : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'competitor' => ['nullable', 'string', 'ulid'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $this->brands->create($request->user() ?? abort(401), isset($validated['competitor']) ? (string) $validated['competitor'] : null, (string) $validated['name']);

        return back()->with('status', 'Brand added.');
    }

    public function update(Request $request, Brand $brand): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $this->brands->rename($request->user() ?? abort(401), $brand, (string) $validated['name']);

        return back()->with('status', 'Brand renamed.');
    }

    public function destroy(Request $request, Brand $brand): RedirectResponse
    {
        $this->brands->remove($request->user() ?? abort(401), $brand);

        return back()->with('status', 'Brand removed.');
    }
}
