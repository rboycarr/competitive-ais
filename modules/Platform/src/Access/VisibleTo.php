<?php

namespace Modules\Platform\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The `visibleTo($user)` query scope for Classifiable and Scoped models (docs/01 F4). It only delegates to
 * AccessScope, so visibility stays in one place.
 */
trait VisibleTo
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, Authenticatable $user): Builder
    {
        return app(AccessScope::class)->applyTo($query, $user);
    }
}
