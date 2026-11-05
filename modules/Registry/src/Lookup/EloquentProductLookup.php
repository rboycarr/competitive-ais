<?php

namespace Modules\Registry\Lookup;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Registry\Contracts\ProductLookup;
use Modules\Registry\Contracts\ProductSummary;
use Modules\Registry\Models\Product;

/** ProductLookup over the products table, filtered by Product::scopeVisibleTo (O25, P1-40). */
class EloquentProductLookup implements ProductLookup
{
    private const COLUMNS = ['id', 'ulid', 'name', 'competitor_id', 'classification'];

    public function findVisible(Authenticatable $viewer, string $ulid): ?ProductSummary
    {
        $product = Product::query()->visibleTo($viewer)->where('ulid', strtoupper($ulid))->first(self::COLUMNS);

        return $product === null ? null : $this->summary($product);
    }

    public function visibleTo(Authenticatable $viewer): array
    {
        return array_values(Product::query()->visibleTo($viewer)->orderBy('name')->orderBy('id')->get(self::COLUMNS)
            ->map(fn (Product $p): ProductSummary => $this->summary($p))->all());
    }

    public function summaries(Authenticatable $viewer, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Product::query()->visibleTo($viewer)->whereIn('id', $ids)->get(self::COLUMNS)
            ->mapWithKeys(fn (Product $p): array => [$p->id => $this->summary($p)])->all();
    }

    private function summary(Product $product): ProductSummary
    {
        return new ProductSummary($product->id, $product->ulid, $product->name, $product->competitor_id);
    }
}
