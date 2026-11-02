<?php

namespace Modules\Registry\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Modules\Registry\Models\Currency;
use Modules\Registry\Models\Market;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-23b / PO decision O29: taxonomy managers add and edit currencies. The code is the key and fixed; the minor units
 * stay fixed while a market uses the currency; never deleted (grant class no_delete, checked for every such table in
 * DatabaseAccountsTest); every change audited with the code and field names only.
 */
class CurrencyTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
    }

    public function test_only_taxonomy_managers_reach_currencies(): void
    {
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst');

        $this->actingAs($analyst)->get('/admin/currencies')->assertForbidden();
        $this->actingAs($analyst)->post('/admin/currencies', ['code' => 'XTS', 'name' => 'Test Units', 'minor_units' => 2])->assertForbidden();
        $this->actingAs($analyst)->put('/admin/currencies/PHP', ['name' => 'Changed', 'minor_units' => 2])->assertForbidden();
        $this->actingAs($this->director)->get('/admin/currencies')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Registry/Admin/Currencies')->has('currencies', 22)->where('currencies.0', ['code' => 'AED', 'name' => 'UAE Dirham', 'minorUnits' => 2, 'markets' => 0]));
        $this->actingAs($this->director)->delete('/admin/currencies/PHP')->assertStatus(405); // never deleted
        $this->actingAs($this->director)->put('/admin/currencies/XXX', ['name' => 'Unknown', 'minor_units' => 2])->assertNotFound();
        $this->assertSame('Philippine Peso', Currency::query()->findOrFail('PHP')->name);
    }

    public function test_a_currency_is_added_and_edited_and_audited(): void
    {
        $this->actingAs($this->director)->post('/admin/currencies', ['code' => ' xts ', 'name' => ' Test  Units ', 'minor_units' => 2])
            ->assertSessionHasNoErrors()->assertRedirect();
        $currency = Currency::query()->findOrFail('XTS');
        $this->assertSame(['Test Units', 2], [$currency->name, $currency->minor_units]);
        $created = DB::table('audit_events')->where('action', 'currency.created')->sole();
        $this->assertSame([null, 'internal', ['currency' => 'xts', 'minor_units' => 2]], [$created->subject_id, $created->classification, json_decode((string) $created->context, true)]);

        $this->actingAs($this->director)->post('/admin/currencies', ['code' => 'XTS', 'name' => 'Again', 'minor_units' => 2])
            ->assertSessionHasErrors(['code' => 'This currency is already listed.']);

        $this->actingAs($this->director)->put('/admin/currencies/XTS', ['name' => 'Testing Units', 'minor_units' => 3])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->director)->put('/admin/currencies/XTS', ['name' => 'Testing Units', 'minor_units' => 3])->assertSessionHasNoErrors(); // unchanged: not audited
        $this->assertSame(['Testing Units', 3], [$currency->refresh()->name, $currency->minor_units]);
        $this->assertEquals([['currency' => 'xts', 'fields' => ['name', 'minor_units']]],
            DB::table('audit_events')->where('action', 'currency.updated')->pluck('context')->map(fn ($c) => json_decode((string) $c, true))->all());
    }

    public function test_decimal_places_stay_fixed_while_a_market_uses_the_currency(): void
    {
        $market = new Market;
        $market->forceFill(['ulid' => (string) Str::ulid(), 'code' => 'PH', 'name' => 'Synthetic', 'country' => 'PH', 'default_currency' => 'PHP'])->save();

        $this->actingAs($this->director)->put('/admin/currencies/PHP', ['name' => 'Philippine Peso', 'minor_units' => 3])
            ->assertSessionHasErrors(['minor_units' => 'Markets use this currency, so its decimal places stay as they are.']);
        $this->actingAs($this->director)->put('/admin/currencies/PHP', ['name' => 'Peso', 'minor_units' => 2])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(['Peso', 2], [Currency::query()->findOrFail('PHP')->name, Currency::query()->findOrFail('PHP')->minor_units]);
        $this->actingAs($this->director)->get('/admin/currencies')->assertInertia(fn (AssertableInertia $page) => $page->where('currencies.16.code', 'PHP')->where('currencies.16.markets', 1));
    }

    public function test_invalid_currencies_are_refused_by_the_service_and_the_database(): void
    {
        foreach ([
            'code' => ['code' => 'X1Z'],
            'name' => ['name' => ''],
            'minor_units' => ['minor_units' => 5],
        ] as $field => $overrides) {
            $this->actingAs($this->director)->post('/admin/currencies', $overrides + ['code' => 'XTS', 'name' => 'Test Units', 'minor_units' => 2])->assertSessionHasErrors($field);
        }
        $this->assertSame(22, Currency::query()->count());

        foreach (['code' => ['code' => 'xts'], 'name' => ['name' => ' Test'], 'minor_units' => ['minor_units' => 5]] as $check => $overrides) {
            try {
                DB::table('currencies')->insert($overrides + ['code' => 'XTS', 'name' => 'Test Units', 'minor_units' => 2]);
                $this->fail("The database accepted a currency breaking currencies_{$check}_check.");
            } catch (QueryException $e) {
                $this->assertStringContainsString("currencies_{$check}_check", $e->getMessage());
            }
        }
    }
}
