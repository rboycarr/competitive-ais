<?php

namespace Modules\Registry\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Classification;
use Modules\Registry\Models\Competitor;
use Modules\Registry\Models\Product;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One product's visibility (PO decision O25), the same rule as Product::scopeVisibleTo(): AccessScope on the
 * product's classification, and a competitor's product only while the competitor is visible. Hidden products are 404.
 */
class ProductAccess
{
    public function __construct(private readonly AccessScope $access) {}

    public function allows(Authenticatable $viewer, Product $product): bool
    {
        if (! $this->access->allows($viewer, $product)) {
            return false;
        }

        return $product->competitor_id === null
            || Competitor::query()->visibleTo($viewer)->whereKey($product->competitor_id)->exists();
    }

    public function ensureVisible(Authenticatable $viewer, Product $product): void
    {
        if (! $this->allows($viewer, $product)) {
            throw new NotFoundHttpException;
        }
    }

    /** The classification for audit rows about a product: its own, or its competitor's if that is higher. */
    public static function auditClassification(Product $product, ?Competitor $owner): Classification
    {
        return $owner !== null && $owner->classification->rank() > $product->classification->rank() ? $owner->classification : $product->classification;
    }
}
