<?php

namespace Modules\Registry\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-24: quick search finds competitors (by name or alias) and products (by name) the viewer can see, with the same
 * visibility as the lists; a kind the viewer may not view is not searched; LIKE wildcards in the query are literal.
 */
class QuickSearchTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst');
        DB::table('access_grants')->insert([
            'ulid' => (string) Str::ulid(), 'user_id' => $this->analyst->id, 'scope_type' => 'all', 'max_classification' => Classification::Internal->value,
            'granted_by' => $this->analyst->id, 'reason' => 'Synthetic test grant', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_it_finds_visible_competitors_by_name_or_alias_and_products_by_name(): void
    {
        $acme = $this->competitor('Acme Foods');
        DB::table('competitor_aliases')->insert(['competitor_id' => $this->competitor('Zenith Snacks'), 'alias' => 'Acme Old Name', 'alias_type' => 'former', 'created_at' => now(), 'updated_at' => now()]);
        $this->competitor('Acme Secret', 'restricted');   // above the analyst's clearance
        $this->competitor('Other Co');
        $this->product('Acme Crisps');
        $this->product('Plain Water');

        $this->actingAs($this->analyst)->get('/search?q=acme')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Registry/Search')->where('searched', true)
            ->where('competitors', fn ($hits) => array_column($this->list($hits), 'label') === ['Acme Foods', 'Zenith Snacks'])
            ->where('products', fn ($hits) => array_column($this->list($hits), 'label') === ['Acme Crisps'])
            ->where('competitors.0.href', route('competitors.show', DB::table('competitors')->where('id', $acme)->value('ulid'))));
    }

    public function test_short_queries_and_wildcards_match_nothing_extra(): void
    {
        $this->competitor('Acme Foods');
        $this->competitor('100% Juice');

        $this->actingAs($this->analyst)->get('/search?q=a')->assertOk()->assertInertia(fn ($page) => $page
            ->where('searched', false)->where('competitors', [])->where('products', []));
        $this->actingAs($this->analyst)->get('/search?q=%25')->assertOk()->assertInertia(fn ($page) => $page
            ->where('competitors', [])); // a lone "%" is one character: too short
        $this->actingAs($this->analyst)->get('/search?q=0%25')->assertOk()->assertInertia(fn ($page) => $page
            ->where('competitors', fn ($hits) => array_column($this->list($hits), 'label') === ['100% Juice']));
        $this->actingAs($this->analyst)->get('/search?q=_c')->assertOk()->assertInertia(fn ($page) => $page
            ->where('competitors', [])); // "_" is literal, not "any character"
        $this->actingAs($this->analyst)->get('/search?q='.str_repeat('x', 101))->assertSessionHasErrors('q');
    }

    public function test_only_the_kinds_the_viewer_may_view_are_searched(): void
    {
        $this->get('/search?q=acme')->assertRedirect('/login');
        $this->actingAs($this->createUser('service@example.test', 'service_account'))->get('/search?q=acme')->assertForbidden();
    }

    /** @return list<array<string, mixed>> */
    private function list(mixed $hits): array
    {
        return array_values(is_array($hits) ? $hits : $hits->all());
    }

    private function competitor(string $name, string $classification = 'internal'): int
    {
        return (int) DB::table('competitors')->insertGetId([
            'ulid' => (string) Str::ulid(), 'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)), 'lifecycle' => 'active',
            'classification' => $classification, 'created_by' => $this->analyst->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function product(string $name): void
    {
        DB::table('products')->insert([
            'ulid' => (string) Str::ulid(), 'owner_kind' => 'own', 'name' => $name, 'status' => 'active', 'classification' => 'internal',
            'created_by' => $this->analyst->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
