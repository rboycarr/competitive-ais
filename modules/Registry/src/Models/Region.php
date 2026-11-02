<?php

namespace Modules\Registry\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A region of a market (P1-23a). Levels deepen strictly from parent to child; the database holds that rule (see the
 * migration). Name and place in the tree only; the level is fixed. No mass assignment.
 *
 * @property int $id
 * @property int $market_id
 * @property ?int $parent_id
 * @property string $name
 * @property string $level
 * @property int $level_rank generated from level
 * @property ?int $parent_level_rank
 */
class Region extends Model
{
    /** Level => rank, shallowest first. */
    public const LEVELS = ['region' => 1, 'province' => 2, 'city' => 3, 'district' => 4];

    protected $guarded = ['*'];
}
