<?php

namespace Modules\Intelligence\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Why a published item was retracted (P1-44, docs/02 §3.6): the reason (plain text), who and when, and optionally the
 * published item that replaces it. Append-only; one per item. Seen only through its item.
 *
 * @property int $id
 * @property int $intelligence_item_id
 * @property string $reason
 * @property int $retracted_by
 * @property CarbonInterface $retracted_at
 * @property ?int $replacement_item_id
 */
class Retraction extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'intelligence_item_id' => 'integer',
            'retracted_by' => 'integer',
            'retracted_at' => 'immutable_datetime',
            'replacement_item_id' => 'integer',
        ];
    }
}
