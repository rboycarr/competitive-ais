<?php

namespace Modules\Platform\Access;

/**
 * A record that may be about competitors and markets (docs/07 §3, all-of semantics). A record with neither is
 * unscoped. The scopes live in pivot tables reached through the relations named by scopeRelations(), so that
 * AccessScope::applyTo() can tell scoped from unscoped records in SQL.
 */
interface Scoped
{
    /** @return list<int> */
    public function competitorIds(): array;

    /** @return list<int> */
    public function marketIds(): array;

    /**
     * Relation names (on the Eloquent model) of the competitor and market scope pivots.
     *
     * @return list<string>
     */
    public static function scopeRelations(): array;
}
