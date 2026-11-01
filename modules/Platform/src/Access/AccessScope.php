<?php

namespace Modules\Platform\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The one place that decides which records a user may see (ADR-0005, docs/07 §3, P1-15). No other code implements
 * visibility: single records go through allows() or ensureVisible(), lists through applyTo() (the `visibleTo`
 * query scope of the VisibleTo trait). Implemented by the Identity module, which owns users and grants.
 *
 * Default deny: an unknown user type or an inactive account sees nothing. Super admins get no bypass.
 * Search and vector filters follow with Phase 2 and Phase 4; record status (drafts) with the intelligence items.
 */
interface AccessScope
{
    public function allows(Authenticatable $user, Classifiable&Scoped $record): bool;

    /**
     * Limit the query to the records the user may see. The query's model must implement Classifiable and Scoped.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function applyTo(Builder $query, Authenticatable $user): Builder;

    /**
     * A hidden record does not exist for this user (docs/07 §3): 404, never 403.
     *
     * @throws NotFoundHttpException
     */
    public function ensureVisible(Authenticatable $user, Classifiable&Scoped $record): void;

    /** Cached for the rest of the request (or job); forget() after grants or team memberships change. */
    public function effectiveGrants(Authenticatable $user): GrantSet;

    public function forget(): void;
}
