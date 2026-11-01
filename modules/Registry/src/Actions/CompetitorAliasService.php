<?php

namespace Modules\Registry\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Registry\Models\Competitor;
use Modules\Registry\Models\CompetitorAlias;

/**
 * Competitor aliases (P1-21, docs/03 Registry):
 * - adding and removing need competitor.manage and a competitor the actor can see, checked again under a lock on the
 *   competitor row so a concurrent reclassification cannot slip in between;
 * - aliases are normalized (trimmed, whitespace and control characters collapsed to single spaces) and unique per
 *   competitor, ignoring case and accents (the UNIQUE index decides, so concurrent duplicates are refused too);
 * - every change is audited with the alias ID and type, never its text (audit context holds no user input), and
 *   with the competitor's classification.
 */
class CompetitorAliasService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly AccessScope $access,
        private readonly Gate $gate,
    ) {}

    public function add(Authenticatable $actor, Competitor $competitor, string $alias, string $type): CompetitorAlias
    {
        $this->assertMayManage($actor);
        $this->access->ensureVisible($actor, $competitor);
        $alias = self::normalize($alias);
        if ($alias === '' || mb_strlen($alias) > 255) {
            throw ValidationException::withMessages(['alias' => 'An alias is 1 to 255 characters.']);
        }
        if (! in_array($type, CompetitorAlias::TYPES, true)) {
            throw ValidationException::withMessages(['alias_type' => 'Unknown alias type.']);
        }

        try {
            return $this->db->transaction(function () use ($actor, $competitor, $alias, $type): CompetitorAlias {
                $locked = $this->lockVisible($actor, $competitor);
                $row = new CompetitorAlias;
                $row->forceFill(['competitor_id' => $locked->id, 'alias' => $alias, 'alias_type' => $type])->save();
                $this->audit->record('competitor.alias_added', $locked, ['alias_id' => $row->id, 'alias_type' => $type], $this->actor($actor));

                return $row;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['alias' => "{$competitor->name} already has this alias."]);
        }
    }

    public function remove(Authenticatable $actor, Competitor $competitor, CompetitorAlias $alias): void
    {
        $this->assertMayManage($actor);
        $this->access->ensureVisible($actor, $competitor);
        if ($alias->competitor_id !== $competitor->id) {
            abort(404);
        }

        $this->db->transaction(function () use ($actor, $competitor, $alias): void {
            $locked = $this->lockVisible($actor, $competitor);
            if (CompetitorAlias::query()->whereKey($alias->id)->delete() === 0) {
                return; // already removed
            }
            $this->audit->record('competitor.alias_removed', $locked, ['alias_id' => $alias->id, 'alias_type' => $alias->alias_type], $this->actor($actor));
        });
    }

    /** One rule for every caller: trim, and collapse whitespace and control characters to single spaces. */
    public static function normalize(string $alias): string
    {
        return trim((string) preg_replace('/[\s\p{Z}\p{Cc}]+/u', ' ', $alias));
    }

    private function lockVisible(Authenticatable $actor, Competitor $competitor): Competitor
    {
        $locked = Competitor::query()->whereKey($competitor->id)->sharedLock()->firstOrFail();
        $this->access->ensureVisible($actor, $locked);

        return $locked;
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
