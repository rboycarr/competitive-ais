<?php

namespace Modules\Registry\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Classification;
use Modules\Registry\Access\ProductAccess;
use Modules\Registry\Actions\ProductService;
use Modules\Registry\Models\Brand;
use Modules\Registry\Models\Competitor;
use Modules\Registry\Models\Product;
use Modules\Registry\Models\ProductCategory;
use Modules\Registry\Models\ProductVariant;
use Modules\Registry\Support\CategoryTree;

/**
 * Products (P1-22b): list and detail for product.view holders, filtered like competitors (ProductAccess, PO decision
 * O25; hidden products are 404); creating and editing for product.manage holders through ProductService.
 */
class ProductController
{
    public function __construct(
        private readonly ProductService $service,
        private readonly ProductAccess $products,
        private readonly AccessScope $access,
        private readonly Gate $gate,
    ) {}

    public function index(Request $request): Response
    {
        $viewer = $this->viewer($request);
        $filters = $request->validate([
            'owner' => ['nullable', 'string', 'regex:/^(own|[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26})$/'],
            'status' => ['nullable', Rule::in(Product::STATUSES)],
            'category' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Product::query()->visibleTo($viewer)->orderBy('name');
        if (($filters['owner'] ?? null) === 'own') {
            $query->whereNull('competitor_id');
        } elseif (isset($filters['owner'])) {
            $query->whereIn('competitor_id', Competitor::query()->visibleTo($viewer)->where('ulid', strtoupper((string) $filters['owner']))->select('id'));
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['category'])) {
            $query->where('category_id', (int) $filters['category']);
        }
        $products = $query->get();
        $names = $this->names($viewer, $products);

        return Inertia::render('Registry/Products/Index', [
            'products' => $products->map(fn (Product $p): array => [
                'ulid' => $p->ulid,
                'name' => $p->name,
                'owner' => $p->competitor_id === null ? null : $names['competitors'][$p->competitor_id] ?? null,
                'brand' => $p->brand_id === null ? null : $names['brands'][$p->brand_id] ?? null,
                'category' => $p->category_id === null ? null : $names['categories'][$p->category_id] ?? null,
                'status' => $p->status,
                'classification' => $p->classification->value,
            ])->all(),
            'filters' => array_filter($filters),
        ] + $this->options($viewer));
    }

    public function show(Request $request, Product $product): Response
    {
        $viewer = $this->viewer($request);
        $this->products->ensureVisible($viewer, $product);
        $names = $this->names($viewer, collect([$product]));

        return Inertia::render('Registry/Products/Show', [
            'product' => [
                'ulid' => $product->ulid,
                'name' => $product->name,
                'owner' => $product->competitor_id === null ? null : $names['competitors'][$product->competitor_id] ?? null,
                'brand' => $product->brand_id === null ? null : $names['brands'][$product->brand_id] ?? null,
                'category' => $product->category_id === null ? null : $names['categories'][$product->category_id] ?? null,
                'status' => $product->status,
                'launchedOn' => $product->launched_on?->toDateString(),
                'discontinuedOn' => $product->discontinued_on?->toDateString(),
                'classification' => $product->classification->value,
                'description' => $product->description,
            ],
            'variants' => $product->variants()->orderBy('sku')->get()->map(fn (ProductVariant $v): array => [
                'ulid' => $v->ulid, 'sku' => $v->sku, 'gtin' => $v->gtin, 'name' => $v->name, 'packSize' => $v->pack_size,
                'packUnit' => $v->pack_unit, 'packCount' => $v->pack_count, 'status' => $v->status,
            ])->all(),
            'units' => ProductVariant::UNITS,
            'variantStatuses' => ProductVariant::STATUSES,
        ] + $this->options($viewer));
    }

    public function store(Request $request): RedirectResponse
    {
        $product = $this->service->create($this->viewer($request), $this->validated($request, creating: true));

        return redirect()->route('products.show', $product)->with('status', "Product {$product->name} added.");
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->service->update($this->viewer($request), $product, $this->validated($request, creating: false));

        return back()->with('status', 'Changes saved.');
    }

    /** @return array<string, ?string> */
    private function validated(Request $request, bool $creating): array
    {
        $validated = $request->validate(($creating ? ['competitor' => ['nullable', 'string', 'ulid']] : []) + [
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'ulid'],
            'category' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', Rule::in(Product::STATUSES)],
            'launched_on' => ['nullable', 'date_format:Y-m-d'],
            'discontinued_on' => ['nullable', 'date_format:Y-m-d'],
            'classification' => ['required', Rule::enum(Classification::class)],
            'description' => ['nullable', 'string', 'max:10000'],
        ]);

        return array_map(fn (mixed $value): ?string => $value === null ? null : (string) $value, $validated);
    }

