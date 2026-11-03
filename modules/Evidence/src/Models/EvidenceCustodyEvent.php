<?php

namespace Modules\Evidence\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One step in an asset's chain of custody (P1-32a, docs/02 §3.5): append-only, read only through a visible asset.
 *
 * @property int $id
 * @property int $evidence_asset_id
 * @property string $event
 * @property string $actor_type
 * @property ?int $actor_id
 * @property CarbonInterface $occurred_at
 * @property ?array<string, mixed> $details
 */
class EvidenceCustodyEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'details' => 'array'];
    }
}
