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
use Modules\Registry\Models\Market;
use Modules\Registry\Models\Region;
use Modules\Registry\Support\Names;

/**
 * Regions of a market (P1-23a, docs/03 Registry), maintained by taxonomy.manage holders:
 * - a region sits at the top of its market or under a region of the same market at a shallower level
 *   (region › province › city › district); the database enforces the same (composite key and CHECK);
 * - names are in the registry normal form and unique among siblings, ignoring case and accents;
 * - the level and place are fixed; a name can change; only a region without subregions is removed;
 * - every change is audited (IDs and codes only).
 */
class RegionService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly Gate $gate,
    ) {}

    public function create(Authenticatable $actor, Market $market, string $name, string $level, ?int $parentId): Region
    {
        $this->assertMayManage($actor);
        $name = $this->name($name);
        $rank = Region::LEVELS[$level] ?? throw ValidationException::withMessages(['level' => 'Unknown level.']);

        try {
            return $this->db->transaction(function () use ($actor, $market, $name, $level, $rank, $parentId): Region {
                $parent = $parentId === null ? null : (Region::query()->whereKey($parentId)->where('market_id', $market->id)->lockForUpdate()->first()
                    ?? throw ValidationException::withMessages(['parent' => 'Choose a region of this market.']));
                if ($parent !== null && $rank <= $parent->level_rank) {
                    throw ValidationException::withMessages(['level' => "A $level cannot be part of a {$parent->level}."]);
                }
                $region = new Region;
                $region->forceFill([
                    'market_id' => $market->id, 'parent_id' => $parent?->id, 'name' => $name, 'level' => $level, 'parent_level_rank' => $parent?->level_rank,
                ])->save();
                $this->audit->record('region.created', $region, ['market' => $market->ulid, 'level' => $level] + ($parent === null ? [] : ['parent_id' => $parent->id]), $this->actor($actor));

                return $region;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->taken();
        }
    }

    public function rename(Authenticatable $actor, Market $market, Region $region, string $name): void
    {
        $this->assertMayManage($actor);
        $this->assertBelongs($market, $region);
        $name = $this->name($name);

        try {
            $this->db->transaction(function () use ($actor, $market, $region, $name): void {
                $locked = Region::query()->whereKey($region->id)->lockForUpdate()->first() ?? abort(404);
                if ($locked->name === $name) {
                    return;
                }
                $locked->forceFill(['name' => $name])->save();
                $this->audit->record('region.renamed', $locked, ['market' => $market->ulid], $this->actor($actor));
                $region->setRawAttributes($locked->getAttributes(), true);
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->taken();
        }
    }

    public function remove(Authenticatable $actor, Market $market, Region $region): void
    {
        $this->assertMayManage($actor);
        $this->assertBelongs($market, $region);

        $this->db->transaction(function () use ($actor, $market, $region): void {
            $locked = Region::query()->whereKey($region->id)->lockForUpdate()->first();
            if ($locked === null) {
                return; // already removed
            }
            if (Region::query()->where('parent_id', $locked->id)->sharedLock()->exists()) {
                throw ValidationException::withMessages(['region' => 'Remove its subregions first.']);
            }
            $locked->delete();
            $this->audit->record('region.removed', $locked, ['market' => $market->ulid, 'level' => $locked->level]
                + ($locked->parent_id === null ? [] : ['parent_id' => $locked->parent_id]), $this->actor($actor));
        });
    }

    private function name(string $name): string
    {
        $name = Names::normalize($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'A region name is 1 to 255 characters.']);
        }

        return $name;
    }

    private function assertBelongs(Market $market, Region $region): void
    {
        if ($region->market_id !== $market->id) {
            abort(404);
        }
    }

    private function taken(): ValidationException
    {
        return ValidationException::withMessages(['name' => 'A region with this name already exists at that place.']);
    }

    private function assertMayManage(Authenticatable $actor): void
    {
        if (! $this->gate->forUser($actor)->allows('taxonomy.manage')) {
            throw new AuthorizationException('You are not allowed to manage regions.');
        }
    }

    private function actor(Authenticatable $actor): Actor
    {
        return Actor::user((int) $actor->getAuthIdentifier());
    }
}
