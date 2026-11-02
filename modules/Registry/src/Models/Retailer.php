<?php

namespace Modules\Registry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A retailer (P1-23b): trades in one market (fixed) through one channel. Unclassified reference data maintained by
 * taxonomy managers. No mass assignment.
 *
 * @property int $id
 * @property string $ulid
 * @property int $market_id
 * @property int $channel_id
 * @property string $name
 * @property ?string $website
 */
class Retailer extends Model
{
    protected $guarded = ['*'];

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Market, $this> */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
