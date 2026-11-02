<?php

namespace Modules\Registry\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Modules\Registry\Actions\BrandService;
use Modules\Registry\Actions\CategoryService;
use Modules\Registry\Actions\CompetitorAliasService;
use Modules\Registry\Actions\CompetitorRelationshipService;
use Modules\Registry\Actions\CompetitorTagService;
use Modules\Registry\Actions\CurrencyService;
use Modules\Registry\Actions\MarketService;
use Modules\Registry\Actions\ProductService;
use Modules\Registry\Actions\RegionService;
use Modules\Registry\Actions\TagService;
use Modules\Registry\Models\Brand;
use Modules\Registry\Models\Competitor;
use Modules\Registry\Models\CompetitorAlias;
use Modules\Registry\Models\CompetitorRelationship;
use Modules\Registry\Models\Currency;
use Modules\Registry\Models\Market;
use Modules\Registry\Models\Product;
use Modules\Registry\Models\ProductCategory;
use Modules\Registry\Models\Region;
use Modules\Registry\Models\Tag;
use Modules\Registry\Support\CategoryTree;
use Tests\Support\Concurrently;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-25: registry rules hold under truly parallel requests (separate processes and connections, Concurrently). Each
 * scenario checks invariants that must hold whatever the interleaving, and ties every successful request to what was
 * stored (TASK-0046 #2, #3), so no scenario passes because every request failed. Every request ends either
 * successfully or with a validation error, never with a database error (which would be a 500). Rounds repeat each
 * scenario, since one run proves little about timing. Data is committed, so the class uses DatabaseMigrations.
 */
class RegistryConcurrencyTest extends TestCase
{
    use CreatesUsers, DatabaseMigrations;

    private const ROUNDS = 3;

    private const WORKERS = 4;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
    }

    public function test_one_request_wins_a_unique_name(): void
    {
        $market = $this->market('PH');
        $owner = $this->competitor('Brand Owner');
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $competitor = $this->competitor("Acme $round");
            $region = app(RegionService::class)->create($this->director, $market, "Region $round", 'region', null);
            $category = app(CategoryService::class)->create($this->director, "Food $round", null);

            $this->assertOneWinner(Concurrently::run(array_map(fn (string $alias) => fn () => app(CompetitorAliasService::class)
                ->add($this->director, $competitor, $alias, 'trade'), $this->spellings('Acme Brands'))), 'alias');
            $this->assertSame(1, CompetitorAlias::query()->where('competitor_id', $competitor->id)->count());

            $this->assertOneWinner(Concurrently::run(array_map(fn (string $name) => fn () => app(BrandService::class)
                ->create($this->director, null, $name), $this->spellings("Round $round Brands"))), 'own brand');
            $this->assertSame(1, Brand::query()->whereNull('competitor_id')->where('name', "Round $round Brands")->count());

            $this->assertOneWinner(Concurrently::run(array_map(fn (string $name) => fn () => app(BrandService::class)
                ->create($this->director, $owner->ulid, $name), $this->spellings("Owner $round Brand"))), 'competitor brand');
            $this->assertSame(1, Brand::query()->where('competitor_id', $owner->id)->where('name', "Owner $round Brand")->count());

            $this->assertOneWinner(Concurrently::run(array_map(fn (string $name) => fn () => app(RegionService::class)
                ->create($this->director, $market, $name, 'region', null), $this->spellings("North $round"))), 'top-level region');
            $this->assertSame(1, Region::query()->where('market_id', $market->id)->where('name', "North $round")->count());

            $this->assertOneWinner(Concurrently::run(array_map(fn (string $name) => fn () => app(RegionService::class)
                ->create($this->director, $market, $name, 'province', $region->id), $this->spellings("Alpha $round"))), 'subregion');
            $this->assertSame(1, Region::query()->where('parent_id', $region->id)->count());

            $this->assertOneWinner(Concurrently::run(array_map(fn (string $name) => fn () => app(CategoryService::class)
                ->create($this->director, $name, null), $this->spellings("Beverages $round"))), 'top-level category');
            $this->assertSame(1, ProductCategory::query()->whereNull('parent_id')->where('name', "Beverages $round")->count());

            $this->assertOneWinner(Concurrently::run(array_map(fn (string $name) => fn () => app(CategoryService::class)
                ->create($this->director, $name, $category->id), $this->spellings("Snacks $round"))), 'subcategory');
            $this->assertSame(1, ProductCategory::query()->where('parent_id', $category->id)->count());

            $this->assertOneWinner(Concurrently::run(array_map(fn (string $code) => fn () => app(MarketService::class)
                ->create($this->director, ['code' => $code, 'name' => "Market $round", 'country' => 'PH', 'currency' => 'USD']), ["SG$round", "sg$round", " sg$round ", "SG$round"])), 'market code');
            $this->assertSame(1, Market::query()->where('code', "SG$round")->count());

            $code = 'Q'.chr(64 + $round).'Z';
            $this->assertOneWinner(Concurrently::run(array_map(fn (string $c) => fn () => app(CurrencyService::class)
                ->create($this->director, $c, "Units $round", 2), [$code, strtolower($code), " $code ", $code])), 'currency code');
            $this->assertSame(1, Currency::query()->whereKey($code)->count());
        }
    }

    public function test_overlapping_relationships_are_refused_under_parallel_requests(): void
    {
        $relationships = app(CompetitorRelationshipService::class);
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $parent = $this->competitor("Parent $round");
            $child = $this->competitor("Child $round");

            // Four open-ended ranges for the same pair and type: every two overlap, so one may exist.
            $results = Concurrently::run(array_map(fn (int $month) => fn () => $relationships->add(
                $this->director, $parent, 'parent', $child->ulid, ['relation_type' => 'subsidiary', 'valid_from' => sprintf('2020-%02d-01', $month)],
            ), range(1, self::WORKERS)));
            $this->assertOneWinner($results, "open-ended round $round");
            $this->assertSame(1, $this->relationshipCount($parent, $child, 'subsidiary'));

            // Four back-to-back quarters: none overlaps, so the locks must not refuse any.
            $quarters = [['2021-01-01', '2021-03-31'], ['2021-04-01', '2021-06-30'], ['2021-07-01', '2021-09-30'], ['2021-10-01', '2021-12-31']];
            $results = Concurrently::run(array_map(fn (array $q) => fn () => $relationships->add(
                $this->director, $parent, 'parent', $child->ulid, ['relation_type' => 'partner', 'valid_from' => $q[0], 'valid_to' => $q[1]],
            ), $quarters));
            $this->assertSame(['ok', 'ok', 'ok', 'ok'], array_column($results, 'outcome'), "back-to-back round $round: ".json_encode($results));
            $this->assertSame(4, $this->relationshipCount($parent, $child, 'partner'));

            // Two updates that are each fine alone but overlap together: one may win.
            $early = $relationships->add($this->director, $parent, 'parent', $child->ulid, ['relation_type' => 'distributor', 'valid_from' => '2022-01-01', 'valid_to' => '2022-03-31']);
            $late = $relationships->add($this->director, $parent, 'parent', $child->ulid, ['relation_type' => 'distributor', 'valid_from' => '2022-10-01', 'valid_to' => '2022-12-31']);
            $results = Concurrently::run([
                fn () => $relationships->update($this->director, $parent, $early, ['valid_from' => '2022-01-01', 'valid_to' => '2022-08-31']),
                fn () => $relationships->update($this->director, $parent, $late, ['valid_from' => '2022-05-01', 'valid_to' => '2022-12-31']),
            ]);
            $this->assertOneWinner($results, "crossing updates round $round");
            $ends = [$early->refresh()->valid_to?->toDateString(), $late->refresh()->valid_from->toDateString()];
            $this->assertSame($results[0]['outcome'] === 'ok' ? ['2022-08-31', '2022-10-01'] : ['2022-03-31', '2022-05-01'], $ends, "crossing updates round $round");
        }
    }

    public function test_parallel_category_moves_never_break_the_tree(): void
    {
        $categories = app(CategoryService::class);
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $a = $categories->create($this->director, "Snacks $round", null);
            $b = $categories->create($this->director, "Drinks $round", null);
            $c = $categories->create($this->director, "Chips $round", $a->id);
            $d = $categories->create($this->director, "Potato chips $round", $c->id); // moves with C

            // A under B and B under A at once (a cycle if both won), and C (with D) moving to B or the top.
            $results = Concurrently::run([
                fn () => $categories->move($this->director, $a, $b->id),
                fn () => $categories->move($this->director, $b, $a->id),
                fn () => $categories->move($this->director, $c, $b->id),
                fn () => $categories->move($this->director, $c, null),
            ]);

            $this->assertCleanOutcomes($results, "category round $round");
            $this->assertSame([], CategoryTree::inconsistencies(), "Tree inconsistent after round $round");
            $parent = ProductCategory::query()->whereKey([$a->id, $b->id, $c->id, $d->id])->pluck('parent_id', 'id')->all();
            $this->assertCount(4, $parent, "A category disappeared in round $round");
            $ok = array_map(fn (array $r): bool => $r['outcome'] === 'ok', $results);
            $this->assertFalse($ok[0] && $ok[1], "Both crossing moves won in round $round");
            // Each category ends where its successful move put it, or where it was.
            $this->assertSame($ok[0] ? $b->id : null, $parent[$a->id], "A in round $round");
            $this->assertSame($ok[1] ? $a->id : null, $parent[$b->id], "B in round $round");
            $cTargets = array_values(array_filter([$ok[2] ? $b->id : false, $ok[3] ? null : false], fn ($t) => $t !== false));
            $this->assertContains($parent[$c->id], $cTargets === [] ? [$a->id] : $cTargets, "C in round $round");
            $this->assertSame($c->id, $parent[$d->id], "D in round $round");
        }
    }

    public function test_tagging_different_competitors_with_one_tag_in_parallel_succeeds(): void
    {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $tag = app(TagService::class)->create($this->director, 'segment', "Value $round");
            $competitors = array_map(fn (int $i) => $this->competitor("Value $round-$i"), range(1, self::WORKERS));

            // Nothing conflicts here, so every request must succeed (no lock may turn into a deadlock).
            $results = Concurrently::run(array_map(fn (Competitor $c) => fn () => app(CompetitorTagService::class)->tag($this->director, $c, $tag->id), $competitors));
            $this->assertSame(array_fill(0, self::WORKERS, 'ok'), array_column($results, 'outcome'), "round $round: ".json_encode($results));
            $this->assertSame(self::WORKERS, DB::table('taggables')->where('tag_id', $tag->id)->count());
        }
    }

    public function test_removal_guards_hold_against_parallel_use(): void
    {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            // A brand is removed while products are created with it: removed and no products, or kept with them.
            $brand = app(BrandService::class)->create($this->director, null, "House $round");
            $results = Concurrently::run([
                fn () => app(BrandService::class)->remove($this->director, $brand),
                ...array_map(fn (int $i) => fn () => app(ProductService::class)->create($this->director, [
                    'name' => "Product $round-$i", 'brand' => $brand->ulid, 'classification' => 'internal',
                ]), range(1, self::WORKERS - 1)),
            ]);
            $this->assertCleanOutcomes($results, "brand round $round");
            $products = Product::query()->where('brand_id', $brand->id)->count();
            $kept = Brand::query()->whereKey($brand->id)->exists();
            $this->assertSame($kept, $results[0]['outcome'] !== 'ok', "Brand round $round: the removal's outcome and the row disagree");
            $this->assertSame($this->successes(array_slice($results, 1)), $products, "Brand round $round: successful creations and rows disagree");
            $this->assertTrue($kept ? $products > 0 : $products === 0, "Brand round $round: kept=$kept, products=$products");

            // A tag is removed while competitors are tagged with it: the same either-or.
            $tag = app(TagService::class)->create($this->director, 'segment', "Premium $round");
            $competitors = array_map(fn (int $i) => $this->competitor("Tagged $round-$i"), range(1, self::WORKERS - 1));
            $results = Concurrently::run([
                fn () => app(TagService::class)->remove($this->director, $tag),
                ...array_map(fn (Competitor $c) => fn () => app(CompetitorTagService::class)->tag($this->director, $c, $tag->id), $competitors),
            ]);
            $this->assertCleanOutcomes($results, "tag round $round");
            $links = DB::table('taggables')->where('tag_id', $tag->id)->count();
            $kept = Tag::query()->whereKey($tag->id)->exists();
            $this->assertSame($kept, $results[0]['outcome'] !== 'ok', "Tag round $round: the removal's outcome and the row disagree");
            $this->assertSame($this->successes(array_slice($results, 1)), $links, "Tag round $round: successful taggings and links disagree");
            foreach ($competitors as $i => $competitor) {
                $linked = DB::table('taggables')->where('tag_id', $tag->id)->where('competitor_id', $competitor->id)->exists();
                $this->assertSame($results[$i + 1]['outcome'] === 'ok', $linked, "Tag round $round, competitor $i");
            }
            $this->assertTrue($kept ? $links > 0 : $links === 0, "Tag round $round: kept=$kept, links=$links");

            // A currency's decimal places change while markets start using it: a change after a market exists is refused.
            $code = 'X'.chr(64 + $round).'Z';
            $currency = app(CurrencyService::class)->create($this->director, $code, "Test units $round", 2);
            $results = Concurrently::run([
                fn () => app(CurrencyService::class)->update($this->director, $currency, "Test units $round", 3),
                ...array_map(fn (int $i) => fn () => app(MarketService::class)->create($this->director, [
                    'code' => "M$round$i", 'name' => "Market $round-$i", 'country' => 'PH', 'currency' => $code,
                ]), range(1, self::WORKERS - 1)),
            ]);
            $this->assertCleanOutcomes($results, "currency round $round");
            $markets = Market::query()->where('default_currency', $code)->pluck('id');
            $this->assertSame($this->successes(array_slice($results, 1)), $markets->count(), "Currency round $round: successful markets and rows disagree");
            $this->assertSame($results[0]['outcome'] === 'ok' ? 3 : 2, Currency::query()->findOrFail($code)->minor_units, "Currency round $round: the update's outcome and the row disagree");
            // A refused change must have met a market; a successful one must precede every market (audit IDs follow
            // the lock order: a market's insert waits for the currency lock, and the change waits for the market's).
            $changed = DB::table('audit_events')->where('action', 'currency.updated')->where('context->currency', strtolower($code))->max('id');
            $firstMarket = DB::table('audit_events')->where('action', 'market.created')->whereIn('subject_id', $markets)->min('id');
            $this->assertTrue($results[0]['outcome'] === 'ok' ? ($firstMarket === null || $changed < $firstMarket) : $markets->isNotEmpty(), "Currency round $round");
        }
    }

    /** @param  list<array{outcome: string, message: string}>  $results */
    private function assertOneWinner(array $results, string $scenario): void
    {
        $this->assertCleanOutcomes($results, $scenario);
        $this->assertSame(1, $this->successes($results), "$scenario: ".json_encode($results));
    }

    /**
     * Every request succeeded or was refused with a validation error: no database error, timeout or crash.
     *
     * @param  list<array{outcome: string, message: string}>  $results
     */
    private function assertCleanOutcomes(array $results, string $scenario): void
    {
        foreach ($results as $i => $result) {
            $this->assertContains($result['outcome'], ['ok', ValidationException::class], "$scenario, request $i: {$result['outcome']} {$result['message']}");
        }
    }

    /** @param  list<array{outcome: string, message: string}>  $results */
    private function successes(array $results): int
    {
        return count(array_filter($results, fn (array $r): bool => $r['outcome'] === 'ok'));
    }

    private function relationshipCount(Competitor $parent, Competitor $child, string $type): int
    {
        return CompetitorRelationship::query()->where('parent_competitor_id', $parent->id)->where('child_competitor_id', $child->id)->where('relation_type', $type)->count();
    }

    /**
     * Four spellings of one name that the registry treats as the same: as is, upper case, lower case with extra
     * spaces, and as is again.
     *
     * @return list<string>
     */
    private function spellings(string $name): array
    {
        return [$name, mb_strtoupper($name), ' '.str_replace(' ', '  ', mb_strtolower($name)).' ', $name];
    }

    private function market(string $code): Market
    {
        $market = new Market;
        $market->forceFill(['ulid' => (string) Str::ulid(), 'code' => $code, 'name' => "Synthetic $code", 'country' => 'PH', 'default_currency' => 'USD'])->save();

        return $market;
    }

    private function competitor(string $name): Competitor
    {
        $competitor = new Competitor;
        $competitor->forceFill([
            'ulid' => (string) Str::ulid(), 'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)), 'lifecycle' => 'active',
            'classification' => Classification::Internal, 'created_by' => $this->director->id,
        ])->save();

        return $competitor;
    }
}
