<?php

namespace Modules\Registry\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Registry\Models\Competitor;
use Modules\Registry\Models\Product;

/**
 * Quick search (P1-24): competitors by name or alias, and products by name, that the viewer can see (the same
 * visibleTo rules as the lists), at most LIMIT of each. A substring match on the name; MySQL FULLTEXT and ranking come
 * with Phase 2 search (developer decision 2026-11-08: simpler designs).
 */
class SearchController
{
    private const LIMIT = 20;

    public function __construct(private readonly Gate $gate) {}

    public function __invoke(Request $request): Response
    {
        $viewer = $request->user() ?? abort(401);
        $gate = $this->gate->forUser($viewer);
        $competitors = $gate->allows('competitor.view');
        $products = $gate->allows('product.view');
        if (! $competitors && ! $products) {
            throw new AuthorizationException('Quick search covers competitors and products.');
        }
        $q = trim((string) $request->validate(['q' => ['nullable', 'string', 'max:100']])['q']);
        $results = ['competitors' => [], 'products' => []];

        if (mb_strlen($q) >= 2) {
            $like = '%'.addcslashes($q, '\\%_').'%';
            if ($competitors) {
                $results['competitors'] = Competitor::query()->visibleTo($viewer)
                    ->where(fn ($w) => $w->where('name', 'like', $like)->orWhereExists(fn ($a) => $a->from('competitor_aliases')
                        ->whereColumn('competitor_aliases.competitor_id', 'competitors.id')->where('alias', 'like', $like)))
                    ->orderBy('name')->limit(self::LIMIT)->get()
                    ->map(fn (Competitor $c): array => ['label' => $c->name, 'href' => route('competitors.show', $c), 'meta' => $c->lifecycle])->all();
            }
            if ($products) {
                $results['products'] = Product::query()->visibleTo($viewer)->where('name', 'like', $like)
                    ->orderBy('name')->limit(self::LIMIT)->get()
                    ->map(fn (Product $p): array => ['label' => $p->name, 'href' => route('products.show', $p), 'meta' => $p->status])->all();
            }
        }

        return Inertia::render('Registry/Search', [
            'q' => $q,
            'searched' => mb_strlen($q) >= 2,
            'limit' => self::LIMIT,
            'competitors' => $competitors ? $results['competitors'] : null,
            'products' => $products ? $results['products'] : null,
        ]);
    }
}
