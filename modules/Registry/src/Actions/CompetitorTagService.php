<?php

namespace Modules\Registry\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Database\UniqueViolation;
use Modules\Registry\Models\Competitor;
use Modules\Registry\Models\Tag;

/**
 * Tagging competitors (P1-23b, docs/03 Registry):
 * - needs competitor.manage and a competitor the actor can see, checked again under a lock on the competitor row;
 * - the tag is read under a shared lock, so it cannot be removed until the link commits;
 * - tagging twice and untagging an untagged competitor change nothing and are not audited; the primary key decides
 *   between concurrent duplicates, without a gap lock, so parallel tagging cannot deadlock (P1-25);
 * - every change is audited on the competitor (its classification) with the tag ID and taxonomy, never the name.
 */
class CompetitorTagService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly AccessScope $access,
        private readonly Gate $gate,
    ) {}

    public function tag(Authenticatable $actor, Competitor $competitor, int $tagId): void
    {
        $this->assertMayManage($actor);
        $this->access->ensureVisible($actor, $competitor);

        try {
            $this->db->transaction(function () use ($actor, $competitor, $tagId): void {
                $locked = $this->lockVisible($actor, $competitor);
                $tag = Tag::query()->whereKey($tagId)->sharedLock()->first()
                    ?? throw ValidationException::withMessages(['tag' => 'Choose a listed tag.']);
                // A plain read, not a locking one: a locking read of a missing link takes a gap lock, and two requests
                // tagging different competitors with this tag then deadlock on their inserts (P1-25). The primary key
                // refuses a concurrent duplicate, which the catch below treats as already tagged.
                if ($this->links($tag, $locked)->exists()) {
                    return;
                }
                $this->links($tag, $locked)->insert(['tag_id' => $tag->id, 'taggable_type' => Tag::COMPETITOR, 'taggable_id' => $locked->id, 'created_at' => now('UTC')]);
                $this->audit->record('competitor.tagged', $locked, ['tag_id' => $tag->id, 'taxonomy' => $tag->taxonomy], $this->actor($actor));
            });
        } catch (UniqueConstraintViolationException $e) {
            // Tagged by a concurrent request: the outcome is the same, and that request audited it. Any other duplicate
            // is a different failure.
            if (! UniqueViolation::on($e, 'taggables', 'PRIMARY')) {
                throw $e;
            }
        }
    }

    public function untag(Authenticatable $actor, Competitor $competitor, Tag $tag): void
    {
        $this->assertMayManage($actor);
        $this->access->ensureVisible($actor, $competitor);

        $this->db->transaction(function () use ($actor, $competitor, $tag): void {
            $locked = $this->lockVisible($actor, $competitor);
            if ($this->links($tag, $locked)->delete() === 0) {
                return; // not tagged
            }
            $this->audit->record('competitor.untagged', $locked, ['tag_id' => $tag->id, 'taxonomy' => $tag->taxonomy], $this->actor($actor));
        });
    }

    private function links(Tag $tag, Competitor $competitor): Builder
    {
        return $this->db->table('taggables')->where('tag_id', $tag->id)->where('taggable_type', Tag::COMPETITOR)->where('taggable_id', $competitor->id);
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
