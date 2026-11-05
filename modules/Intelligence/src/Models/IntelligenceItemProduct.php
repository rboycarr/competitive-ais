<?php

namespace Modules\Intelligence\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A product the item is about; its competitor, if any, is always among the item's competitors (P1-40, docs/02 §3.6).
 *
 * @property int $intelligence_item_id
 * @property int $product_id
 */
class IntelligenceItemProduct extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'intelligence_item_product';

    protected $primaryKey = 'product_id';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['intelligence_item_id' => 'integer', 'product_id' => 'integer'];
    }
}
