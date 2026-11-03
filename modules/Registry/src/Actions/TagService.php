<?php

namespace Modules\Registry\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Database\UniqueViolation;
use Modules\Registry\Models\Tag;
use Modules\Registry\Support\Names;

/**
 * Tags (P1-23b, docs/03 Registry), maintained by taxonomy.manage holders: a name in the registry normal form within a
 * taxonomy (a lower-case code), unique there ignoring case and accents. Both can change. A tag is removed only while
 * nothing carries it: the tag row is locked first, and tagging takes a shared lock on it through the foreign key, so
 * no link can appear in between; the message names nothing the remover may not see. Every change is audited.
 */
class TagService
{
    private const FIELDS = ['taxonomy', 'name'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly Gate $gate,
    ) {}

    public function create(Authenticatable $actor, string $taxonomy, string $name): Tag
    {
        $this->assertMayManage($actor);
        $attributes = $this->attributes($taxonomy, $name);

        try {
            return $this->db->transaction(function () use ($actor, $attributes): Tag {
                $tag = new Tag;
                $tag->forceFill($attributes)->save();
                $this->audit->record('tag.created', $tag, ['taxonomy' => $tag->taxonomy], $this->actor($actor));

                return $tag;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw UniqueViolation::on($e, 'tags', 'tags_taxonomy_name_unique') ? $this->taken() : $e;
        }
    }

    public function update(Authenticatable $actor, Tag $tag, string $taxonomy, string $name): void
    {
        $this->assertMayManage($actor);
        $attributes = $this->attributes($taxonomy, $name);

        try {
            $this->db->transaction(function () use ($actor, $tag, $attributes): void {
                $locked = Tag::query()->whereKey($tag->id)->lockForUpdate()->first() ?? abort(404);
                $locked->forceFill($attributes);
                $changed = array_values(array_intersect(self::FIELDS, array_keys($locked->getDirty())));
                if ($changed === []) {
                    return;
                }
                $locked->save();
                $this->audit->record('tag.updated', $locked, ['fields' => $changed], $this->actor($actor));
                $tag->setRawAttributes($locked->getAttributes(), true);
            });
        } catch (UniqueConstraintViolationException $e) {
            throw UniqueViolation::on($e, 'tags', 'tags_taxonomy_name_unique') ? $this->taken() : $e;
        }
    }

    public function remove(Authenticatable $actor, Tag $tag): void
    {
        $this->assertMayManage($actor);

        $this->db->transaction(function () use ($actor, $tag): void {
            $locked = Tag::query()->whereKey($tag->id)->lockForUpdate()->first();
            if ($locked === null) {
                return; // already removed
            }
            if ($locked->inUse()) {
                throw ValidationException::withMessages(['tag' => 'This tag is in use; it can be removed once nothing carries it.']);
            }
            $locked->delete();
            $this->audit->record('tag.removed', $locked, ['taxonomy' => $locked->taxonomy], $this->actor($actor));
        });
    }

    /** @return array{taxonomy: string, name: string} */
    private function attributes(string $taxonomy, string $name): array
    {
        $taxonomy = strtolower(trim($taxonomy));
        if (preg_match('/^[a-z][a-z0-9_]{0,49}$/', $taxonomy) !== 1) {
            throw ValidationException::withMessages(['taxonomy' => 'A taxonomy is 1 to 50 lower-case letters, digits or "_", starting with a letter.']);
        }
        $name = Names::normalize($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'A tag name is 1 to 255 characters.']);
        }

        return ['taxonomy' => $taxonomy, 'name' => $name];
    }

    private function taken(): ValidationException
    {
        return ValidationException::withMessages(['name' => 'This taxonomy already has a tag with this name.']);
    }

    private function assertMayManage(Authenticatable $actor): void
    {
        if (! $this->gate->forUser($actor)->allows('taxonomy.manage')) {
            throw new AuthorizationException('You are not allowed to manage tags.');
        }
    }

    private function actor(Authenticatable $actor): Actor
    {
        return Actor::user((int) $actor->getAuthIdentifier());
    }
}
