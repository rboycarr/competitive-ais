<?php

namespace Modules\Registry\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Classification;
use Modules\Registry\Models\Competitor;
use Modules\Registry\Models\CompetitorRelationship;

/**
 * Corporate relationships (P1-21, docs/03 Registry):
 * - adding, editing and removing need competitor.manage and both competitors visible to the actor (the other one is
 *   looked up within AccessScope, so a hidden competitor is "not found", never named);
 * - no self-relationship; valid_from is stored as the start of its precision period, valid_to (inclusive) not before it;
 * - validity ranges for the same pair and type must not overlap, in either direction unless the type is directed.
 *   Checked under exclusive locks on both competitor rows, taken in ID order, so concurrent writes for a pair queue;
 * - every change is audited with the higher classification of the two competitors. Edits change the period and
 *   notes only; the pair and type stay fixed (remove and add to change them).
 */
class CompetitorRelationshipService
{
    private const FIELDS = ['valid_from', 'valid_from_precision', 'valid_to', 'notes'];

    /**
     * The overlap read takes shared gap locks, so two writers on different pairs that sit next to each other in the
     * index can deadlock on their inserts; MySQL aborts one, and the transaction is retried from the start.
     */
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly AccessScope $access,
        private readonly Gate $gate,
    ) {}

    /**
     * @param  string  $role  'parent' or 'child': the role of $competitor in the relationship
     * @param  array<string, ?string>  $data  relation_type, valid_from, valid_from_precision (default day), valid_to, notes
     */
    public function add(Authenticatable $actor, Competitor $competitor, string $role, string $otherUlid, array $data): CompetitorRelationship
    {
        $this->assertMayManage($actor);
        $this->access->ensureVisible($actor, $competitor);
        $other = Competitor::query()->visibleTo($actor)->where('ulid', strtoupper(trim($otherUlid)))->first()
            ?? throw ValidationException::withMessages(['other' => 'Choose a competitor you can see.']);
        if ($other->id === $competitor->id) {
            throw ValidationException::withMessages(['other' => 'A competitor cannot be related to itself.']);
        }
        if (! in_array($role, ['parent', 'child'], true)) {
            throw ValidationException::withMessages(['role' => 'Unknown role.']);
        }
        $type = (string) ($data['relation_type'] ?? '');
        if (! in_array($type, CompetitorRelationship::TYPES, true)) {
            throw ValidationException::withMessages(['relation_type' => 'Unknown relationship type.']);
        }
        [$parentId, $childId] = $role === 'parent' ? [$competitor->id, $other->id] : [$other->id, $competitor->id];
        $attributes = $this->period($data);

        try {
            return $this->db->transaction(function () use ($actor, $parentId, $childId, $type, $attributes): CompetitorRelationship {
                [$parent, $child] = $this->lockPair($actor, $parentId, $childId);
                $this->assertNoOverlap($parentId, $childId, $type, $attributes['valid_from'], $attributes['valid_to']);

                $relationship = new CompetitorRelationship;
                $relationship->forceFill($attributes + [
                    'ulid' => (string) Str::ulid(),
                    'parent_competitor_id' => $parentId,
                    'child_competitor_id' => $childId,
                    'relation_type' => $type,
                    'created_by' => $actor->getAuthIdentifier(),
                ])->save();
                $this->audit->record('competitor.relationship_added', $relationship,
                    ['parent' => $parent->ulid, 'child' => $child->ulid, 'relation_type' => $type], $this->actor($actor), $this->classification($parent, $child));

                return $relationship;
            }, self::ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            throw $this->overlapping($type); // a concurrent insert with the same start date got there first
        }
    }

    /**
     * @param  array<string, ?string>  $data  valid_from, valid_from_precision (default day), valid_to, notes
     */
    public function update(Authenticatable $actor, Competitor $competitor, CompetitorRelationship $relationship, array $data): void
    {
        $this->assertCanReach($actor, $competitor, $relationship);
        $attributes = $this->period($data);

        try {
            $this->db->transaction(function () use ($actor, $relationship, $attributes): void {
                [$parent, $child] = $this->lockPair($actor, $relationship->parent_competitor_id, $relationship->child_competitor_id);
                $locked = CompetitorRelationship::query()->whereKey($relationship->id)->lockForUpdate()->first() ?? abort(404);
                $locked->forceFill($attributes);
                $changed = array_values(array_intersect(self::FIELDS, array_keys($locked->getDirty())));
                if ($changed === []) {
                    return;
                }
                $this->assertNoOverlap($locked->parent_competitor_id, $locked->child_competitor_id, $locked->relation_type, $locked->valid_from, $locked->valid_to, $locked->id);

                $locked->save();
                $this->audit->record('competitor.relationship_updated', $locked, ['fields' => $changed], $this->actor($actor), $this->classification($parent, $child));
                $relationship->setRawAttributes($locked->getAttributes(), true);
            }, self::ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            throw $this->overlapping($relationship->relation_type);
        }
    }

    public function remove(Authenticatable $actor, Competitor $competitor, CompetitorRelationship $relationship): void
    {
        $this->assertCanReach($actor, $competitor, $relationship);

        $this->db->transaction(function () use ($actor, $relationship): void {
            [$parent, $child] = $this->lockPair($actor, $relationship->parent_competitor_id, $relationship->child_competitor_id);
            if (CompetitorRelationship::query()->whereKey($relationship->id)->delete() === 0) {
                return; // already removed
            }
            $this->audit->record('competitor.relationship_removed', $relationship,
                ['parent' => $parent->ulid, 'child' => $child->ulid, 'relation_type' => $relationship->relation_type], $this->actor($actor), $this->classification($parent, $child));
        });
    }

    /**
     * @param  array<string, ?string>  $data
     * @return array{valid_from: CarbonImmutable, valid_from_precision: string, valid_to: ?CarbonImmutable, notes: ?string}
     */
    private function period(array $data): array
    {
        $precision = $data['valid_from_precision'] ?? 'day';
        if (! in_array($precision, CompetitorRelationship::PRECISIONS, true)) {
            throw ValidationException::withMessages(['valid_from_precision' => 'Unknown precision.']);
        }
        $from = $this->date($data['valid_from'] ?? null, 'valid_from') ?? throw ValidationException::withMessages(['valid_from' => 'A start date is required.']);
        $from = match ($precision) {
            'month' => $from->startOfMonth(),
            'year' => $from->startOfYear(),
            default => $from,
        };
        $to = $this->date($data['valid_to'] ?? null, 'valid_to');
        if ($to !== null && $to->lt($from)) {
            throw ValidationException::withMessages(['valid_to' => 'The end date cannot be before the start date.']);
        }
        $notes = trim((string) ($data['notes'] ?? ''));

        return ['valid_from' => $from, 'valid_from_precision' => $precision, 'valid_to' => $to, 'notes' => $notes === '' ? null : $notes];
    }

    private function date(?string $value, string $field): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 1800 || (int) $m[1] > 2199) {
            throw ValidationException::withMessages([$field => 'Enter a date between 1800 and 2199 as YYYY-MM-DD.']);
        }

        return CarbonImmutable::parse($value, 'UTC')->startOfDay();
    }

    /**
     * Ranges are inclusive; an open end runs forever. A locking read, so it sees rows committed by whoever held the
     * competitor locks before us, whatever the transaction's snapshot.
     */
    private function assertNoOverlap(int $parentId, int $childId, string $type, CarbonImmutable $from, ?CarbonImmutable $to, ?int $except = null): void
    {
        $clash = CompetitorRelationship::query()
            ->where('relation_type', $type)
            ->where(function (Builder $pair) use ($parentId, $childId, $type): void {
                $pair->where(fn (Builder $q) => $q->where('parent_competitor_id', $parentId)->where('child_competitor_id', $childId));
                if (! in_array($type, CompetitorRelationship::DIRECTED_TYPES, true)) {
                    $pair->orWhere(fn (Builder $q) => $q->where('parent_competitor_id', $childId)->where('child_competitor_id', $parentId));
                }
            })
            ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except))
            ->where('valid_from', '<=', $to?->toDateString() ?? '9999-12-31')
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $from->toDateString()))
            ->sharedLock()
            ->exists();
        if ($clash) {
            throw $this->overlapping($type);
        }
    }

    private function overlapping(string $type): ValidationException
    {
        return ValidationException::withMessages(['valid_from' => 'These dates overlap another '.str_replace('_', ' ', $type).' relationship between these competitors.']);
    }

    /**
     * Both competitors, locked for update in ID order (no deadlocks between pairs) and visible to the actor now.
     *
     * @return array{0: Competitor, 1: Competitor} parent, child
     */
    private function lockPair(Authenticatable $actor, int $parentId, int $childId): array
    {
        $locked = Competitor::query()->whereKey([$parentId, $childId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $parent = $locked->get($parentId) ?? abort(404);
        $child = $locked->get($childId) ?? abort(404);
        $this->access->ensureVisible($actor, $parent);
        $this->access->ensureVisible($actor, $child);

        return [$parent, $child];
    }

    private function assertCanReach(Authenticatable $actor, Competitor $competitor, CompetitorRelationship $relationship): void
    {
        $this->assertMayManage($actor);
        $this->access->ensureVisible($actor, $competitor);
        if (! $relationship->involves($competitor)) {
            abort(404);
        }
    }

    private function classification(Competitor $parent, Competitor $child): Classification
    {
        return $parent->classification->isAtLeast($child->classification) ? $parent->classification : $child->classification;
    }

    private function assertMayManage(Authenticatable $actor): void
    {
        if (! $this->gate->forUser($actor)->allows('competitor.manage')) {
            throw new AuthorizationException('You are not allowed to manage competitors.');
        }
    }

    private function actor(Authenticatable $actor): Actor
    {
        return Actor::user((int) $actor->getAuthIdentifier());
    }
}
