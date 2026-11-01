<?php

namespace Modules\Registry\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A brand (P1-22a): a competitor's, or ours when competitor_id is NULL. It has no classification of its own: our
 * brands are visible to every product.view holder, a competitor's brand only to viewers who can see the competitor.
 * No mass assignment.
 *
 * @property int $id
 * @property string $ulid
 * @property ?int $competitor_id
 * @property string $name
 */
class Brand extends Model
{
    protected $guarded = ['*'];

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Our brands, and the brands of competitors the viewer can see.
     *
     * @param  Builder<Brand>  $query
     */
    public function scopeVisibleTo(Builder $query, Authenticatable $viewer): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('competitor_id')
            ->orWhereIn('competitor_id', Competitor::query()->visibleTo($viewer)->select('id')));
    }

    /** @return BelongsTo<Competitor, $this> */
    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }
}
