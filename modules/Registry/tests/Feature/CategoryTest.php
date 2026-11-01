<?php

namespace Modules\Registry\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use LogicException;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Modules\Registry\Actions\CategoryService;
use Modules\Registry\Models\ProductCategory;
use Modules\Registry\Support\CategoryTree;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-22a / docs/02 §3.3 / docs/03 Registry: the product category tree, maintained by taxonomy.manage holders.
 * Paths and depths stay consistent (CHECKs), names are unique among siblings ignoring case and accents, the tree
 * has at most six levels and no cycles, only empty categories are removed, and every change is audited.
 */
class CategoryTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
    }

    public function test_only_taxonomy_managers_reach_the_page(): void
    {
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst');
        $admin = $this->enableTwoFactor($this->createUser('admin@example.test', 'super_admin', clearance: Classification::Restricted));
        $food = $this->category('Food');

        $this->actingAs($analyst)->get('/admin/categories')->assertForbidden();
        $this->actingAs($analyst)->post('/admin/categories', ['name' => 'Drinks'])->assertForbidden();
        $this->actingAs($analyst)->put("/admin/categories/{$food->id}", ['name' => 'Foods'])->assertForbidden();
        $this->actingAs($analyst)->put("/admin/categories/{$food->id}/parent", ['parent' => null])->assertForbidden();
        $this->actingAs($analyst)->delete("/admin/categories/{$food->id}")->assertForbidden();
        $this->actingAs($admin)->get('/admin/categories')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Registry/Admin/Categories'));
        $this->actingAs($this->director)->get('/admin/categories')->assertOk();
        $this->assertSame(['Food'], ProductCategory::query()->pluck('name')->all());
    }

    public function test_a_tree_is_built_with_consistent_paths_and_audited(): void
    {
        $this->actingAs($this->director)->post('/admin/categories', ['name' => ' Snacks '])->assertSessionHasNoErrors();
        $snacks = ProductCategory::query()->sole();
        $this->actingAs($this->director)->post('/admin/categories', ['name' => 'Chips', 'parent' => $snacks->id])->assertSessionHasNoErrors();
        $chips = ProductCategory::query()->where('name', 'Chips')->sole();
        $this->actingAs($this->director)->post('/admin/categories', ['name' => 'Corn chips', 'parent' => $chips->id])->assertSessionHasNoErrors();
        $this->actingAs($this->director)->post('/admin/categories', ['name' => 'Biscuits', 'parent' => $snacks->id])->assertSessionHasNoErrors();
        $this->actingAs($this->director)->post('/admin/categories', ['name' => 'Beverages'])->assertSessionHasNoErrors();
        $corn = ProductCategory::query()->where('name', 'Corn chips')->sole();

        $this->assertSame(['Snacks', '/', 0], [$snacks->name, $snacks->path, $snacks->depth]);
        $this->assertSame(["/{$snacks->id}/", 1, "/{$snacks->id}/{$chips->id}/", 2], [$chips->path, $chips->depth, $corn->path, $corn->depth]);
        // Depth first, siblings by name.
        $page = $this->actingAs($this->director)->get('/admin/categories')->assertInertia(fn (AssertableInertia $page) => $page->where('maxDepth', 5));
        $this->assertSame(['Beverages', 'Snacks', '-Biscuits', '-Chips', '--Corn chips'],
            array_map(fn (array $r): string => str_repeat('-', $r['depth']).$r['name'], $page->inertiaProps('categories')));
        $audits = DB::table('audit_events')->where('action', 'category.created')->orderBy('id')->pluck('context')->map(fn ($c) => json_decode((string) $c, true))->all();
        $this->assertEquals([null, ['parent_id' => $snacks->id], ['parent_id' => $chips->id], ['parent_id' => $snacks->id], null], $audits);
    }

    public function test_names_are_unique_among_siblings_ignoring_case_and_accents(): void
    {
        $snacks = $this->category('Snacks');
        $drinks = $this->category('Drinks');
        $this->category('Chips', $snacks);

        $this->actingAs($this->director)->post('/admin/categories', ['name' => 'CHÎPS', 'parent' => $snacks->id])->assertSessionHasErrors('name');
        $this->actingAs($this->director)->post('/admin/categories', ['name' => 'Chips', 'parent' => $drinks->id])->assertSessionHasNoErrors();
        $this->actingAs($this->director)->post('/admin/categories', ['name' => 'snacks'])->assertSessionHasErrors('name');
        $this->actingAs($this->director)->put("/admin/categories/{$drinks->id}", ['name' => 'SNACKS'])->assertSessionHasErrors('name');
        $this->actingAs($this->director)->put("/admin/categories/{$snacks->id}", ['name' => 'SNACKS'])->assertSessionHasNoErrors();
        $this->assertSame('SNACKS', $snacks->refresh()->name);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'category.renamed')->count());

        // Top-level names too, through the NULL-safe key, whatever writes the row.
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('product_categories')->insert(['name' => 'Drinks', 'path' => '/', 'depth' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_moving_carries_the_subtree_and_never_builds_a_cycle(): void
    {
        $snacks = $this->category('Snacks');
        $chips = $this->category('Chips', $snacks);
        $corn = $this->category('Corn chips', $chips);
        $food = $this->category('Food');

        foreach ([$snacks->id, $chips->id, $corn->id] as $inside) {
            $this->actingAs($this->director)->put("/admin/categories/{$snacks->id}/parent", ['parent' => $inside])->assertSessionHasErrors('parent');
        }
        $this->actingAs($this->director)->put("/admin/categories/{$snacks->id}/parent", ['parent' => $food->id])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame([["/{$food->id}/", 1], ["/{$food->id}/{$snacks->id}/", 2], ["/{$food->id}/{$snacks->id}/{$chips->id}/", 3]],
            [[$snacks->refresh()->path, $snacks->depth], [$chips->refresh()->path, $chips->depth], [$corn->refresh()->path, $corn->depth]]);

        // Back to the top: depths shrink with it.
        $this->actingAs($this->director)->put("/admin/categories/{$chips->id}/parent", ['parent' => null])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame([['/', 0, null], ["/{$chips->id}/", 1]], [[$chips->refresh()->path, $chips->depth, $chips->parent_id], [$corn->refresh()->path, $corn->depth]]);
        // Unchanged parent: nothing to audit.
        $this->actingAs($this->director)->put("/admin/categories/{$chips->id}/parent", ['parent' => null])->assertSessionHasNoErrors()->assertRedirect();

        $audits = DB::table('audit_events')->where('action', 'category.moved')->orderBy('id')->get();
        $this->assertEquals([['to_parent' => $food->id, 'subcategories' => 2], ['from_parent' => $snacks->id, 'subcategories' => 1]],
            $audits->map(fn ($a) => json_decode((string) $a->context, true))->all());
        $this->assertSame([$snacks->id, $chips->id], $audits->pluck('subject_id')->all());

        // A name taken at the new place is refused, and nothing moves.
        $this->category('Corn chips', $food);
        $this->actingAs($this->director)->put("/admin/categories/{$corn->id}/parent", ['parent' => $food->id])->assertSessionHasErrors('name');
        $this->assertSame($chips->id, $corn->refresh()->parent_id);
        $this->actingAs($this->director)->put("/admin/categories/{$corn->id}/parent", ['parent' => 999999])->assertSessionHasErrors('parent');
    }

    public function test_the_tree_has_at_most_six_levels(): void
    {
        $levels = [$parent = $this->category('Level 0')];
        foreach (range(1, 5) as $level) {
            $this->actingAs($this->director)->post('/admin/categories', ['name' => "Level $level", 'parent' => $parent->id])->assertSessionHasNoErrors();
            $levels[] = $parent = ProductCategory::query()->where('name', "Level $level")->sole();
        }
        $this->assertSame(5, $parent->depth);
        $this->actingAs($this->director)->post('/admin/categories', ['name' => 'Level 6', 'parent' => $parent->id])->assertSessionHasErrors('parent');

        // Moving a two-level subtree under level 4 would need a seventh level.
        $branch = $this->category('Branch');
        $this->category('Leaf', $branch);
        $this->actingAs($this->director)->put("/admin/categories/{$branch->id}/parent", ['parent' => $levels[4]->id])->assertSessionHasErrors('parent');
        $this->actingAs($this->director)->put("/admin/categories/{$branch->id}/parent", ['parent' => $levels[3]->id])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(5, ProductCategory::query()->where('name', 'Leaf')->sole()->depth);
    }

    public function test_only_empty_categories_are_removed(): void
    {
        $snacks = $this->category('Snacks');
        $chips = $this->category('Chips', $snacks);

        $this->actingAs($this->director)->delete("/admin/categories/{$snacks->id}")->assertSessionHasErrors(['category' => 'Move or remove its subcategories first.']);
        $this->actingAs($this->director)->delete("/admin/categories/{$chips->id}")->assertSessionHasNoErrors();
        $this->actingAs($this->director)->delete("/admin/categories/{$snacks->id}")->assertSessionHasNoErrors();
        $this->actingAs($this->director)->delete("/admin/categories/{$snacks->id}")->assertNotFound();

        $this->assertSame(0, ProductCategory::query()->count());
        $this->assertEquals([['parent_id' => $snacks->id], null],
            DB::table('audit_events')->where('action', 'category.removed')->orderBy('id')->pluck('context')->map(fn ($c) => json_decode((string) $c, true))->all());
    }

    public function test_the_database_keeps_paths_depths_and_names_consistent(): void
    {
        $root = $this->category('Root');
        $row = fn (array $overrides) => $overrides + ['name' => 'Child', 'parent_id' => $root->id, 'path' => "/{$root->id}/", 'depth' => 1, 'created_at' => now(), 'updated_at' => now()];
        foreach ([
            'path' => ['depth' => 2],
            'path ' => ['path' => "x/{$root->id}/"], // each row breaks exactly one CHECK

            'parent' => ['path' => '/', 'depth' => 0],
            'parent ' => ['parent_id' => null],
            'name' => ['name' => 'Two  spaces'],
            'name ' => ['name' => "no\u{00A0}break"],
        ] as $check => $overrides) {
            try {
                DB::table('product_categories')->insert($row($overrides));
                $this->fail("The database accepted a row breaking product_categories_{$check}_check.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('product_categories_'.trim($check).'_check', $e->getMessage());
            }
        }
        DB::table('product_categories')->insert($row([]));
        $this->assertSame(2, ProductCategory::query()->count());
    }

    public function test_the_tree_check_finds_rows_the_single_row_checks_cannot(): void
    {
        $snacks = $this->category('Snacks');
        $chips = $this->category('Chips', $snacks);
        $food = $this->category('Food');
        $this->actingAs($this->director)->put("/admin/categories/{$snacks->id}/parent", ['parent' => $food->id])->assertRedirect();
        $this->assertSame([], CategoryTree::inconsistencies());
        $this->assertSame(0, Artisan::call('registry:verify-categories'));

        // TASK-0036 #1: a path ending in the parent's ID passes the CHECKs even when the rest is wrong.
        DB::table('product_categories')->insert(['name' => 'Stray', 'parent_id' => $chips->id, 'path' => "/{$chips->id}/{$chips->id}/", 'depth' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $stray = ProductCategory::query()->where('name', 'Stray')->sole();
        $this->assertSame([$stray->id], CategoryTree::inconsistencies());
        $this->assertSame([$stray->id], CategoryTree::inconsistencies($snacks->id));
        $this->assertSame([], CategoryTree::inconsistencies($food->id + 1000));
        $this->assertSame(1, Artisan::call('registry:verify-categories'));
        $this->assertStringContainsString("disagrees with the parent: {$stray->id}", Artisan::output());

        // A move that would leave its subtree inconsistent is rolled back.
        try {
            $this->app->make(CategoryService::class)->move($this->director, $snacks, null);
            $this->fail('A move over an inconsistent subtree was committed.');
        } catch (LogicException $e) {
            $this->assertStringContainsString((string) $stray->id, $e->getMessage());
        }
        $this->assertSame($food->id, $snacks->refresh()->parent_id);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'category.moved')->count());
    }

    private function category(string $name, ?ProductCategory $parent = null): ProductCategory
    {
        $category = new ProductCategory;
        $category->forceFill([
            'parent_id' => $parent?->id, 'name' => $name, 'path' => $parent?->childPath() ?? '/', 'depth' => $parent === null ? 0 : $parent->depth + 1,
        ])->save();

        return $category;
    }
}
