<?php

namespace Modules\Intelligence\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A claim citing an evidence asset (P1-41, docs/02 §3.6): it supports, contradicts or gives context to the claim, at
 * an optional locator (page, section or timecode) with an optional excerpt (plain text). Seen only through its item;
 * the asset's details only by those who can see the asset. Added and removed while the item is editable.
 *
 * @property int $id
 * @property int $intelligence_claim_id
 * @property int $evidence_asset_id
 * @property string $relation
 * @property ?string $locator
 * @property ?string $excerpt
 * @property int $linked_by
 * @property ?CarbonInterface $created_at
 */
class ClaimEvidenceLink extends Model
{
    public const RELATIONS = ['supports', 'contradicts', 'context'];

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['intelligence_claim_id' => 'integer', 'evidence_asset_id' => 'integer', 'linked_by' => 'integer'];
    }
}
