<?php

namespace Modules\Registry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Another name a competitor is known by (P1-21). Visible to whoever sees the competitor. No mass assignment.
 *
 * @property int $id
 * @property int $competitor_id
 * @property string $alias
 * @property string $alias_type
 */
class CompetitorAlias extends Model
{
    public const TYPES = ['brand', 'trade', 'former', 'abbreviation', 'local'];

    protected $guarded = ['*'];

    /** @return BelongsTo<Competitor, $this> */
    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }
}
