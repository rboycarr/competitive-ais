<?php

namespace Modules\Intelligence\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A claim of an intelligence item (P1-40, docs/02 §3.6): a typed statement (observed fact … AI hypothesis, kept apart
 * from its rating) with two human-assigned Admiralty ratings (ADR-0010): source reliability A–F and information
 * credibility 1–6. Seen only through its item. Withdrawn, never deleted.
 *
 * @property int $id
 * @property string $ulid
 * @property int $intelligence_item_id
 * @property int $position
 * @property string $statement
 * @property string $claim_type
 * @property string $source_reliability
 * @property int $info_credibility
 * @property string $status
 * @property int $created_by
 * @property ?CarbonInterface $created_at
 */
class IntelligenceClaim extends Model
{
    public const TYPES = ['observed_fact', 'reported_claim', 'analyst_inference', 'rumor', 'ai_hypothesis'];

    /** A (completely reliable) … E (unreliable), F (cannot be judged). */
    public const RELIABILITIES = ['A', 'B', 'C', 'D', 'E', 'F'];

    /** 1 (confirmed by other sources) … 5 (improbable), 6 (cannot be judged). */
    public const CREDIBILITIES = [1, 2, 3, 4, 5, 6];

    public const STATUSES = ['active', 'disputed', 'withdrawn'];

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return BelongsTo<IntelligenceItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(IntelligenceItem::class, 'intelligence_item_id');
    }

    /** @return HasMany<ClaimEvidenceLink, $this> */
    public function evidenceLinks(): HasMany
    {
        return $this->hasMany(ClaimEvidenceLink::class);
    }

    /** The compact Admiralty badge, e.g. "B2". */
    public function rating(): string
    {
        return $this->source_reliability.$this->info_credibility;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['intelligence_item_id' => 'integer', 'position' => 'integer', 'info_credibility' => 'integer', 'created_by' => 'integer'];
    }
}
