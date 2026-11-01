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
 * team, and opens at least the record's classification. Inactive accounts see nothing, whatever their grants
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
        $readable = array_values(array_filter(Classification::cases(), fn (Classification $c): bool => $user->clearance->isAtLeast($c)));
        // Unscoped records: up to the clearance, restricted only with a grant. Scoped records: only with a grant.
        $unscoped = array_values(array_filter($readable, fn (Classification $c): bool => $c !== Classification::Restricted || $grants->covers($c)));
        $scoped = array_values(array_filter($readable, fn (Classification $c): bool => $grants->covers($c)));
        $column = $model->qualifyColumn('classification');
        $values = fn (array $cases): array => array_map(fn (Classification $c): string => $c->value, $cases);

        return $query->where(function (Builder $visible) use ($model, $column, $values, $unscoped, $scoped): void {
            $visible->whereIn($column, $values($scoped))
                ->orWhere(function (Builder $q) use ($model, $column, $values, $unscoped): void {
                    $q->whereIn($column, $values($unscoped));
                    foreach ($model::scopeRelations() as $relation) {
                        $q->whereDoesntHave($relation);
                    }
                });
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

        return $this->grants[$user->id] ??= new GrantSet(
            AccessGrant::query()->active()->heldBy($user)->where('scope_type', AccessGrant::SCOPE_ALL)
                ->get(['max_classification'])
                ->map(fn (AccessGrant $grant): Classification => $grant->max_classification)
                ->reduce(fn (?Classification $max, Classification $c): Classification => $max !== null && $max->isAtLeast($c) ? $max : $c),
        );
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
