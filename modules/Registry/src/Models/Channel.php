<?php

namespace Modules\Registry\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A sales channel (P1-23b): unclassified reference data maintained by taxonomy managers. A channel that retailers use
 * is not removed. No mass assignment.
 *
 * @property int $id
 * @property string $name
 * @property string $channel_type
 */
class Channel extends Model
{
    public const TYPES = ['modern_trade', 'general_trade', 'ecommerce', 'horeca', 'wholesale', 'direct', 'other'];

    protected $guarded = ['*'];
}
