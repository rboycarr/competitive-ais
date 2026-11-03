<?php

namespace Modules\Evidence\Models;

use Illuminate\Database\Eloquent\Model;

/** @property string $storage_key */
class EvidenceBlob extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'legal_hold' => 'boolean', 'first_scanned_at' => 'datetime', 'last_verified_at' => 'datetime'];
    }
}
