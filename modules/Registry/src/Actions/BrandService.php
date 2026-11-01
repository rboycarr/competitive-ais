<?php

namespace Modules\Registry\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Classification;
use Modules\Registry\Models\Brand;
use Modules\Registry\Models\Competitor;
use Modules\Registry\Support\Names;

/**
 * Brands (P1-22a, docs/03 Registry):
 * - adding, renaming and removing need product.manage; a competitor's brand also needs the competitor visible to the
 *   actor, checked again under a shared lock on the competitor row (a hidden owner is "not found", never named);
 * - names are in the registry normal form and unique per owner, ignoring case and accents (the UNIQUE index decides);
 * - the owner never changes; every change is audited with IDs and codes and the owner's classification (ours:
 *   internal). Removing a brand that products use is refused once products exist (P1-22b).
 */
class BrandService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly AccessScope $access,
        private readonly Gate $gate,
    ) {}

    /** @param  ?string  $competitorUlid  the owner; null for our own brand */
    public function create(Authenticatable $actor, ?string $competitorUlid, string $name): Brand
    {
        $this->assertMayManage($actor);
        $name = $this->name($name);
        $competitor = null;
        if (trim((string) $competitorUlid) !== '') {
            $competitor = Competitor::query()->visibleTo($actor)->where('ulid', strtoupper(trim((string) $competitorUlid)))->first()
                ?? throw ValidationException::withMessages(['competitor' => 'Choose a competitor you can see.']);
        }

        try {
            return $this->db->transaction(function () use ($actor, $competitor, $name): Brand {
                $owner = $competitor === null ? null : $this->lockVisible($actor, $competitor);
                $brand = new Brand;
                $brand->forceFill(['ulid' => (string) Str::ulid(), 'competitor_id' => $owner?->id, 'name' => $name])->save();
                $this->audit->record('brand.created', $brand, $this->context($owner), $this->actor($actor), $this->classification($owner));

                return $brand;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->taken($competitor);
        }
    }

    public function rename(Authenticatable $actor, Brand $brand, string $name): void
    {
        $this->assertMayManage($actor);
        $owner = $this->owner($actor, $brand);
        $name = $this->name($name);

        try {
            $this->db->transaction(function () use ($actor, $brand, $owner, $name): void {
                $owner = $owner === null ? null : $this->lockVisible($actor, $owner);
                $locked = Brand::query()->whereKey($brand->id)->lockForUpdate()->first() ?? abort(404);
                if ($locked->name === $name) {
                    return;
                }
                $locked->forceFill(['name' => $name])->save();
                $this->audit->record('brand.renamed', $locked, $this->context($owner), $this->actor($actor), $this->classification($owner));
                $brand->setRawAttributes($locked->getAttributes(), true);
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->taken($owner);
        }
    }

    public function remove(Authenticatable $actor, Brand $brand): void
    {
        $this->assertMayManage($actor);
        $owner = $this->owner($actor, $brand);

        $this->db->transaction(function () use ($actor, $brand, $owner): void {
            $owner = $owner === null ? null : $this->lockVisible($actor, $owner);
            if (Brand::query()->whereKey($brand->id)->delete() === 0) {
                return; // already removed
            }
            $this->audit->record('brand.removed', $brand, $this->context($owner), $this->actor($actor), $this->classification($owner));
        });
    }

    private function name(string $name): string
    {
        $name = Names::normalize($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'A brand name is 1 to 255 characters.']);
        }

        return $name;
    }

    /** The owning competitor, if any; a brand of a competitor the actor cannot see is "not found". */
    private function owner(Authenticatable $actor, Brand $brand): ?Competitor
    {
        if ($brand->competitor_id === null) {
            return null;
        }
        $competitor = Competitor::query()->find($brand->competitor_id) ?? abort(404);
        $this->access->ensureVisible($actor, $competitor);

        return $competitor;
    }

    private function lockVisible(Authenticatable $actor, Competitor $competitor): Competitor
    {
        $locked = Competitor::query()->whereKey($competitor->id)->sharedLock()->firstOrFail();
        $this->access->ensureVisible($actor, $locked);

        return $locked;
    }

    /** @return array<string, string> */
    private function context(?Competitor $owner): array
    {
        return $owner === null ? ['owner' => 'own'] : ['owner' => 'competitor', 'competitor' => $owner->ulid];
    }

    private function classification(?Competitor $owner): Classification
    {
        return $owner === null ? Classification::Internal : $owner->classification;
    }

    private function taken(?Competitor $owner): ValidationException
    {
        return ValidationException::withMessages(['name' => $owner === null ? 'We already have a brand with this name.' : "{$owner->name} already has a brand with this name."]);
    }

    private function assertMayManage(Authenticatable $actor): void
    {
        if (! $this->gate->forUser($actor)->allows('product.manage')) {
            throw new AuthorizationException('You are not allowed to manage brands.');
        }
    }

    private function actor(Authenticatable $actor): Actor
    {
        return Actor::user((int) $actor->getAuthIdentifier());
    }
}
