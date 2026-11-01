<?php

namespace Tests\Support\Access;

use Illuminate\Database\Eloquent\Model;

/** A market scope of a ProbeRecord. */
class ProbeMarketScope extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $connection = ProbeRecord::CONNECTION;

    protected $table = 'probe_record_market';

    protected $guarded = ['*'];
}
