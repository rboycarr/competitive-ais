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
use Modules\Registry\Actions\CategoryService;
use Modules\Registry\Actions\CurrencyService;
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

        foreach ([
            fn () => app(CurrencyService::class)->create($this->director, 'QQB', 'Other units', 2),
            fn () => app(CategoryService::class)->create($this->director, 'Drinks', null),
            fn () => app(TagService::class)->create($this->director, 'segment', 'Premium'),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected the audit row\'s duplicate ULID to surface.');
            } catch (UniqueConstraintViolationException $e) {
                $this->assertTrue(UniqueViolation::on($e, 'audit_events', 'audit_events_ulid_unique'), $e->getMessage());
            }
        }
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
