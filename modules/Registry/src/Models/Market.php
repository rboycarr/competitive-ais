<?php

namespace Modules\Registry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A market (P1-23a): unclassified reference data maintained by taxonomy managers. The code is fixed; never deleted.
 * No mass assignment.
 *
 * @property int $id
 * @property string $ulid
 * @property string $code
 * @property string $name
 * @property string $country
 * @property string $default_currency
 */
class Market extends Model
{
    protected $guarded = ['*'];

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return HasMany<Region, $this> */
    public function regions(): HasMany
    {
        return $this->hasMany(Region::class);
    }
}
