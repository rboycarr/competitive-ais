<?php

namespace Modules\Intelligence\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Why an approved or published item must be looked at again (P1-48, docs/02 §3.6): evidence its claims cite was
 * withdrawn or failed an integrity check. Append-only, written by the system (FlagCitingItems); open while the item's
 * `rereview_since` is set and the flag was raised since then. Seen only through its item; the evidence is described
 * only to viewers who can see it.
 *
 * @property int $id
 * @property string $ulid
 * @property int $intelligence_item_id
 * @property int $evidence_asset_id
 * @property string $cause
 * @property int $item_version
 * @property CarbonInterface $raised_at
 */
class RereviewFlag extends Model
{
    public const CAUSES = ['evidence_withdrawn', 'evidence_integrity_failed'];

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
            'evidence_asset_id' => 'integer',
            'item_version' => 'integer',
            'raised_at' => 'immutable_datetime',
        ];
    }
}
