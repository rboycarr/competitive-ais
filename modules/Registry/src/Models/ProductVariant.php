<?php

namespace Modules\Registry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pack of a product (P1-22b), e.g. 150 g × 1. Visible with its product. No mass assignment; retired with a status.
 *
 * @property int $id
 * @property string $ulid
 * @property int $product_id
 * @property string $sku
 * @property ?string $gtin
 * @property string $name
 * @property string $pack_size decimal(14,4), as a string
 * @property string $pack_unit
 * @property int $pack_count
 * @property string $status
 */
class ProductVariant extends Model
{
    public const UNITS = ['mg', 'g', 'kg', 'ml', 'l', 'unit', 'pack'];

    public const STATUSES = ['active', 'discontinued'];

    protected $guarded = ['*'];

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
