<?php

namespace Modules\Identity\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use LogicException;
use Modules\Identity\Models\AccessGrant;
use Modules\Identity\Models\User;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Access\Classifiable;
use Modules\Platform\Access\GrantSet;
use Modules\Platform\Access\Scoped;
use Modules\Platform\Classification;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Visibility from clearance and access grants (docs/07 §3, P1-15):
 *
 *   visible(user, record) := user is active
 *                        AND record.classification ≤ user.clearance
 *                        AND (record.classification ≠ restricted OR grants cover it)
 *                        AND (record is unscoped OR grants cover it, for every competitor and market on it)
 *
 * A grant covers a record when it is active (neither revoked nor expired), held by the user directly or through a
 * team, and opens at least the record's classification: an `all` grant covers any record, a competitor grant the
 * records about that competitor (every competitor on a record must be covered; P1-20a). Inactive accounts see nothing, whatever their grants
 * (TASK-0025). Registered per request (scoped binding), so the grant cache never outlives a request or a job.
 */
class GrantAccessScope implements AccessScope
{
    /** @var array<int, GrantSet> user ID => grants, for this request */
    private array $grants = [];

    public function allows(Authenticatable $user, Classifiable&Scoped $record): bool
    {
        if (! $this->isActiveUser($user)) {
            return false;
        }

        $classification = $record->classification();
        if (! $user->clearance->isAtLeast($classification)) {
            return false;
        }

        $competitors = $record->competitorIds();
        $markets = $record->marketIds();
        $needsGrant = $classification === Classification::Restricted || $competitors !== [] || $markets !== [];

        return ! $needsGrant || $this->effectiveGrants($user)->covers($classification, $competitors, $markets);
    }

    public function applyTo(Builder $query, Authenticatable $user): Builder
    {
        $model = $query->getModel();
        if (! $model instanceof Classifiable || ! $model instanceof Scoped) {
            throw new LogicException($model::class.' must implement Classifiable and Scoped to be filtered by visibility.');
        }
        // Conditions already on the query go into their own group first, so an earlier orWhere cannot bypass the
        // filter ("a OR b AND visible" would show a). The visibleTo scope gets this from Eloquent; direct calls here.
        $base = $query->getQuery();
        if ($base->wheres !== []) {
            $existing = $base->forNestedWhere();
            $existing->wheres = $base->wheres;
            $existing->bindings['where'] = $base->bindings['where'];
            $base->wheres = [];
            $base->bindings['where'] = [];
            $base->addNestedWhereQuery($existing);
        }

        if (! $this->isActiveUser($user)) {
            return $query->whereRaw('1 = 0');
        }

        $grants = $this->effectiveGrants($user);
        $column = $model->qualifyColumn('classification');
        $relations = $model::scopeRelations();

        // One branch per classification the user may read. Within it: everything if an `all` grant opens that level;
        // otherwise no market scope, every competitor scope covered by a competitor grant (all-of), and a restricted
        // record must have at least one (its explicit grant). Unscoped records below restricted need no grant.
        return $query->where(function (Builder $visible) use ($user, $grants, $column, $relations): void {
            $visible->whereRaw('1 = 0'); // nothing unless a branch below matches
            foreach (Classification::cases() as $level) {
                if (! $user->clearance->isAtLeast($level)) {
                    continue;
                }
                $visible->orWhere(function (Builder $q) use ($grants, $column, $relations, $level): void {
                    $q->where($column, $level->value);
                    if ($grants->coversAll($level)) {
                        return;
                    }
                    if (isset($relations['market'])) {
                        $q->whereDoesntHave($relations['market'][0]);
                    }
                    $covered = $grants->competitorsCovering($level);
                    if (isset($relations['competitor'])) {
                        [$relation, $idColumn] = $relations['competitor'];
                        $q->whereDoesntHave($relation, fn (Builder $scope) => $covered === [] ? $scope : $scope->whereNotIn($idColumn, $covered));
                        if ($level === Classification::Restricted) {
                            $covered === [] ? $q->whereRaw('1 = 0') : $q->whereHas($relation);
                        }
                    } elseif ($level === Classification::Restricted) {
                        $q->whereRaw('1 = 0');
                    }
                });
            }
        });
    }

    public function ensureVisible(Authenticatable $user, Classifiable&Scoped $record): void
    {
        if (! $this->allows($user, $record)) {
            throw new NotFoundHttpException;
        }
    }

    public function effectiveGrants(Authenticatable $user): GrantSet
    {
        if (! $this->isActiveUser($user)) {
            return GrantSet::none();
        }

        if (isset($this->grants[$user->id])) {
            return $this->grants[$user->id];
        }

        $all = null;
        $competitors = [];
        $higher = fn (?Classification $a, Classification $b): Classification => $a !== null && $a->isAtLeast($b) ? $a : $b;
        foreach (AccessGrant::query()->active()->heldBy($user)->get(['scope_type', 'competitor_id', 'max_classification']) as $grant) {
            match ($grant->scope_type) {
                AccessGrant::SCOPE_ALL => $all = $higher($all, $grant->max_classification),
                AccessGrant::SCOPE_COMPETITOR => $competitors[(int) $grant->competitor_id] = $higher($competitors[(int) $grant->competitor_id] ?? null, $grant->max_classification),
                default => null, // a scope this code does not know opens nothing
            };
        }

        return $this->grants[$user->id] = new GrantSet($all, $competitors);
    }

    public function forget(): void
    {
        $this->grants = [];
    }

    /** @phpstan-assert-if-true User $user */
    private function isActiveUser(Authenticatable $user): bool
    {
        return $user instanceof User && $user->status === 'active';
    }
}
