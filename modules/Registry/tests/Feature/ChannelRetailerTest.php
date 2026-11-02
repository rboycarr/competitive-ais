<?php

namespace Modules\Registry\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Modules\Registry\Models\Channel;
use Modules\Registry\Models\Market;
use Modules\Registry\Models\Retailer;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-23b / docs/02 §3.3 / docs/03 Registry: channels (unique names, a channel type; kept while retailers use them) and
 * retailers (one fixed market, one channel, names unique within the market, an optional http(s) website), maintained
 * by taxonomy.manage holders; the database holds the same rules; every change audited (IDs and codes only).
 */
class ChannelRetailerTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
    }

    public function test_only_taxonomy_managers_reach_channels_and_retailers(): void
    {
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst');
        $channel = $this->channel('Supermarkets');
        $retailer = $this->retailer($this->market('PH'), $channel, 'Synthetic Mart');

        $this->actingAs($analyst)->get('/admin/channels')->assertForbidden();
        $this->actingAs($analyst)->post('/admin/channels', ['name' => 'Online', 'channel_type' => 'ecommerce'])->assertForbidden();
        $this->actingAs($analyst)->delete("/admin/channels/{$channel->id}")->assertForbidden();
        $this->actingAs($analyst)->get('/admin/retailers')->assertForbidden();
        $this->actingAs($analyst)->put("/admin/retailers/{$retailer->ulid}", ['name' => 'Changed', 'channel' => $channel->id])->assertForbidden();
        $this->actingAs($analyst)->delete("/admin/retailers/{$retailer->ulid}")->assertForbidden();
        $this->actingAs($this->director)->get('/admin/channels')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Registry/Admin/Channels')->where('channels.0', ['id' => $channel->id, 'name' => 'Supermarkets', 'type' => 'modern_trade', 'retailers' => 1]));
        $this->actingAs($this->director)->get('/admin/retailers')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Registry/Admin/Retailers'));
        $this->actingAs($this->director)->delete('/admin/retailers/'.Str::ulid())->assertNotFound();
        $this->assertSame(['Synthetic Mart'], Retailer::query()->pluck('name')->all());
    }

    public function test_channels_are_added_edited_and_removed_with_an_audit(): void
    {
        $this->actingAs($this->director)->post('/admin/channels', ['name' => ' Super  Markets ', 'channel_type' => 'modern_trade'])->assertSessionHasNoErrors()->assertRedirect();
        $channel = Channel::query()->sole();
        $this->assertSame(['Super Markets', 'modern_trade'], [$channel->name, $channel->channel_type]);
        $this->actingAs($this->director)->post('/admin/channels', ['name' => 'SUPER MARKETS', 'channel_type' => 'other'])->assertSessionHasErrors(['name' => 'A channel with this name exists.']);
        $this->actingAs($this->director)->post('/admin/channels', ['name' => 'Online', 'channel_type' => 'teleport'])->assertSessionHasErrors('channel_type');

        $this->actingAs($this->director)->put("/admin/channels/{$channel->id}", ['name' => 'Supermarkets', 'channel_type' => 'wholesale'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(['Supermarkets', 'wholesale'], [$channel->refresh()->name, $channel->channel_type]);
        $this->actingAs($this->director)->delete("/admin/channels/{$channel->id}")->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(0, Channel::query()->count());

        $this->assertEquals([
            ['channel.created', ['channel_type' => 'modern_trade']],
            ['channel.updated', ['fields' => ['name', 'channel_type']]],
            ['channel.removed', ['channel_type' => 'wholesale']],
        ], DB::table('audit_events')->where('action', 'like', 'channel.%')->orderBy('id')->get()->map(fn ($e) => [$e->action, json_decode((string) $e->context, true)])->all());
    }

    public function test_a_channel_that_retailers_use_is_kept_by_the_service_and_the_database(): void
    {
        $channel = $this->channel('Supermarkets');
        $this->retailer($this->market('PH'), $channel, 'Synthetic Mart');

        $this->actingAs($this->director)->delete("/admin/channels/{$channel->id}")
            ->assertSessionHasErrors(['channel' => 'Retailers use this channel; give them another channel first.']);
        $this->assertSame(1, Channel::query()->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'channel.removed')->count());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('retailers_channel_id_foreign');
        DB::table('channels')->where('id', $channel->id)->delete();
    }

    public function test_retailers_are_added_in_a_fixed_market_edited_and_removed_with_an_audit(): void
    {
        $ph = $this->market('PH');
        $sg = $this->market('SG');
        $supermarkets = $this->channel('Supermarkets');
        $online = $this->channel('Online', 'ecommerce');

        $this->actingAs($this->director)->post('/admin/retailers', ['market' => $ph->ulid, 'channel' => $supermarkets->id, 'name' => ' Synthetic  Mart ', 'website' => ' https://mart.example '])
            ->assertSessionHasNoErrors()->assertRedirect();
        $retailer = Retailer::query()->sole();
        $this->assertSame([$ph->id, $supermarkets->id, 'Synthetic Mart', 'https://mart.example'], [$retailer->market_id, $retailer->channel_id, $retailer->name, $retailer->website]);
        $this->actingAs($this->director)->post('/admin/retailers', ['market' => $ph->ulid, 'channel' => $online->id, 'name' => 'SYNTHETIC MART'])
            ->assertSessionHasErrors(['name' => 'This market already has a retailer with this name.']);
        $this->actingAs($this->director)->post('/admin/retailers', ['market' => $sg->ulid, 'channel' => $online->id, 'name' => 'Synthetic Mart'])->assertSessionHasNoErrors(); // another market

        $this->actingAs($this->director)->put("/admin/retailers/{$retailer->ulid}", ['market' => $sg->ulid, 'channel' => $online->id, 'name' => 'Synthetic Mart Online', 'website' => ''])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame([$ph->id, $online->id, 'Synthetic Mart Online', null], [$retailer->refresh()->market_id, $retailer->channel_id, $retailer->name, $retailer->website]); // the market is not an input
        $this->actingAs($this->director)->delete("/admin/retailers/{$retailer->ulid}")->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Retailer::query()->count());

        $this->assertEquals([
            ['retailer.created', ['market' => $ph->ulid, 'channel_id' => $supermarkets->id]],
            ['retailer.created', ['market' => $sg->ulid, 'channel_id' => $online->id]],
            ['retailer.updated', ['fields' => ['name', 'channel_id', 'website']]],
            ['retailer.removed', ['market' => $ph->ulid, 'channel_id' => $online->id]],
        ], DB::table('audit_events')->where('action', 'like', 'retailer.%')->orderBy('id')->get()->map(fn ($e) => [$e->action, json_decode((string) $e->context, true)])->all());
    }

    public function test_invalid_retailers_are_refused_by_the_service_and_the_database(): void
    {
        $market = $this->market('PH');
        $channel = $this->channel('Supermarkets');
        $form = fn (array $overrides): array => $overrides + ['market' => $market->ulid, 'channel' => $channel->id, 'name' => 'Synthetic Mart'];

        foreach ([
            'market' => ['market' => (string) Str::ulid()],
            'channel' => ['channel' => 999999],
            'name' => ['name' => ''],
            'website' => ['website' => 'javascript:alert(1)'],
            'website ' => ['website' => 'ftp://mart.example'],
        ] as $field => $overrides) {
            $this->actingAs($this->director)->post('/admin/retailers', $form($overrides))->assertSessionHasErrors(trim($field));
        }
        $this->assertSame(0, Retailer::query()->count());

        $row = fn (array $overrides): array => $overrides + [
            'ulid' => (string) Str::ulid(), 'market_id' => $market->id, 'channel_id' => $channel->id, 'name' => 'Synthetic Mart', 'website' => null, 'created_at' => now(), 'updated_at' => now(),
        ];
        foreach (['website' => ['website' => 'javascript:alert(1)'], 'name' => ['name' => "Synthetic\tMart"]] as $check => $overrides) {
            try {
                DB::table('retailers')->insert($row($overrides));
                $this->fail("The database accepted a retailer breaking retailers_{$check}_check.");
            } catch (QueryException $e) {
                $this->assertStringContainsString("retailers_{$check}_check", $e->getMessage());
            }
        }
        foreach (['type' => ['channel_type' => 'teleport'], 'name' => ['name' => ' Online']] as $check => $overrides) {
            try {
                DB::table('channels')->insert($overrides + ['name' => 'Online', 'channel_type' => 'ecommerce', 'created_at' => now(), 'updated_at' => now()]);
                $this->fail("The database accepted a channel breaking channels_{$check}_check.");
            } catch (QueryException $e) {
                $this->assertStringContainsString("channels_{$check}_check", $e->getMessage());
            }
        }
    }

    public function test_the_retailer_list_filters_by_market_and_channel(): void
    {
        $ph = $this->market('PH');
        $sg = $this->market('SG');
        $supermarkets = $this->channel('Supermarkets');
        $online = $this->channel('Online', 'ecommerce');
        $this->retailer($ph, $supermarkets, 'Alpha Mart');
        $this->retailer($ph, $online, 'Beta Online');
        $this->retailer($sg, $supermarkets, 'Gamma Mart');

        $list = fn (string $query): array => array_column($this->actingAs($this->director)->get("/admin/retailers?$query")->assertOk()->inertiaProps('retailers'), 'name');
        $this->assertSame(['Alpha Mart', 'Beta Online', 'Gamma Mart'], $list(''));
        $this->assertSame(['Alpha Mart', 'Beta Online'], $list('market='.$ph->ulid));
        $this->assertSame(['Alpha Mart'], $list('market='.$ph->ulid.'&channel='.$supermarkets->id));
        $this->assertSame([], $list('market='.Str::ulid()));
    }

    private function market(string $code): Market
    {
        $market = new Market;
        $market->forceFill(['ulid' => (string) Str::ulid(), 'code' => $code, 'name' => "Synthetic $code", 'country' => $code, 'default_currency' => 'USD'])->save();

        return $market;
    }

    private function channel(string $name, string $type = 'modern_trade'): Channel
    {
        $channel = new Channel;
        $channel->forceFill(['name' => $name, 'channel_type' => $type])->save();

        return $channel;
    }

    private function retailer(Market $market, Channel $channel, string $name): Retailer
    {
        $retailer = new Retailer;
        $retailer->forceFill(['ulid' => (string) Str::ulid(), 'market_id' => $market->id, 'channel_id' => $channel->id, 'name' => $name])->save();

        return $retailer;
    }
}
