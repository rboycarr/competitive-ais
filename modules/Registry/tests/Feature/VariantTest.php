<?php

namespace Modules\Registry\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Modules\Registry\Actions\VariantService;
use Modules\Registry\Models\Competitor;
use Modules\Registry\Models\Product;
use Modules\Registry\Models\ProductVariant;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-22b / docs/02 §3.3: variants are packs of a product. SKUs are unique per product ignoring case and accents, a
 * GTIN has a valid check digit, pack sizes are positive with at most four decimals (service and CHECKs); a variant
 * is visible and changeable with its product only; changes are audited.
 */
class VariantTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    /** Synthetic GTIN-13 in the GS1 restricted-circulation range (prefix 2), check digit 5. */
    private const GTIN = '2000000000015';

    private User $analyst;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->product = $this->product('Crunch', Classification::Confidential);
    }

    public function test_an_analyst_adds_a_variant_and_it_is_audited(): void
    {
        $this->actingAs($this->analyst)->post("/products/{$this->product->ulid}/variants", $this->form([
            'sku' => "  CR\u{00A0} 150 ", 'gtin' => '2000 0000 00015', 'name' => ' Crunch  150 g ', 'pack_size' => '0150.5',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $variant = ProductVariant::query()->sole();
        $this->assertSame(['CR 150', self::GTIN, 'Crunch 150 g', '150.5000', 'g', 1, 'active'],
            [$variant->sku, $variant->gtin, $variant->name, $variant->pack_size, $variant->pack_unit, $variant->pack_count, $variant->status]);
        $audit = DB::table('audit_events')->where('action', 'variant.created')->sole();
        $this->assertSame([$variant->ulid, 'confidential'], [$audit->subject_ulid, $audit->classification]);
        $this->assertEquals(['product' => $this->product->ulid], json_decode((string) $audit->context, true));

        $this->actingAs($this->analyst)->get("/products/{$this->product->ulid}")->assertInertia(fn (AssertableInertia $page) => $page->where('variants', [[
            'ulid' => $variant->ulid, 'sku' => 'CR 150', 'gtin' => self::GTIN, 'name' => 'Crunch 150 g', 'packSize' => '150.5000', 'packUnit' => 'g', 'packCount' => 1, 'status' => 'active',
        ]]));
    }

    public function test_skus_are_unique_per_product_ignoring_case_and_accents(): void
    {
        $other = $this->product('Other');
        $this->actingAs($this->analyst)->post("/products/{$this->product->ulid}/variants", $this->form(['sku' => 'CRÜ-150']))->assertSessionHasNoErrors();
        $this->actingAs($this->analyst)->post("/products/{$this->product->ulid}/variants", $this->form(['sku' => 'cru-150']))->assertSessionHasErrors(['sku' => 'This product already has a variant with this SKU.']);
        $this->actingAs($this->analyst)->post("/products/{$other->ulid}/variants", $this->form(['sku' => 'CRU-150']))->assertSessionHasNoErrors();
        $this->assertSame(2, ProductVariant::query()->count());

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('product_variants')->insert(['ulid' => (string) Str::ulid(), 'product_id' => $this->product->id, 'sku' => 'CRU-150', 'name' => 'X', 'pack_size' => 1, 'pack_unit' => 'g', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_invalid_variants_are_refused_by_the_service_and_the_database(): void
    {
        foreach ([
            'gtin' => ['gtin' => '2000000000016'], // wrong check digit
            'gtin ' => ['gtin' => '200000000001'], // 12 digits, wrong check digit for GTIN-12
            'gtin  ' => ['gtin' => '2000000'],
            'pack_size' => ['pack_size' => '0'],
            'pack_size ' => ['pack_size' => '-1'],
            'pack_size  ' => ['pack_size' => '1.23456'],
            'pack_unit' => ['pack_unit' => 'lb'],
            'pack_count' => ['pack_count' => 0],
            'sku' => ['sku' => '   '],
            'name' => ['name' => ''],
            'status' => ['status' => 'gone'],
        ] as $field => $overrides) {
            $this->actingAs($this->analyst)->post("/products/{$this->product->ulid}/variants", $this->form($overrides))->assertSessionHasErrors(trim($field));
        }
        $this->assertTrue(VariantService::isValidGtin(self::GTIN));
        $this->assertTrue(VariantService::isValidGtin('20000004')); // GTIN-8
        $this->assertFalse(VariantService::isValidGtin('20000005'));

        $row = fn (array $overrides) => $overrides + ['ulid' => (string) Str::ulid(), 'product_id' => $this->product->id, 'sku' => 'S', 'name' => 'N', 'pack_size' => 1, 'pack_unit' => 'g', 'created_at' => now(), 'updated_at' => now()];
        foreach ([
            'sku' => ['sku' => 'two  spaces'],
            'gtin' => ['gtin' => '123'],
            'pack' => ['pack_size' => 0],
            'pack ' => ['pack_unit' => 'lb'],
            'status' => ['status' => 'gone'],
            'name' => ['name' => ' '],
        ] as $check => $overrides) {
            try {
                DB::table('product_variants')->insert($row($overrides));
                $this->fail("The database accepted a variant breaking product_variants_{$check}_check.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('product_variants_'.trim($check).'_check', $e->getMessage());
            }
        }
        $this->assertSame(0, ProductVariant::query()->count());
    }

    public function test_variants_follow_their_products_visibility_and_url(): void
    {
        $reader = $this->createUser('reader@example.test', 'exec_reader', clearance: Classification::Confidential);
        $variant = $this->variant($this->product, 'CR-150');
        $hidden = $this->product('Secret', Classification::Restricted);
        $hiddenVariant = $this->variant($hidden, 'S-1');
        $other = $this->product('Other');

        $this->actingAs($reader)->post("/products/{$this->product->ulid}/variants", $this->form())->assertForbidden();
        $this->actingAs($reader)->put("/products/{$this->product->ulid}/variants/{$variant->ulid}", $this->form())->assertForbidden();
        $this->actingAs($this->analyst)->post("/products/{$hidden->ulid}/variants", $this->form())->assertNotFound();
        $this->actingAs($this->analyst)->put("/products/{$hidden->ulid}/variants/{$hiddenVariant->ulid}", $this->form())->assertNotFound();
        // A variant is reached only through its own product.
        $this->actingAs($this->analyst)->put("/products/{$other->ulid}/variants/{$variant->ulid}", $this->form(['sku' => 'MOVED']))->assertNotFound();
        $this->actingAs($this->analyst)->get("/products/{$hidden->ulid}")->assertNotFound();

        $this->assertSame(['CR-150', 'S-1'], ProductVariant::query()->orderBy('id')->pluck('sku')->all());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'like', 'variant.%')->count());
    }

    public function test_edits_are_audited(): void
    {
        $variant = $this->variant($this->product, 'CR-150');

        $this->actingAs($this->analyst)->put("/products/{$this->product->ulid}/variants/{$variant->ulid}", $this->form(['sku' => 'CR-150']))->assertSessionHasNoErrors(); // unchanged
        $this->actingAs($this->analyst)->put("/products/{$this->product->ulid}/variants/{$variant->ulid}", $this->form(['sku' => 'CR-150', 'pack_count' => 6, 'status' => 'discontinued']))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame([6, 'discontinued'], [$variant->refresh()->pack_count, $variant->status]);
        $audit = DB::table('audit_events')->where('action', 'variant.updated')->sole();
        $this->assertSame([$variant->ulid, 'confidential'], [$audit->subject_ulid, $audit->classification]);
        $this->assertEquals(['fields' => ['pack_count', 'status']], json_decode((string) $audit->context, true));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + ['sku' => 'CR-150', 'gtin' => '', 'name' => 'Crunch 150 g', 'pack_size' => '150', 'pack_unit' => 'g', 'pack_count' => 1, 'status' => 'active'];
    }

    private function variant(Product $product, string $sku): ProductVariant
    {
        $variant = new ProductVariant;
        $variant->forceFill(['ulid' => (string) Str::ulid(), 'product_id' => $product->id, 'sku' => $sku, 'name' => 'Crunch 150 g', 'pack_size' => '150.0000', 'pack_unit' => 'g', 'pack_count' => 1, 'status' => 'active'])->save();

        return $variant;
    }

    private function product(string $name, Classification $classification = Classification::Internal): Product
    {
        $competitor = new Competitor;
        $competitor->forceFill([
            'ulid' => (string) Str::ulid(), 'name' => "$name maker", 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)), 'lifecycle' => 'active',
            'classification' => Classification::Internal, 'created_by' => $this->analyst->id,
        ])->save();
        $product = new Product;
        $product->forceFill([
            'ulid' => (string) Str::ulid(), 'owner_kind' => 'competitor', 'competitor_id' => $competitor->id, 'name' => $name, 'status' => 'active',
            'classification' => $classification, 'created_by' => $this->analyst->id,
        ])->save();

        return $product;
    }
}
