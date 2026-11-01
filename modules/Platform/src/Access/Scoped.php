<?php

namespace Modules\Platform\Access;

/**
 * A record that may be about competitors and markets (docs/07 §3, all-of semantics). A record with neither is
 * unscoped. The scopes live in pivot tables reached through the relations named by scopeRelations(), so that
 * AccessScope::applyTo() can check them in SQL.
 */
interface Scoped
{
    /** @return list<int> */
    public function competitorIds(): array;

    /** @return list<int> */
    public function marketIds(): array;

    /**
     * The scope pivots on the Eloquent model: for 'competitor' and 'market' (each optional), the relation name and
     * the column on the pivot that holds the competitor or market ID (P1-20a).
     *
     * @return array<'competitor'|'market', array{0: string, 1: string}>
     */
    public static function scopeRelations(): array;
}
