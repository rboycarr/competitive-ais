<?php

namespace Modules\Registry\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Registry\Models\ProductCategory;
use Modules\Registry\Support\Names;

/**
 * The product category tree (P1-22a, docs/03 Registry), maintained by taxonomy.manage holders:
 * - names are in the registry normal form and unique among siblings, ignoring case and accents (the UNIQUE index);
 * - at most six levels (ProductCategory::MAX_DEPTH);
 * - creating locks the parent row; moving locks every category row, so tree changes run one at a time and two
 *   concurrent moves cannot build a cycle; a category never moves under itself or one of its subcategories, and its
 *   subtree's paths and depths move with it in the same transaction;
 * - only a category without subcategories can be removed (products are checked too once they exist, P1-22b);
 * - every change is audited (category IDs only).
 */
class CategoryService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly Gate $gate,
    ) {}

    public function create(Authenticatable $actor, string $name, ?int $parentId): ProductCategory
    {
        $this->assertMayManage($actor);
        $name = $this->name($name);

        try {
            return $this->db->transaction(function () use ($actor, $name, $parentId): ProductCategory {
                $parent = $parentId === null ? null : $this->parent($parentId, lock: true);
                if ($parent !== null && $parent->depth >= ProductCategory::MAX_DEPTH) {
                    throw ValidationException::withMessages(['parent' => 'Categories go at most six levels deep.']);
                }
                $category = new ProductCategory;
                $category->forceFill([
                    'parent_id' => $parent?->id,
                    'name' => $name,
                    'path' => $parent?->childPath() ?? '/',
                    'depth' => $parent === null ? 0 : $parent->depth + 1,
                ])->save();
                $this->audit->record('category.created', $category, $parent === null ? [] : ['parent_id' => $parent->id], $this->actor($actor));

                return $category;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->taken();
        }
    }

    public function rename(Authenticatable $actor, ProductCategory $category, string $name): void
    {
        $this->assertMayManage($actor);
        $name = $this->name($name);

        try {
            $this->db->transaction(function () use ($actor, $category, $name): void {
                $locked = ProductCategory::query()->whereKey($category->id)->lockForUpdate()->first() ?? abort(404);
                if ($locked->name === $name) {
                    return;
                }
                $locked->forceFill(['name' => $name])->save();
                $this->audit->record('category.renamed', $locked, [], $this->actor($actor));
                $category->setRawAttributes($locked->getAttributes(), true);
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->taken();
        }
    }

    /** @param  ?int  $parentId  the new parent; null for the top level */
    public function move(Authenticatable $actor, ProductCategory $category, ?int $parentId): void
    {
        $this->assertMayManage($actor);

        try {
            $this->db->transaction(function () use ($actor, $category, $parentId): void {
                // Every row, in ID order: tree changes run one at a time (and inserts wait behind the next-key locks).
                ProductCategory::query()->orderBy('id')->lockForUpdate()->pluck('id');
                $locked = ProductCategory::query()->find($category->id) ?? abort(404);
                $parent = $parentId === null ? null : $this->parent($parentId);
                if ($parent !== null && ($parent->id === $locked->id || str_starts_with($parent->path, $locked->childPath()))) {
                    throw ValidationException::withMessages(['parent' => 'A category cannot move under itself or one of its subcategories.']);
                }
                if ($locked->parent_id === $parent?->id) {
                    return;
                }
                $oldChildPath = $locked->childPath();
                $oldDepth = $locked->depth;
                $newDepth = $parent === null ? 0 : $parent->depth + 1;
                $deepest = (int) (ProductCategory::query()->where('path', 'like', $oldChildPath.'%')->max('depth') ?? $oldDepth);
                if ($deepest - $oldDepth + $newDepth > ProductCategory::MAX_DEPTH) {
                    throw ValidationException::withMessages(['parent' => 'Categories go at most six levels deep, subcategories included.']);
                }

                $from = $locked->parent_id;
                $locked->forceFill(['parent_id' => $parent?->id, 'path' => $parent?->childPath() ?? '/', 'depth' => $newDepth])->save();
                $moved = $this->db->update(
                    // `path` is ascii_bin (a 1000-character index fits); the bound prefix arrives as utf8mb4.
                    'UPDATE product_categories SET path = CONCAT(CONVERT(? USING ascii) COLLATE ascii_bin, SUBSTRING(path, ?)), depth = CAST(depth AS SIGNED) + ?, updated_at = ? WHERE path LIKE ?',
                    [$locked->childPath(), strlen($oldChildPath) + 1, $newDepth - $oldDepth, CarbonImmutable::now('UTC'), $oldChildPath.'%'],
                );
                $this->audit->record('category.moved', $locked, array_filter([
                    'from_parent' => $from, 'to_parent' => $parent?->id,
                ]) + ['subcategories' => $moved], $this->actor($actor));
                $category->setRawAttributes($locked->getAttributes(), true);
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->taken();
        }
    }

    public function remove(Authenticatable $actor, ProductCategory $category): void
    {
        $this->assertMayManage($actor);

        $this->db->transaction(function () use ($actor, $category): void {
            $locked = ProductCategory::query()->whereKey($category->id)->lockForUpdate()->first();
            if ($locked === null) {
                return; // already removed
            }
            if (ProductCategory::query()->where('parent_id', $locked->id)->sharedLock()->exists()) {
                throw ValidationException::withMessages(['category' => 'Move or remove its subcategories first.']);
            }
            $locked->delete();
            $this->audit->record('category.removed', $locked, $locked->parent_id === null ? [] : ['parent_id' => $locked->parent_id], $this->actor($actor));
        });
    }

    private function parent(int $id, bool $lock = false): ProductCategory
    {
        $query = ProductCategory::query()->whereKey($id);

        return ($lock ? $query->lockForUpdate() : $query)->first()
            ?? throw ValidationException::withMessages(['parent' => 'Choose an existing category.']);
    }

    private function name(string $name): string
    {
        $name = Names::normalize($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'A category name is 1 to 255 characters.']);
        }

        return $name;
    }

    private function taken(): ValidationException
    {
        return ValidationException::withMessages(['name' => 'A category with this name already exists at that place in the tree.']);
    }

    private function assertMayManage(Authenticatable $actor): void
    {
        if (! $this->gate->forUser($actor)->allows('taxonomy.manage')) {
            throw new AuthorizationException('You are not allowed to manage categories.');
        }
    }

    private function actor(Authenticatable $actor): Actor
    {
        return Actor::user((int) $actor->getAuthIdentifier());
    }
}
