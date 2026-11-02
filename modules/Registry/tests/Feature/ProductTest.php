<?php

namespace Modules\Registry\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Modules\Registry\Access\ProductAccess;
use Modules\Registry\Models\Brand;
use Modules\Registry\Models\Competitor;
use Modules\Registry\Models\Product;
use Modules\Registry\Models\ProductCategory;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-22b / docs/02 §3.3 / docs/03 Registry: products, ours or a competitor's. Visible like competitors (PO decision
 * O25): by the product's classification, and a competitor's product only with the competitor visible. The brand
 * belongs to the same owner (service and composite foreign key); classification rules as for competitors; changes
 * need product.manage and are audited; brands and categories in use stay.
 */
class ProductTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $analyst;

    private Competitor $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->acme = $this->competitor('Acme Foods', Classification::Confidential);
    }

    public function test_reading_needs_product_view_and_changing_needs_product_manage(): void
    {
        $reader = $this->createUser('reader@example.test', 'exec_reader', clearance: Classification::Confidential);
        $admin = $this->enableTwoFactor($this->createUser('admin@example.test', 'super_admin', clearance: Classification::Restricted));
        $product = $this->product(null, 'Our Crisps');

        $this->actingAs($reader)->get('/products')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Registry/Products/Index')->where('canManage', false)->where('brands', [])->where('classifications', [])->where('products.0.name', 'Our Crisps'));
        $this->actingAs($reader)->get("/products/{$product->ulid}")->assertOk();
        $this->actingAs($reader)->post('/products', $this->form('Other'))->assertForbidden();
        $this->actingAs($reader)->put("/products/{$product->ulid}", $this->form('Other'))->assertForbidden();
        $this->actingAs($admin)->get('/products')->assertForbidden(); // platform, not content (docs/07 ¹)
        $this->actingAs($this->analyst)->delete("/products/{$product->ulid}")->assertStatus(405);
        $this->assertSame(['Our Crisps'], Product::query()->pluck('name')->all());
    }

    public function test_an_analyst_adds_products_with_brand_and_category_and_they_are_audited(): void
    {
        $brand = $this->brand($this->acme, 'Crunch');
        $category = $this->category('Snacks');

        $this->actingAs($this->analyst)->post('/products', $this->form('  Crunch   Original ', [
            'competitor' => $this->acme->ulid, 'brand' => $brand->ulid, 'category' => (string) $category->id, 'launched_on' => '2020-03-01',
            'description' => 'Synthetic test entry.',
        ]))->assertSessionHasNoErrors();
        $product = Product::query()->sole();
        $this->assertSame(['competitor', $this->acme->id, $brand->id, $category->id, 'Crunch Original', 'active', '2020-03-01', Classification::Internal, $this->analyst->id],
            [$product->owner_kind, $product->competitor_id, $product->brand_id, $product->category_id, $product->name, $product->status, $product->launched_on?->toDateString(), $product->classification, $product->created_by]);

        $audit = DB::table('audit_events')->where('action', 'product.created')->sole();
        // The higher of the product's (internal) and its competitor's (confidential) classification.
        $this->assertSame([$product->ulid, 'confidential'], [$audit->subject_ulid, $audit->classification]);
        $this->assertEquals(['owner' => 'competitor', 'competitor' => $this->acme->ulid, 'status' => 'active', 'classification' => 'internal'], json_decode((string) $audit->context, true));

        $this->actingAs($this->analyst)->get("/products/{$product->ulid}")->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Registry/Products/Show')
            ->where('product.owner', ['ulid' => $this->acme->ulid, 'name' => 'Acme Foods'])
            ->where('product.brand', ['ulid' => $brand->ulid, 'name' => 'Crunch'])
            ->where('product.category', ['id' => $category->id, 'name' => 'Snacks']));
        $this->actingAs($this->analyst)->get("/competitors/{$this->acme->ulid}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products', [['ulid' => $product->ulid, 'name' => 'Crunch Original', 'status' => 'active']]));

        $this->actingAs($this->analyst)->post('/products', $this->form('Our Crisps'))->assertSessionHasNoErrors();
        $this->assertEquals(['owner' => 'own', 'status' => 'active', 'classification' => 'internal'],
            json_decode((string) DB::table('audit_events')->where('action', 'product.created')->orderByDesc('id')->value('context'), true));
    }

    public function test_the_brand_belongs_to_the_same_owner(): void
    {
        $theirs = $this->brand($this->acme, 'Crunch');
        $ours = $this->brand(null, 'Our Brand');
        $beta = $this->competitor('Beta Snacks');
        $betas = $this->brand($beta, 'Beta Bites');

        $this->actingAs($this->analyst)->post('/products', $this->form('A', ['brand' => $theirs->ulid]))->assertSessionHasErrors('brand');
        $this->actingAs($this->analyst)->post('/products', $this->form('B', ['competitor' => $this->acme->ulid, 'brand' => $ours->ulid]))->assertSessionHasErrors('brand');
        $this->actingAs($this->analyst)->post('/products', $this->form('C', ['competitor' => $this->acme->ulid, 'brand' => $betas->ulid]))->assertSessionHasErrors('brand');
        $this->actingAs($this->analyst)->post('/products', $this->form('D', ['brand' => (string) Str::ulid()]))->assertSessionHasErrors('brand');
        $this->actingAs($this->analyst)->post('/products', $this->form('E', ['competitor' => $this->acme->ulid, 'brand' => $theirs->ulid]))->assertSessionHasNoErrors();
        $product = Product::query()->sole();
        $this->actingAs($this->analyst)->put("/products/{$product->ulid}", $this->form('E', ['brand' => $betas->ulid]))->assertSessionHasErrors('brand');
        $this->assertSame($theirs->id, $product->refresh()->brand_id);

        // The composite foreign key refuses it whatever writes the row.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('products_brand_owner_foreign');
        DB::table('products')->where('id', $product->id)->update(['brand_id' => $ours->id]);
    }

    public function test_visibility_follows_the_matrix_and_the_competitor(): void
    {
        $users = AuthorizationMatrix::users(); // ci_analyst: product.view
        $records = array_values(array_filter(AuthorizationMatrix::records(), fn (array $r): bool => $r['competitors'] === [] && $r['markets'] === []));
        $hidden = AuthorizationMatrix::competitorId('B');
        DB::table('competitors')->where('id', $hidden)->update(['classification' => 'restricted']); // needs an `all` grant
        $products = [];
        foreach ($records as $record) {
            foreach (['own' => null, 'A' => AuthorizationMatrix::competitorId('A'), 'B' => $hidden] as $owner => $competitorId) {
                $products[$record['label']][$owner] = $this->product($competitorId, "Product {$record['label']} $owner", Classification::from($record['classification']))->ulid;
            }
        }
        foreach (['own', 'A', 'B'] as $owner) {
            $visible = [];
            foreach ($users as $entry) {
                $visible[$entry['user']->id] = Product::query()->visibleTo($entry['user'])->pluck('ulid')->all();
            }
            foreach ($users as $entry) {
                foreach ($records as $record) {
                    $ulid = $products[$record['label']][$owner];
                    $want = $this->expected($entry, $record, $owner);
                    $this->assertSame($want, in_array($ulid, $visible[$entry['user']->id], true), "Product::visibleTo() for {$record['label']}/$owner, user {$entry['clearance']}/{$entry['status']}/{$entry['grant']}");
                    $this->assertSame($want, $this->app->make(ProductAccess::class)->allows($entry['user'], Product::query()->where('ulid', $ulid)->sole()), "ProductAccess::allows() for {$record['label']}/$owner");
                }
            }
        }

        // The HTTP paths use the same rule: an analyst (confidential, no grant) sees no restricted product and nothing of B.
        foreach ($records as $record) {
            foreach (['own', 'A', 'B'] as $owner) {
                $status = $this->actingAs($this->analyst)->get("/products/{$products[$record['label']][$owner]}")->getStatusCode();
                $this->assertSame($owner !== 'B' && $record['classification'] !== 'restricted' ? 200 : 404, $status, "GET /products for {$record['label']}/$owner");
            }
        }
        $listed = array_column($this->actingAs($this->analyst)->get('/products')->inertiaProps('products'), 'ulid');
        $this->assertCount(6, $listed); // public, internal, confidential × own, A
        // Filtering by the hidden competitor reveals nothing (TASK-0039): the same empty list as an unknown one.
        $hiddenUlid = (string) DB::table('competitors')->where('id', $hidden)->value('ulid');
        $this->assertSame([], $this->actingAs($this->analyst)->get("/products?owner=$hiddenUlid")->assertOk()->inertiaProps('products'));
        $secret = $products['internal/unscoped']['B'];
        $this->actingAs($this->analyst)->put("/products/$secret", $this->form('Renamed'))->assertNotFound();
        $this->actingAs($this->analyst)->post("/products/$secret/variants", ['sku' => 'X', 'name' => 'X', 'pack_size' => '1', 'pack_unit' => 'g', 'pack_count' => 1, 'status' => 'active'])->assertNotFound();
    }

    public function test_classification_rules_match_competitors(): void
    {
        $internal = $this->createUser('internal@example.test', 'ci_analyst');
        $this->actingAs($internal)->post('/products', $this->form('Secret', ['classification' => 'confidential']))->assertSessionHasErrors('classification');
        $this->actingAs($this->analyst)->post('/products', $this->form('Secret', ['classification' => 'restricted']))->assertSessionHasErrors('classification');
        $this->actingAs($this->analyst)->get('/products')->assertInertia(fn (AssertableInertia $page) => $page->where('classifications', ['public', 'internal', 'confidential']));

        $product = $this->product(null, 'Our Crisps');
        $this->actingAs($this->analyst)->put("/products/{$product->ulid}", $this->form('Our Crisps', ['classification' => 'confidential']))->assertSessionHasNoErrors();
        $this->actingAs($this->analyst)->put("/products/{$product->ulid}", $this->form('Our Crisps', ['classification' => 'internal']))->assertSessionHasErrors('classification');
        $director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
        $this->actingAs($director)->put("/products/{$product->ulid}", $this->form('Our Crisps', ['classification' => 'internal']))->assertSessionHasNoErrors();
        $this->assertSame(Classification::Internal, $product->refresh()->classification);
    }

    public function test_edits_are_audited_and_the_owner_stays(): void
    {
        $product = $this->product($this->acme->id, 'Crunch');

        $this->actingAs($this->analyst)->put("/products/{$product->ulid}", $this->form('Crunch'))->assertSessionHasNoErrors(); // unchanged
        $this->actingAs($this->analyst)->put("/products/{$product->ulid}", $this->form('Crunch', [
            'competitor' => '', 'status' => 'discontinued', 'launched_on' => '2019-01-01', 'discontinued_on' => '2024-06-30',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(['discontinued', '2024-06-30', $this->acme->id], [$product->refresh()->status, $product->discontinued_on?->toDateString(), $product->competitor_id]);
        $audit = DB::table('audit_events')->where('action', 'product.updated')->sole();
        $this->assertSame([$product->ulid, 'confidential'], [$audit->subject_ulid, $audit->classification]);
        $this->assertEquals(['fields' => ['status', 'launched_on', 'discontinued_on']], json_decode((string) $audit->context, true));
        $this->actingAs($this->analyst)->put("/products/{$product->ulid}", $this->form('Crunch', ['launched_on' => '2025-01-01', 'discontinued_on' => '2024-06-30']))->assertSessionHasErrors('discontinued_on');
    }

    public function test_the_list_filters_by_owner_status_and_category(): void
    {
        $snacks = $this->category('Snacks');
        $ours = $this->product(null, 'Ours');
        $theirs = $this->product($this->acme->id, 'Theirs', category: $snacks);
        $old = $this->product($this->acme->id, 'Old', status: 'discontinued');
        $list = fn (array $query): array => array_column($this->actingAs($this->analyst)->get('/products?'.http_build_query($query))->inertiaProps('products'), 'ulid');

        $this->assertSame([$ours->ulid], $list(['owner' => 'own']));
        $this->assertSame([$old->ulid, $theirs->ulid], $list(['owner' => $this->acme->ulid]));
        $this->assertSame([$old->ulid], $list(['status' => 'discontinued']));
        $this->assertSame([$theirs->ulid], $list(['category' => $snacks->id]));
        $this->assertSame([], $list(['owner' => (string) Str::ulid()]));
        $this->actingAs($this->analyst)->get('/products?owner=nonsense')->assertSessionHasErrors('owner');
    }

    public function test_brands_and_categories_in_use_stay(): void
    {
        $director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
        $brand = $this->brand($this->acme, 'Crunch');
        $category = $this->category('Snacks');
        // Even a product the remover cannot see keeps them; the message names no product.
        $this->product($this->acme->id, 'Secret', Classification::Restricted, $brand, $category);

        $this->actingAs($this->analyst)->delete("/brands/{$brand->ulid}")->assertSessionHasErrors(['brand' => 'Products use this brand, so it cannot be removed.']);
        $this->actingAs($director)->delete("/admin/categories/{$category->id}")->assertSessionHasErrors(['category' => 'Products use this category; move them to another one first.']);
        $this->assertSame([1, 1], [Brand::query()->count(), ProductCategory::query()->count()]);
        $this->assertSame(0, DB::table('audit_events')->whereIn('action', ['brand.removed', 'category.removed'])->count());
    }

    public function test_the_database_refuses_inconsistent_products(): void
    {
        $row = fn (array $overrides) => $overrides + [
            'ulid' => (string) Str::ulid(), 'owner_kind' => 'own', 'name' => 'Row', 'status' => 'active', 'classification' => 'internal',
            'created_by' => $this->analyst->id, 'created_at' => now(), 'updated_at' => now(),
        ];
        foreach ([
            'owner' => ['owner_kind' => 'competitor'],
            'owner ' => ['competitor_id' => $this->acme->id],
            'status' => ['status' => 'gone'],
            'classification' => ['classification' => 'secret'],
            'dates' => ['launched_on' => '2020-01-02', 'discontinued_on' => '2020-01-01'],
            'name' => ['name' => '   '],
        ] as $check => $overrides) {
            try {
                DB::table('products')->insert($row($overrides));
                $this->fail("The database accepted a product breaking products_{$check}_check.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('products_'.trim($check).'_check', $e->getMessage());
            }
        }
        DB::table('products')->insert($row([]));
        $this->assertSame(1, Product::query()->count());
    }

    /**
     * The independent oracle: the product's own record, and its competitor's entry (matrix competitors are public;
     * B was made restricted in the test).
     *
     * @param  array{clearance: string, status: string, grant: string}  $user
     * @param  array{classification: string, competitors: list<int>, markets: list<int>}  $record
     */
    private function expected(array $user, array $record, string $owner): bool
    {
        $competitor = ['classification' => $owner === 'B' ? 'restricted' : 'public', 'competitors' => [], 'markets' => []];

        return AuthorizationMatrix::expected($user, $record) && ($owner === 'own' || AuthorizationMatrix::expected($user, $competitor));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function form(string $name, array $overrides = []): array
    {
        return $overrides + ['name' => $name, 'status' => 'active', 'classification' => 'internal'];
    }

    private function product(?int $competitorId, string $name, Classification $classification = Classification::Internal, ?Brand $brand = null, ?ProductCategory $category = null, string $status = 'active'): Product
    {
        $product = new Product;
        $product->forceFill([
            'ulid' => (string) Str::ulid(), 'owner_kind' => $competitorId === null ? 'own' : 'competitor', 'competitor_id' => $competitorId,
            'brand_id' => $brand?->id, 'category_id' => $category?->id, 'name' => $name, 'status' => $status, 'classification' => $classification,
            'created_by' => $this->analyst->id,
        ])->save();

        return $product;
    }

    private function brand(?Competitor $owner, string $name): Brand
    {
        $brand = new Brand;
        $brand->forceFill(['ulid' => (string) Str::ulid(), 'competitor_id' => $owner?->id, 'name' => $name])->save();

        return $brand;
    }

    private function category(string $name): ProductCategory
    {
        $category = new ProductCategory;
        $category->forceFill(['name' => $name, 'path' => '/', 'depth' => 0])->save();

        return $category;
    }

    private function competitor(string $name, Classification $classification = Classification::Internal): Competitor
    {
        $competitor = new Competitor;
        $competitor->forceFill([
            'ulid' => (string) Str::ulid(), 'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)), 'lifecycle' => 'active',
            'classification' => $classification, 'created_by' => $this->analyst->id,
        ])->save();

        return $competitor;
    }
}
