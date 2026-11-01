<?php

namespace Modules\Registry\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A corporate relationship between two competitors over a period (P1-21, docs/02 §3.3). The parent is the owner or
 * principal (see the migration). It has no classification of its own: it is shown only to viewers who can see both
 * competitors. No mass assignment.
 *
 * @property int $id
 * @property string $ulid
 * @property int $parent_competitor_id
 * @property int $child_competitor_id
 * @property string $relation_type
 * @property CarbonImmutable $valid_from
 * @property string $valid_from_precision
 * @property ?CarbonImmutable $valid_to
 * @property ?string $notes
 * @property int $created_by
 */
class CompetitorRelationship extends Model
{
    public const TYPES = ['subsidiary', 'brand_owner', 'joint_venture', 'partner', 'distributor'];

    /** Types where A → B and B → A over the same period are different facts; for the others they conflict. */
    public const DIRECTED_TYPES = ['distributor'];

    public const PRECISIONS = ['day', 'month', 'year', 'unknown'];

    protected $guarded = ['*'];

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function involves(Competitor $competitor): bool
    {
        return in_array($competitor->id, [$this->parent_competitor_id, $this->child_competitor_id], true);
    }

    /** @return BelongsTo<Competitor, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Competitor::class, 'parent_competitor_id');
    }

    /** @return BelongsTo<Competitor, $this> */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Competitor::class, 'child_competitor_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['valid_from' => 'immutable_date', 'valid_to' => 'immutable_date'];
    }
}
