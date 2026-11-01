<?php

namespace Tests\Support\Access;

use Illuminate\Database\Eloquent\Model;

/** A competitor scope of a ProbeRecord. */
class ProbeCompetitorScope extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $connection = ProbeRecord::CONNECTION;

    protected $table = 'probe_record_competitor';

    protected $guarded = ['*'];
}