    /**
     * Display names, each only within what the viewer can see.
     *
     * @param  Collection<int, Product>  $products
     * @return array{competitors: array<int, array{ulid: string, name: string}>, brands: array<int, array{ulid: string, name: string}>, categories: array<int, array{id: int, name: string}>}
     */
    private function names(Authenticatable $viewer, Collection $products): array
    {
        $ids = fn (string $column): array => array_values(array_unique(array_filter($products->pluck($column)->all())));

        return [
            'competitors' => Competitor::query()->visibleTo($viewer)->whereKey($ids('competitor_id'))->get(['id', 'ulid', 'name'])
                ->mapWithKeys(fn (Competitor $c): array => [$c->id => ['ulid' => $c->ulid, 'name' => $c->name]])->all(),
            'brands' => Brand::query()->visibleTo($viewer)->whereKey($ids('brand_id'))->get(['id', 'ulid', 'name'])
                ->mapWithKeys(fn (Brand $b): array => [$b->id => ['ulid' => $b->ulid, 'name' => $b->name]])->all(),
            'categories' => ProductCategory::query()->whereKey($ids('category_id'))->get(['id', 'name'])
                ->mapWithKeys(fn (ProductCategory $c): array => [$c->id => ['id' => $c->id, 'name' => $c->name]])->all(),
        ];
    }

    /**
     * Filter and form options. Owners and brands only within what the viewer can see; classifications only up to it.
     *
     * @return array<string, mixed>
     */
    private function options(Authenticatable $viewer): array
    {
        $canManage = $this->gate->forUser($viewer)->allows('product.manage');
        $competitors = Competitor::query()->visibleTo($viewer)->orderBy('name')->get(['id', 'ulid', 'name']);
        $owners = $competitors->pluck('ulid', 'id');

        return [
            'canManage' => $canManage,
            'statuses' => Product::STATUSES,
            'competitors' => $competitors->map(fn (Competitor $c): array => ['ulid' => $c->ulid, 'name' => $c->name])->all(),
            'categories' => array_map(fn (ProductCategory $c): array => ['id' => $c->id, 'name' => $c->name, 'depth' => $c->depth], CategoryTree::depthFirst()),
            'brands' => $canManage ? Brand::query()->visibleTo($viewer)->orderBy('name')->get(['id', 'ulid', 'name', 'competitor_id'])
                ->map(fn (Brand $b): array => ['ulid' => $b->ulid, 'name' => $b->name, 'owner' => $b->competitor_id === null ? null : $owners[$b->competitor_id] ?? null])->all() : [],
            'classifications' => $canManage ? array_values(array_map(
                fn (Classification $c): string => $c->value,
                array_filter(Classification::cases(), fn (Classification $c): bool => $this->access->allows($viewer, $this->probe($c))),
            )) : [],
        ];
    }

    private function probe(Classification $classification): Product
    {
        $probe = new Product;
        $probe->forceFill(['classification' => $classification]);

        return $probe;
    }

    private function viewer(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }
}
