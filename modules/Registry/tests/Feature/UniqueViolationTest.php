<?php

namespace Modules\Registry\Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Modules\Platform\Database\UniqueViolation;
use Modules\Registry\Actions\BrandService;
use Modules\Registry\Actions\CategoryService;
use Modules\Registry\Actions\ChannelService;
use Modules\Registry\Actions\CompetitorService;
use Modules\Registry\Actions\CurrencyService;
use Modules\Registry\Actions\MarketService;
use Modules\Registry\Actions\TagService;
use Symfony\Component\Uid\Ulid;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * A duplicate becomes "already taken" only when it is the duplicate the service expects (its name or code key). Any
 * other unique violation in the same transaction surfaces as itself, so a failure is never disguised as a validation
 * message. Two unexplained "already taken" failures in the parallel registry tests (2026-11-03) had this shape; here
 * the other violation is forced on the audit row's ULID.
 */
class UniqueViolationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
    }

    protected function tearDown(): void
    {
        Str::createUlidsNormally();

        parent::tearDown();
    }

    public function test_the_expected_duplicate_is_still_a_validation_error(): void
    {
        app(CurrencyService::class)->create($this->director, 'QQQ', 'Probe units', 2);
        app(CategoryService::class)->create($this->director, 'Snacks', null);
        app(TagService::class)->create($this->director, 'segment', 'Value');

        foreach ([
            ['code', fn () => app(CurrencyService::class)->create($this->director, 'qqq', 'Other units', 2)],
            ['name', fn () => app(CategoryService::class)->create($this->director, 'SNACKS', null)],
            ['name', fn () => app(TagService::class)->create($this->director, 'segment', 'value')],
        ] as [$field, $call]) {
            try {
                $call();
                $this->fail("Expected a validation error for $field.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
    }

    public function test_any_other_duplicate_surfaces_as_itself(): void
    {
        // Every new row in this test gets the same ULID, so the second audit row of a create clashes on its ULID key.
        $fixed = Ulid::fromString((string) Str::ulid());
        Str::createUlidsUsing(fn () => $fixed);
        app(CurrencyService::class)->create($this->director, 'QQA', 'Probe units', 2);

        // Each service's expected key, which the forced duplicate is not.
        foreach ([
            [fn () => app(CurrencyService::class)->create($this->director, 'QQB', 'Other units', 2), 'currencies', 'PRIMARY'],
            [fn () => app(CategoryService::class)->create($this->director, 'Drinks', null), 'product_categories', 'product_categories_parent_key_name_unique'],
            [fn () => app(TagService::class)->create($this->director, 'segment', 'Premium'), 'tags', 'tags_taxonomy_name_unique'],
            [fn () => app(ChannelService::class)->create($this->director, 'Online shops', 'ecommerce'), 'channels', 'channels_name_unique'],
            [fn () => app(BrandService::class)->create($this->director, null, 'House brand'), 'brands', 'brands_competitor_key_name_unique'],
            [fn () => app(MarketService::class)->create($this->director, ['code' => 'MK1', 'name' => 'Probe market', 'country' => 'PH', 'currency' => 'PHP']), 'markets', 'markets_code_unique'],
            // The slug retry must not consume its attempts on another key (TASK-0067).
            [fn () => app(CompetitorService::class)->create($this->director, ['name' => 'Acme', 'classification' => 'internal']), 'competitors', 'competitors_slug_unique'],
        ] as [$call, $table, $expected]) {
            try {
                $call();
                $this->fail("Expected the forced duplicate to surface instead of [$table.$expected].");
            } catch (UniqueConstraintViolationException $e) {
                $this->assertFalse(UniqueViolation::on($e, $table, $expected), $e->getMessage());
                $this->assertMatchesRegularExpression("/for key '(audit_events\\.audit_events_ulid_unique|$table\\.{$table}_ulid_unique)'\\z/", (string) ($e->errorInfo[2] ?? ''));
            }
        }
        $this->assertSame([0, 0, 0], [DB::table('competitors')->count(), DB::table('markets')->count(), DB::table('brands')->count()]);
    }

    public function test_the_index_is_read_from_the_error(): void
    {
        app(CurrencyService::class)->create($this->director, 'QQC', 'Probe units', 2);
        try {
            DB::table('currencies')->insert(['code' => 'QQC', 'name' => 'Again', 'minor_units' => 2]);
            $this->fail('Expected a duplicate key.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertTrue(UniqueViolation::on($e, 'currencies', 'PRIMARY'));
            $this->assertFalse(UniqueViolation::on($e, 'currencies', 'currencies_name_unique'));
            $this->assertFalse(UniqueViolation::on($e, 'markets', 'PRIMARY'));
        }
    }
}
