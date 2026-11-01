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
use Modules\Registry\Models\Brand;
use Modules\Registry\Models\Competitor;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-22a / docs/02 §3.3 / docs/03 Registry: brands are ours or a competitor's, unique per owner ignoring case and
 * accents (application, UNIQUE on the NULL-safe key); a competitor's brand is visible and changeable only with the
 * competitor visible (PO decision 2026-11-02: products and brands follow their competitor); changes need
 * product.manage and are audited with IDs and codes.
 */
class BrandTest extends TestCase
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
        $brand = $this->brand(null, 'Our Snacks');

        $this->actingAs($reader)->get('/brands')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Registry/Brands/Index')->where('canManage', false)->where('competitors', [])->where('brands.0.name', 'Our Snacks'));
        $this->actingAs($reader)->post('/brands', ['name' => 'Other'])->assertForbidden();
        $this->actingAs($reader)->put("/brands/{$brand->ulid}", ['name' => 'Other'])->assertForbidden();
        $this->actingAs($reader)->delete("/brands/{$brand->ulid}")->assertForbidden();
        // Super admins manage the platform, not content (docs/07 ¹).
        $this->actingAs($admin)->get('/brands')->assertForbidden();
        // A suspended account is signed out before anything is read or changed.
        $suspended = $this->createUser('suspended@example.test', 'ci_analyst', 'suspended');
        $this->actingAs($suspended)->get('/brands')->assertRedirect('/login');
        $this->actingAs($suspended)->post('/brands', ['name' => 'Other'])->assertRedirect('/login');
        $this->assertSame(['Our Snacks'], Brand::query()->pluck('name')->all());
    }

    public function test_an_analyst_adds_our_brands_and_competitor_brands_and_they_are_audited(): void
    {
        $this->actingAs($this->analyst)->post('/brands', ['name' => "  Our\u{00A0} Crisps "])->assertSessionHasNoErrors();
        $this->actingAs($this->analyst)->post('/brands', ['competitor' => $this->acme->ulid, 'name' => 'Acme Crunch'])->assertSessionHasNoErrors();

        [$own, $theirs] = Brand::query()->orderBy('id')->get()->all();
        $this->assertSame(['Our Crisps', null, 'Acme Crunch', $this->acme->id], [$own->name, $own->competitor_id, $theirs->name, $theirs->competitor_id]);
        $audits = DB::table('audit_events')->where('action', 'brand.created')->orderBy('id')->get();
        $this->assertSame([[$own->ulid, 'internal'], [$theirs->ulid, 'confidential']], $audits->map(fn ($a) => [$a->subject_ulid, $a->classification])->all());
        $this->assertEquals([['owner' => 'own'], ['owner' => 'competitor', 'competitor' => $this->acme->ulid]], $audits->map(fn ($a) => json_decode((string) $a->context, true))->all());

        $this->actingAs($this->analyst)->get('/brands')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('brands', [
                ['ulid' => $own->ulid, 'name' => 'Our Crisps', 'owner' => null],
                ['ulid' => $theirs->ulid, 'name' => 'Acme Crunch', 'owner' => ['ulid' => $this->acme->ulid, 'name' => 'Acme Foods']],
            ])
            ->where('canManage', true)
            ->where('competitors', [['ulid' => $this->acme->ulid, 'name' => 'Acme Foods']]));
    }

    public function test_names_are_unique_per_owner_ignoring_case_and_accents(): void
    {
        $beta = $this->competitor('Beta Snacks');
        $this->actingAs($this->analyst)->post('/brands', ['competitor' => $this->acme->ulid, 'name' => 'Crunché'])->assertSessionHasNoErrors();
        $this->actingAs($this->analyst)->post('/brands', ['competitor' => $this->acme->ulid, 'name' => 'CRUNCHE'])->assertSessionHasErrors(['name' => 'Acme Foods already has a brand with this name.']);
        $this->actingAs($this->analyst)->post('/brands', ['competitor' => $beta->ulid, 'name' => 'Crunche'])->assertSessionHasNoErrors();
        $this->actingAs($this->analyst)->post('/brands', ['name' => 'crunche'])->assertSessionHasNoErrors();
        $this->actingAs($this->analyst)->post('/brands', ['name' => 'Crunché  '])->assertSessionHasErrors(['name' => 'We already have a brand with this name.']);
        $this->assertSame(3, Brand::query()->count());

        // Renaming into a taken name is refused; changing only the case is a rename.
        $ours = Brand::query()->whereNull('competitor_id')->sole();
        $other = $this->brand(null, 'Other');
        $this->actingAs($this->analyst)->put("/brands/{$other->ulid}", ['name' => 'CRUNCHE'])->assertSessionHasErrors('name');
        $this->actingAs($this->analyst)->put("/brands/{$ours->ulid}", ['name' => 'CRUNCHE'])->assertSessionHasNoErrors();
        $this->assertSame('CRUNCHE', $ours->refresh()->name);

        // The NULL-safe key makes our own brands unique too, whatever writes the row.
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('brands')->insert(['ulid' => (string) Str::ulid(), 'competitor_id' => null, 'name' => 'Crunche', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_hidden_competitors_brands_are_invisible_and_unchangeable(): void
    {
        $hidden = $this->competitor('Hidden Holdings', Classification::Restricted); // restricted needs a grant
        $secret = $this->brand($hidden, 'Secret Brand');
        $this->brand($this->acme, 'Acme Crunch');

        $page = $this->actingAs($this->analyst)->get('/brands')->assertOk();
        $this->assertSame(['Acme Crunch'], array_column($page->inertiaProps('brands'), 'name'));
        foreach (['Hidden Holdings', $hidden->ulid, 'Secret Brand', $secret->ulid] as $needle) {
            $this->assertStringNotContainsString($needle, $page->getContent() ?: '');
        }
        $this->actingAs($this->analyst)->put("/brands/{$secret->ulid}", ['name' => 'Renamed'])->assertNotFound();
        $this->actingAs($this->analyst)->delete("/brands/{$secret->ulid}")->assertNotFound();
        foreach ([$hidden->ulid, (string) Str::ulid()] as $owner) {
            $this->actingAs($this->analyst)->post('/brands', ['competitor' => $owner, 'name' => 'New'])->assertSessionHasErrors(['competitor' => 'Choose a competitor you can see.']);
        }

        $this->assertSame(['Secret Brand', 'Acme Crunch'], Brand::query()->orderBy('id')->pluck('name')->all());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'like', 'brand.%')->count());
    }

    public function test_renaming_and_removing_are_audited(): void
    {
        $brand = $this->brand($this->acme, 'Acme Crunch');

        $this->actingAs($this->analyst)->put("/brands/{$brand->ulid}", ['name' => 'Acme Crunch'])->assertSessionHasNoErrors(); // unchanged
        $this->actingAs($this->analyst)->put("/brands/{$brand->ulid}", ['name' => 'Acme Crunch Max'])->assertSessionHasNoErrors();
        $this->actingAs($this->analyst)->delete("/brands/{$brand->ulid}")->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->analyst)->delete("/brands/{$brand->ulid}")->assertNotFound();

        $this->assertSame(0, Brand::query()->count());
        $audits = DB::table('audit_events')->where('action', 'like', 'brand.%')->orderBy('id')->get();
        $this->assertSame([['brand.renamed', $brand->ulid, 'confidential'], ['brand.removed', $brand->ulid, 'confidential']],
            $audits->map(fn ($a) => [$a->action, $a->subject_ulid, $a->classification])->all());
        $this->assertEquals(['owner' => 'competitor', 'competitor' => $this->acme->ulid], json_decode((string) $audits->last()?->context, true));
    }

    public function test_invalid_names_are_refused_by_the_request_and_the_database(): void
    {
        $this->actingAs($this->analyst)->post('/brands', ['name' => '   '])->assertSessionHasErrors('name');
        $this->actingAs($this->analyst)->post('/brands', ['name' => str_repeat('b', 256)])->assertSessionHasErrors('name');
        $this->actingAs($this->analyst)->post('/brands', ['competitor' => 'not-a-ulid', 'name' => 'Brand'])->assertSessionHasErrors('competitor');

        foreach ([' padded', 'two  spaces', "tab\there", "no\u{00A0}break", ''] as $name) {
            try {
                DB::table('brands')->insert(['ulid' => (string) Str::ulid(), 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
                $this->fail("The database accepted the brand name [$name].");
            } catch (QueryException $e) {
                $this->assertStringContainsString('brands_name_check', $e->getMessage());
            }
        }
        $this->assertSame(0, Brand::query()->count());
    }

    private function brand(?Competitor $owner, string $name): Brand
    {
        $brand = new Brand;
        $brand->forceFill(['ulid' => (string) Str::ulid(), 'competitor_id' => $owner?->id, 'name' => $name])->save();

        return $brand;
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
