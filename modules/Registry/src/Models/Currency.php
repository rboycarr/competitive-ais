<?php

namespace Modules\Registry\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An ISO 4217 currency (P1-23a; seeded by its migration). Taxonomy managers add and edit currencies (P1-23b, PO
 * decision O29): the code is the key and fixed; never deleted.
 *
 * @property string $code
 * @property string $name
 * @property int $minor_units
 */
class Currency extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected $guarded = ['*'];
}
