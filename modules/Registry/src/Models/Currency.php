<?php

namespace Modules\Registry\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An ISO 4217 currency (P1-23a). Read-only for the app: rows come from the migration.
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
