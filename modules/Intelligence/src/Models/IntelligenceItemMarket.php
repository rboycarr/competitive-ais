<?php

namespace Modules\Intelligence\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A market the item is about; one of its scopes for AccessScope (all-of) (P1-40, docs/02 §3.6).
 *
 * @property int $intelligence_item_id
 * @property int $market_id
 */
class IntelligenceItemMarket extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'intelligence_item_market';

    protected $primaryKey = 'market_id';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['intelligence_item_id' => 'integer', 'market_id' => 'integer'];
    }
}
