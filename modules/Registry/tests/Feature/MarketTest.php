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
use Modules\Registry\Models\Currency;
use Modules\Registry\Models\Market;
use Modules\Registry\Models\Region;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-23a / docs/02 §3.3 / docs/03 Registry: ISO 4217 currencies (seeded, read-only for the app), markets with a fixed
 * upper-case code, and each market's region tree, whose levels deepen strictly from parent to child (service, and
 * the database through a composite key and a CHECK). Maintained by taxonomy.manage holders; every change audited.
 */
class MarketTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
    }

    public function test_only_taxonomy_managers_reach_markets(): void
    {
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst');
        $admin = $this->enableTwoFactor($this->createUser('admin@example.test', 'super_admin', clearance: Classification::Restricted));
        $market = $this->market('PH');
        $region = $this->region($market, 'North', 'region');

        $this->actingAs($analyst)->get('/admin/markets')->assertForbidden();
        $this->actingAs($analyst)->get("/admin/markets/{$market->ulid}")->assertForbidden();
        $this->actingAs($analyst)->post('/admin/markets', $this->form('XX'))->assertForbidden();
        $this->actingAs($analyst)->put("/admin/markets/{$market->ulid}", $this->form('PH'))->assertForbidden();
        $this->actingAs($analyst)->post("/admin/markets/{$market->ulid}/regions", ['name' => 'South', 'level' => 'region'])->assertForbidden();
        $this->actingAs($analyst)->delete("/admin/markets/{$market->ulid}/regions/{$region->id}")->assertForbidden();
        $this->actingAs($admin)->get('/admin/markets')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Registry/Admin/Markets'));
        $this->actingAs($this->director)->get("/admin/markets/{$market->ulid}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Registry/Admin/Market'));
        $this->actingAs($this->director)->delete("/admin/markets/{$market->ulid}")->assertStatus(405); // never deleted
        $this->actingAs($this->director)->get('/admin/markets/'.Str::ulid())->assertNotFound();
        $this->assertSame(1, Region::query()->count());
    }

    public function test_currencies_are_the_seeded_iso_subset(): void
    {
        $this->assertSame(22, Currency::query()->count());
        foreach (['PHP' => ['Philippine Peso', 2], 'JPY' => ['Yen', 0], 'BHD' => ['Bahraini Dinar', 3]] as $code => $expected) {
            $currency = Currency::query()->findOrFail($code);
            $this->assertSame($expected, [$currency->name, $currency->minor_units]);
        }
        $this->actingAs($this->director)->get('/admin/markets')->assertInertia(fn (AssertableInertia $page) => $page->has('currencies', 22)->where('currencies.0', ['code' => 'AED', 'name' => 'UAE Dirham']));
    }

    public function test_a_market_is_created_with_a_fixed_code_and_audited(): void
    {
        $response = $this->actingAs($this->director)->post('/admin/markets', ['code' => ' ph-ncr ', 'name' => ' Synthetic  Metro ', 'country' => 'ph', 'currency' => 'php'])->assertSessionHasNoErrors();
        $market = Market::query()->sole();
        $response->assertRedirect("/admin/markets/{$market->ulid}");
        $this->assertSame(['PH-NCR', 'Synthetic Metro', 'PH', 'PHP'], [$market->code, $market->name, $market->country, $market->default_currency]);
        $this->assertSame([$market->ulid, 'internal'], [DB::table('audit_events')->where('action', 'market.created')->value('subject_ulid'), DB::table('audit_events')->where('action', 'market.created')->value('classification')]);

        $this->actingAs($this->director)->post('/admin/markets', $this->form('PH-NCR'))->assertSessionHasErrors(['code' => 'A market with this code exists.']);
        $this->actingAs($this->director)->put("/admin/markets/{$market->ulid}", ['code' => 'OTHER', 'name' => 'Synthetic Metro', 'country' => 'PH', 'currency' => 'PHP'])->assertSessionHasNoErrors(); // unchanged; the code is not an input
        $this->actingAs($this->director)->put("/admin/markets/{$market->ulid}", ['name' => 'Synthetic Capital', 'country' => 'PH', 'currency' => 'USD'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(['PH-NCR', 'Synthetic Capital', 'USD'], [$market->refresh()->code, $market->name, $market->default_currency]);
        $this->assertEquals([['fields' => ['name', 'default_currency']]],
            DB::table('audit_events')->where('action', 'market.updated')->pluck('context')->map(fn ($c) => json_decode((string) $c, true))->all());
    }

    public function test_invalid_markets_are_refused_by_the_service_and_the_database(): void
    {
        foreach ([
            'code' => ['code' => 'bad code'],
            'code ' => ['code' => '-LEADING'],
            'country' => ['country' => 'P1'],
            'currency' => ['currency' => 'XXX'],
            'name' => ['name' => ''],
        ] as $field => $overrides) {
            $this->actingAs($this->director)->post('/admin/markets', $overrides + $this->form('OK'))->assertSessionHasErrors(trim($field));
        }
        $this->assertSame(0, Market::query()->count());

        $row = fn (array $overrides) => $overrides + ['ulid' => (string) Str::ulid(), 'code' => 'OK', 'name' => 'Synthetic', 'country' => 'PH', 'default_currency' => 'PHP', 'created_at' => now(), 'updated_at' => now()];
        foreach (['code' => ['code' => 'lower'], 'country' => ['country' => 'ph'], 'name' => ['name' => ' ']] as $check => $overrides) {
            try {
                DB::table('markets')->insert($row($overrides));
                $this->fail("The database accepted a market breaking markets_{$check}_check.");
            } catch (QueryException $e) {
                $this->assertStringContainsString("markets_{$check}_check", $e->getMessage());
            }
        }
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('markets_default_currency_foreign');
        DB::table('markets')->insert($row(['default_currency' => 'XXX']));
    }

    public function test_a_region_tree_deepens_from_region_to_district_and_is_audited(): void
    {
        $market = $this->market('PH');
        $add = fn (string $name, string $level, ?Region $parent = null) => $this->actingAs($this->director)
            ->post("/admin/markets/{$market->ulid}/regions", ['name' => $name, 'level' => $level, 'parent' => $parent?->id]);

        $add(' North  Region ', 'region')->assertSessionHasNoErrors()->assertRedirect();
        $north = Region::query()->sole();
        $add('Alpha Province', 'province', $north)->assertSessionHasNoErrors();
        $alpha = Region::query()->where('name', 'Alpha Province')->sole();
        $add('Beta City', 'city', $alpha)->assertSessionHasNoErrors();
        $beta = Region::query()->where('name', 'Beta City')->sole();
        $add('Gamma District', 'district', $beta)->assertSessionHasNoErrors();
        $add('Lone City', 'city')->assertSessionHasNoErrors(); // any level at the top
        $add('Delta City', 'city', $north)->assertSessionHasNoErrors(); // levels may be skipped

        // Never at the same or a shallower level than the parent.
        $add('Twin City', 'city', $beta)->assertSessionHasErrors(['level' => 'A city cannot be part of a city.']);
        $add('Upside Region', 'region', $alpha)->assertSessionHasErrors('level');
        $add('Nowhere', 'district', Region::query()->where('name', 'Gamma District')->sole())->assertSessionHasErrors('level');

        $this->assertSame([$north->id, 2, 'province'], [$alpha->parent_id, $alpha->level_rank, $alpha->level]);
        $this->actingAs($this->director)->get("/admin/markets/{$market->ulid}")->assertInertia(fn (AssertableInertia $page) => $page->where('levels', ['region', 'province', 'city', 'district']));
        $rows = $this->actingAs($this->director)->get("/admin/markets/{$market->ulid}")->inertiaProps('regions');
        $this->assertSame(['Lone City', 'North Region', '-Alpha Province', '--Beta City', '---Gamma District', '-Delta City'],
            array_map(fn (array $r): string => str_repeat('-', $r['depth']).$r['name'], $rows));
        $this->assertEquals([['market' => $market->ulid, 'level' => 'region'], ['market' => $market->ulid, 'level' => 'province', 'parent_id' => $north->id]],
            DB::table('audit_events')->where('action', 'region.created')->orderBy('id')->limit(2)->pluck('context')->map(fn ($c) => json_decode((string) $c, true))->all());
    }

    public function test_region_names_are_unique_among_siblings_and_parents_stay_in_their_market(): void
    {
        $market = $this->market('PH');
        $other = $this->market('SG');
        $north = $this->region($market, 'North', 'region');
        $south = $this->region($market, 'South', 'region');
        $abroad = $this->region($other, 'Abroad', 'region');
        $this->region($market, 'Alpha', 'province', $north);
        $post = fn (array $form) => $this->actingAs($this->director)->post("/admin/markets/{$market->ulid}/regions", $form);

        $post(['name' => 'ÀLPHA', 'level' => 'province', 'parent' => $north->id])->assertSessionHasErrors(['name' => 'A region with this name already exists at that place.']);
        $post(['name' => 'Alpha', 'level' => 'province', 'parent' => $south->id])->assertSessionHasNoErrors();
        $post(['name' => 'north', 'level' => 'city'])->assertSessionHasErrors('name');
        $post(['name' => 'Inside', 'level' => 'province', 'parent' => $abroad->id])->assertSessionHasErrors(['parent' => 'Choose a region of this market.']);
        $post(['name' => 'Odd', 'level' => 'county'])->assertSessionHasErrors('level');
        $this->actingAs($this->director)->post("/admin/markets/{$other->ulid}/regions", ['name' => 'North', 'level' => 'region'])->assertSessionHasNoErrors(); // another market

        // A region is reached only through its own market.
        $this->actingAs($this->director)->put("/admin/markets/{$other->ulid}/regions/{$north->id}", ['name' => 'Renamed'])->assertNotFound();
        $this->actingAs($this->director)->delete("/admin/markets/{$other->ulid}/regions/{$north->id}")->assertNotFound();
        $this->assertSame('North', $north->refresh()->name);
    }

    public function test_renaming_and_removing_regions(): void
    {
        $market = $this->market('PH');
        $north = $this->region($market, 'North', 'region');
        $alpha = $this->region($market, 'Alpha', 'province', $north);

        $this->actingAs($this->director)->put("/admin/markets/{$market->ulid}/regions/{$alpha->id}", ['name' => 'Alpha'])->assertSessionHasNoErrors(); // unchanged
        $this->actingAs($this->director)->put("/admin/markets/{$market->ulid}/regions/{$alpha->id}", ['name' => 'Alpha Prime'])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->director)->delete("/admin/markets/{$market->ulid}/regions/{$north->id}")->assertSessionHasErrors(['region' => 'Remove its subregions first.']);
        $this->actingAs($this->director)->delete("/admin/markets/{$market->ulid}/regions/{$alpha->id}")->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->director)->delete("/admin/markets/{$market->ulid}/regions/{$north->id}")->assertSessionHasNoErrors();
        $this->actingAs($this->director)->delete("/admin/markets/{$market->ulid}/regions/{$north->id}")->assertNotFound();

        $this->assertSame(0, Region::query()->count());
        $this->assertSame(['region.renamed', 'region.removed', 'region.removed'], DB::table('audit_events')->where('action', 'like', 'region.%')->orderBy('id')->pluck('action')->all());
        $this->assertEquals(['market' => $market->ulid, 'level' => 'province', 'parent_id' => $north->id],
            json_decode((string) DB::table('audit_events')->where('action', 'region.removed')->orderBy('id')->value('context'), true));
    }

    public function test_the_database_holds_the_region_tree_rules(): void
    {
        $market = $this->market('PH');
        $other = $this->market('SG');
        $north = $this->region($market, 'North', 'region'); // rank 1
        $abroad = $this->region($other, 'Abroad', 'region');
        $row = fn (array $overrides) => $overrides + ['market_id' => $market->id, 'parent_id' => $north->id, 'name' => 'Child', 'level' => 'province', 'parent_level_rank' => 1, 'created_at' => now(), 'updated_at' => now()];

        foreach ([
            'regions_parent_foreign' => ['parent_id' => $abroad->id], // parent in another market
            'regions_parent_foreign ' => ['parent_level_rank' => 2, 'level' => 'city'], // not the parent's real rank
            'regions_parent_check' => ['level' => 'region'], // not deeper than the parent
            'regions_parent_check ' => ['parent_id' => null], // a rank without a parent
            'regions_level_check' => ['level' => 'county'],
            'regions_name_check' => ['name' => "no\u{00A0}break"],
        ] as $constraint => $overrides) {
            try {
                DB::table('regions')->insert($row($overrides));
                $this->fail('The database accepted a region breaking '.trim($constraint).'.');
            } catch (QueryException $e) {
                $this->assertStringContainsString(trim($constraint), $e->getMessage());
            }
        }
        DB::table('regions')->insert($row([]));
        $this->assertSame(2, Region::query()->where('market_id', $market->id)->count());

        // The level of a region with subregions cannot change under them.
        $this->expectException(QueryException::class);
        DB::table('regions')->where('id', $north->id)->update(['level' => 'city']);
    }

    public function test_top_level_names_are_unique_whatever_writes_the_row(): void
    {
        $market = $this->market('PH');
        $this->region($market, 'North', 'region');

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('regions')->insert(['market_id' => $market->id, 'name' => 'NORTH', 'level' => 'city', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, string> */
    private function form(string $code): array
    {
        return ['code' => $code, 'name' => 'Synthetic market', 'country' => 'PH', 'currency' => 'PHP'];
    }

    private function market(string $code): Market
    {
        $market = new Market;
        $market->forceFill(['ulid' => (string) Str::ulid(), 'code' => $code, 'name' => "Synthetic $code", 'country' => 'PH', 'default_currency' => 'PHP'])->save();

        return $market;
    }

    private function region(Market $market, string $name, string $level, ?Region $parent = null): Region
    {
        $region = new Region;
        $region->forceFill(['market_id' => $market->id, 'parent_id' => $parent?->id, 'name' => $name, 'level' => $level, 'parent_level_rank' => $parent === null ? null : Region::LEVELS[$parent->level]])->save();

        return $region->refresh();
    }
}
