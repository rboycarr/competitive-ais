<?php

namespace Modules\Evidence\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A competitor an asset is about (P1-36a, docs/02 §3.5): append-only; AccessScope reads it (all-of).
 *
 * @property int $evidence_asset_id
 * @property int $competitor_id
 * @property string $origin
 * @property int $added_by
 * @property CarbonInterface $created_at
 */
class EvidenceAssetCompetitor extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'evidence_asset_competitor';

    protected $primaryKey = 'competitor_id';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['competitor_id' => 'integer', 'added_by' => 'integer', 'created_at' => 'datetime'];
    }
}
