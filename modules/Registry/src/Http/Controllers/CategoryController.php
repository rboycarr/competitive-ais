<?php

namespace Modules\Registry\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Registry\Actions\CategoryService;
use Modules\Registry\Models\ProductCategory;

/** Admin › Categories (P1-22a): the product category tree for taxonomy.manage holders; CategoryService checks again. */
class CategoryController
{
    public function __construct(private readonly CategoryService $categories) {}

    public function index(): Response
    {
        $all = ProductCategory::query()->get(['id', 'parent_id', 'name', 'path', 'depth']);
        $children = $all->groupBy(fn (ProductCategory $c): int => (int) $c->parent_id);
        $rows = [];
        // Depth first, siblings by name, so the page can indent by depth.
        $walk = function (int $parentKey) use (&$walk, &$rows, $children): void {
            foreach ($children->get($parentKey, collect())->sortBy(fn (ProductCategory $c): string => mb_strtolower($c->name)) as $category) {
                $rows[] = ['id' => $category->id, 'parentId' => $category->parent_id, 'name' => $category->name, 'depth' => $category->depth, 'path' => $category->path];
                $walk($category->id);
            }
        };
        $walk(0);

        return Inertia::render('Registry/Admin/Categories', ['categories' => $rows, 'maxDepth' => ProductCategory::MAX_DEPTH]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent' => ['nullable', 'integer', 'min:1'],
        ]);
        $this->categories->create($request->user() ?? abort(401), (string) $validated['name'], isset($validated['parent']) ? (int) $validated['parent'] : null);

        return back()->with('status', 'Category added.');
    }

    public function update(Request $request, ProductCategory $category): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $this->categories->rename($request->user() ?? abort(401), $category, (string) $validated['name']);

        return back()->with('status', 'Category renamed.');
    }

    public function move(Request $request, ProductCategory $category): RedirectResponse
    {
        $validated = $request->validate(['parent' => ['nullable', 'integer', 'min:1']]);
        $this->categories->move($request->user() ?? abort(401), $category, isset($validated['parent']) ? (int) $validated['parent'] : null);

        return back()->with('status', 'Category moved.');
    }

    public function destroy(Request $request, ProductCategory $category): RedirectResponse
    {
        $this->categories->remove($request->user() ?? abort(401), $category);

        return back()->with('status', 'Category removed.');
    }
}
