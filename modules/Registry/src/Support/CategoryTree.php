<?php

namespace Modules\Registry\Support;

use Modules\Registry\Models\ProductCategory;

/**
 * Whole-tree integrity of the product categories (TASK-0036 #1). The table's CHECKs see one row at a time (path
 * format, depth = number of ancestors, a path ending in the parent's ID); they cannot compare a row with its parent's
 * row. This does: every category's path must be its parent's path plus the parent's ID, and its depth one more. A
 * consistent tree has no cycles (a cycle would need infinitely long paths). CategoryService checks the subtree it
 * moves before committing; `registry:verify-categories` checks everything, for rows written some other way.
 */
final class CategoryTree
{
    /**
     * IDs of inconsistent categories, in ID order: all of them, or only within the subtree rooted at $rootId.
     *
     * @return list<int>
     */
    public static function inconsistencies(?int $rootId = null): array
    {
        $rows = ProductCategory::query()->get(['id', 'parent_id', 'path', 'depth'])->keyBy('id');
        $inconsistent = [];
        foreach ($rootId === null ? $rows : $rows->only(self::subtree($rows->all(), $rootId)) as $row) {
            $parent = $row->parent_id === null ? null : $rows->get($row->parent_id);
            $expectedPath = $row->parent_id === null ? '/' : $parent?->childPath();
            $expectedDepth = $parent === null ? 0 : $parent->depth + 1;
            if ($row->path !== $expectedPath || $row->depth !== $expectedDepth) {
                $inconsistent[] = $row->id;
            }
        }
        sort($inconsistent);

        return $inconsistent;
    }

    /**
     * The root and everything below it, found through parent links, not paths: the paths are what is being checked.
     *
     * @param  array<int, ProductCategory>  $rows
     * @return list<int>
     */
    private static function subtree(array $rows, int $rootId): array
    {
        if (! isset($rows[$rootId])) {
            return [];
        }
        $children = [];
        foreach ($rows as $row) {
            $children[(int) $row->parent_id][] = $row->id;
        }
        $found = [$rootId => true];
        for ($queue = [$rootId]; $queue !== [];) {
            foreach ($children[array_shift($queue)] ?? [] as $child) {
                if (! isset($found[$child])) { // a cycle ends here
                    $found[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        return array_keys($found);
    }
}
