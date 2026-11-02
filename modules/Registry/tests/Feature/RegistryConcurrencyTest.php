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
 * scenario checks invariants that must hold whatever the interleaving: one winner for a unique name, no overlapping
 * relationships, a consistent category tree, removal guards against parallel use. And every request ends either
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
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $competitor = $this->competitor("Acme $round");

            $this->assertOneWinner(Concurrently::run(array_map(fn (string $alias) => fn () => app(CompetitorAliasService::class)
                ->add($this->director, $competitor, $alias, 'trade'), $this->spellings('Acme Brands'))), 'alias');
            $this->assertSame(1, CompetitorAlias::query()->where('competitor_id', $competitor->id)->count());

            $this->assertOneWinner(Concurrently::run(array_map(fn (string $name) => fn () => app(BrandService::class)
                ->create($this->director, null, $name), $this->spellings("Round $round Brands"))), 'own brand');
            $this->assertSame(1, Brand::query()->whereNull('competitor_id')->where('name', "Round $round Brands")->count());

            $this->assertOneWinner(Concurrently::run(array_map(fn (string $name) => fn () => app(RegionService::class)
                ->create($this->director, $market, $name, 'region', null), $this->spellings("North $round"))), 'top-level region');
            $this->assertSame(1, Region::query()->where('market_id', $market->id)->where('name', "North $round")->count());

            $this->assertOneWinner(Concurrently::run(array_map(fn (string $name) => fn () => app(CategoryService::class)
                ->create($this->director, $name, null), $this->spellings("Beverages $round"))), 'top-level category');
            $this->assertSame(1, ProductCategory::query()->whereNull('parent_id')->where('name', "Beverages $round")->count());
        }
    }

    public function test_overlapping_relationships_are_refused_under_parallel_requests(): void
    {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $parent = $this->competitor("Parent $round");
            $child = $this->competitor("Child $round");

            // Four open-ended ranges for the same pair and type: every two overlap, so one may exist.
            $results = Concurrently::run(array_map(fn (int $month) => fn () => app(CompetitorRelationshipService::class)->add(
                $this->director, $parent, 'parent', $child->ulid, ['relation_type' => 'subsidiary', 'valid_from' => sprintf('2020-%02d-01', $month)],
            ), range(1, self::WORKERS)));

            $this->assertOneWinner($results, "relationship round $round");
            $this->assertSame(1, CompetitorRelationship::query()->where('parent_competitor_id', $parent->id)->where('child_competitor_id', $child->id)->count());
        }
    }

    public function test_parallel_category_moves_never_break_the_tree(): void
    {
        $categories = app(CategoryService::class);
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $a = $categories->create($this->director, "Snacks $round", null);
            $b = $categories->create($this->director, "Drinks $round", null);
            $c = $categories->create($this->director, "Chips $round", $a->id);

            // A under B and B under A at once (a cycle if both won), and C moving between them.
            $results = Concurrently::run([
                fn () => $categories->move($this->director, $a, $b->id),
                fn () => $categories->move($this->director, $b, $a->id),
                fn () => $categories->move($this->director, $c, $b->id),
                fn () => $categories->move($this->director, $c, null),
            ]);

            $this->assertCleanOutcomes($results, "category round $round");
            $this->assertSame([], CategoryTree::inconsistencies(), "Tree inconsistent after round $round");
            $parents = ProductCategory::query()->whereKey([$a->id, $b->id])->pluck('parent_id', 'id');
            $this->assertFalse($parents[$a->id] === $b->id && $parents[$b->id] === $a->id, "Both moves won in round $round");
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
            $this->assertTrue($kept ? $results[0]['outcome'] !== 'ok' : $products === 0, "Brand round $round: kept=$kept, products=$products");
            $this->assertSame($products, count(array_filter(array_slice($results, 1), fn (array $r): bool => $r['outcome'] === 'ok')));

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
            $this->assertTrue($kept ? $results[0]['outcome'] !== 'ok' : $links === 0, "Tag round $round: kept=$kept, links=$links");

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
            $changed = DB::table('audit_events')->where('action', 'currency.updated')->where('context->currency', strtolower($code))->max('id');
            $firstMarket = DB::table('audit_events')->where('action', 'market.created')->whereIn('subject_id', Market::query()->where('default_currency', $code)->pluck('id'))->min('id');
            $this->assertFalse($changed !== null && $firstMarket !== null && $changed > $firstMarket, "Currency round $round: decimal places changed after a market used the currency");
        }
    }

    /** @param  list<array{outcome: string, message: string}>  $results */
    private function assertOneWinner(array $results, string $scenario): void
    {
        $this->assertCleanOutcomes($results, $scenario);
        $this->assertSame(1, count(array_filter($results, fn (array $r): bool => $r['outcome'] === 'ok')), "$scenario: ".json_encode($results));
    }

    /**
     * Every request succeeded or was refused with a validation error: no database error reached the user.
     *
     * @param  list<array{outcome: string, message: string}>  $results
     */
    private function assertCleanOutcomes(array $results, string $scenario): void
    {
        foreach ($results as $i => $result) {
            $this->assertContains($result['outcome'], ['ok', ValidationException::class], "$scenario, request $i: {$result['outcome']} {$result['message']}");
        }
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
