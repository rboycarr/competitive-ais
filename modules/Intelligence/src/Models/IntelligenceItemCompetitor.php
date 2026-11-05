<?php

namespace Modules\Intelligence\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A competitor the item is about; one of its scopes for AccessScope (all-of) (P1-40, docs/02 §3.6).
 *
 * @property int $intelligence_item_id
 * @property int $competitor_id
 */
class IntelligenceItemCompetitor extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'competitor_intelligence_item';

    protected $primaryKey = 'competitor_id';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['intelligence_item_id' => 'integer', 'competitor_id' => 'integer'];
    }
}
