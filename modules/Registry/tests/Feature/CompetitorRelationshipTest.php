<?php

namespace Modules\Registry\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Modules\Registry\Actions\CompetitorRelationshipService;
use Modules\Registry\Models\Competitor;
use Modules\Registry\Models\CompetitorRelationship;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-21 / docs/02 §3.3 / docs/03 Registry: relationships link two competitors over a period; no self-relationship and
 * no backwards period (application and CHECKs); ranges for the same pair and type do not overlap; both competitors
 * must be visible to see or change one; changes are audited with the higher classification of the two.
 */
class CompetitorRelationshipTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $analyst;

    private Competitor $acme;

    private Competitor $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->acme = $this->competitor('Acme Holdings');
        $this->beta = $this->competitor('Beta Snacks', Classification::Confidential);
    }

    public function test_an_analyst_records_a_relationship_and_it_is_audited(): void
    {
        $this->actingAs($this->analyst)->post("/competitors/{$this->beta->ulid}/relationships", $this->form($this->acme, [
            'role' => 'child', 'valid_from' => '2020-03-15', 'valid_from_precision' => 'month', 'notes' => '  Synthetic test entry.  ',
        ]))->assertSessionHasNoErrors();

        $relationship = CompetitorRelationship::query()->sole();
        $this->assertSame([$this->acme->id, $this->beta->id, 'subsidiary', '2020-03-01', 'month', null, 'Synthetic test entry.', $this->analyst->id], [
            $relationship->parent_competitor_id, $relationship->child_competitor_id, $relationship->relation_type, $relationship->valid_from->toDateString(),
            $relationship->valid_from_precision, $relationship->valid_to, $relationship->notes, $relationship->created_by,
        ]);
        $audit = DB::table('audit_events')->where('action', 'competitor.relationship_added')->sole();
        // The higher classification of the two: only viewers who can see both read it in the audit log.
        $this->assertSame([$this->analyst->id, $relationship->ulid, 'confidential'], [$audit->actor_id, $audit->subject_ulid, $audit->classification]);
        $this->assertEquals(['parent' => $this->acme->ulid, 'child' => $this->beta->ulid, 'relation_type' => 'subsidiary'], json_decode((string) $audit->context, true));

        $this->actingAs($this->analyst)->get("/competitors/{$this->acme->ulid}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('relationships', [[
                'ulid' => $relationship->ulid, 'type' => 'subsidiary', 'role' => 'parent', 'other' => ['ulid' => $this->beta->ulid, 'name' => 'Beta Snacks'],
                'validFrom' => '2020-03-01', 'validFromPrecision' => 'month', 'validTo' => null, 'notes' => 'Synthetic test entry.',
            ]])
            ->where('others', [['ulid' => $this->beta->ulid, 'name' => 'Beta Snacks']]));
        $this->actingAs($this->analyst)->get("/competitors/{$this->beta->ulid}")->assertInertia(fn (AssertableInertia $page) => $page->where('relationships.0.role', 'child'));

        // A year's precision starts on 1 January.
        $this->actingAs($this->analyst)->post("/competitors/{$this->acme->ulid}/relationships", $this->form($this->beta, [
            'relation_type' => 'partner', 'valid_from' => '2019-07-04', 'valid_from_precision' => 'year', 'valid_to' => '2019-12-31',
        ]))->assertSessionHasNoErrors();
        $this->assertSame(['2019-01-01', '2019-12-31'], [CompetitorRelationship::query()->where('relation_type', 'partner')->sole()->valid_from->toDateString(), CompetitorRelationship::query()->where('relation_type', 'partner')->sole()->valid_to?->toDateString()]);
    }

    public function test_invalid_relationships_are_refused_by_the_request_and_the_service(): void
    {
        foreach ([
            'other' => $this->form($this->acme), // itself
            'valid_to' => $this->form($this->beta, ['valid_from' => '2020-05-01', 'valid_to' => '2020-04-30']),
            'valid_from' => $this->form($this->beta, ['valid_from' => '2020-02-30']),
            'valid_from ' => $this->form($this->beta, ['valid_from' => '1700-01-01']),
            'valid_from_precision' => $this->form($this->beta, ['valid_from_precision' => 'decade']),
            'relation_type' => $this->form($this->beta, ['relation_type' => 'rival']),
            'role' => $this->form($this->beta, ['role' => 'sibling']),
            'notes' => $this->form($this->beta, ['notes' => str_repeat('n', 2001)]),
        ] as $field => $form) {
            $this->actingAs($this->analyst)->post("/competitors/{$this->acme->ulid}/relationships", $form)->assertSessionHasErrors(trim($field));
        }
        // The month's start counts: an end inside the start month but before the given day is fine.
        $this->actingAs($this->analyst)->post("/competitors/{$this->acme->ulid}/relationships", $this->form($this->beta, [
            'valid_from' => '2020-03-15', 'valid_from_precision' => 'month', 'valid_to' => '2020-03-10',
        ]))->assertSessionHasNoErrors();

        $service = $this->app->make(CompetitorRelationshipService::class);
        foreach ([['parent', ['relation_type' => 'subsidiary', 'valid_from' => '2021-01-01', 'valid_to' => '2020-12-31']], ['sibling', ['relation_type' => 'subsidiary', 'valid_from' => '2022-01-01']]] as [$role, $data]) {
            try {
                $service->add($this->analyst, $this->acme, $role, $this->beta->ulid, $data);
                $this->fail('An invalid relationship was accepted.');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(1, CompetitorRelationship::query()->count());
    }

    public function test_the_database_refuses_self_relationships_backwards_periods_and_unnormalized_starts(): void
    {
        foreach ([
            'self' => ['child_competitor_id' => $this->acme->id],
            'validity' => ['valid_from' => '2020-05-01', 'valid_to' => '2020-04-30'],
            'period' => ['valid_from' => '2020-05-15', 'valid_from_precision' => 'month'],
            'period ' => ['valid_from' => '2020-05-01', 'valid_from_precision' => 'year'],
            'precision' => ['valid_from_precision' => 'decade'],
            'type' => ['relation_type' => 'rival'],
        ] as $check => $overrides) {
            try {
                $this->insert($overrides);
                $this->fail("The database accepted a row breaking competitor_relationships_{$check}_check.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('competitor_relationships_'.trim($check).'_check', $e->getMessage());
            }
        }
        $this->insert([]);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->insert(['valid_to' => '2030-01-01']); // same pair, type and start: the backstop for concurrent inserts
    }

    public function test_ranges_for_the_same_pair_and_type_do_not_overlap(): void
    {
        $gamma = $this->competitor('Gamma Foods');
        $add = fn (Competitor $competitor, string $role, Competitor $other, array $data) => $this->app->make(CompetitorRelationshipService::class)
            ->add($this->analyst, $competitor, $role, $other->ulid, $data + ['relation_type' => 'subsidiary', 'valid_from_precision' => 'day']);
        $refused = function (callable $write): void {
            try {
                $write();
                $this->fail('An overlapping relationship was accepted.');
            } catch (ValidationException $e) {
                $this->assertSame(['valid_from'], array_keys($e->errors()));
            }
        };

        $first = $add($this->acme, 'parent', $this->beta, ['valid_from' => '2010-01-01', 'valid_to' => '2014-12-31']);
        $refused(fn () => $add($this->acme, 'parent', $this->beta, ['valid_from' => '2014-12-31'])); // ends are inclusive
        $open = $add($this->acme, 'parent', $this->beta, ['valid_from' => '2015-01-01']); // adjacent
        $refused(fn () => $add($this->acme, 'parent', $this->beta, ['valid_from' => '2030-01-01'])); // inside the open range
        $refused(fn () => $add($this->beta, 'parent', $this->acme, ['valid_from' => '2016-01-01'])); // mutual ownership conflicts
        $refused(fn () => $add($this->acme, 'parent', $this->beta, ['valid_from' => '2000-01-01', 'valid_to' => '2010-01-01']));
        $add($this->acme, 'parent', $this->beta, ['valid_from' => '2000-01-01', 'valid_to' => '2009-12-31']);
        $add($this->acme, 'parent', $this->beta, ['relation_type' => 'partner', 'valid_from' => '2012-01-01']); // another type
        $add($this->acme, 'parent', $gamma, ['valid_from' => '2012-01-01']); // another pair
        // Distribution is directed: each may distribute for the other, but not twice the same way.
        $add($this->acme, 'parent', $this->beta, ['relation_type' => 'distributor', 'valid_from' => '2016-01-01']);
        $add($this->beta, 'parent', $this->acme, ['relation_type' => 'distributor', 'valid_from' => '2016-01-01']);
        $refused(fn () => $add($this->beta, 'child', $this->acme, ['relation_type' => 'distributor', 'valid_from' => '2017-01-01']));

        // Edits are checked the same way, against the others only.
        $this->actingAs($this->analyst)->put("/competitors/{$this->acme->ulid}/relationships/{$first->ulid}", ['valid_from' => '2010-01-01', 'valid_from_precision' => 'day', 'valid_to' => '2015-06-30'])->assertSessionHasErrors('valid_from');
        $this->actingAs($this->analyst)->put("/competitors/{$this->acme->ulid}/relationships/{$first->ulid}", ['valid_from' => '2010-01-01', 'valid_from_precision' => 'day', 'valid_to' => '2014-12-31'])->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('audit_events')->where('action', 'competitor.relationship_updated')->count()); // nothing changed
        $this->actingAs($this->analyst)->put("/competitors/{$this->beta->ulid}/relationships/{$open->ulid}", ['valid_from' => '2015-01-01', 'valid_from_precision' => 'day', 'valid_to' => '2024-06-30', 'notes' => 'Sold.'])->assertSessionHasNoErrors();
        $this->assertSame(['2024-06-30', 'Sold.'], [$open->refresh()->valid_to?->toDateString(), $open->notes]);
        $audit = DB::table('audit_events')->where('action', 'competitor.relationship_updated')->sole();
        $this->assertSame([$open->ulid, 'confidential'], [$audit->subject_ulid, $audit->classification]);
        $this->assertEquals(['fields' => ['valid_to', 'notes']], json_decode((string) $audit->context, true));
        $add($this->acme, 'parent', $this->beta, ['valid_from' => '2030-01-01']); // free again after the end date
    }

    public function test_both_competitors_must_be_visible_to_see_or_change_a_relationship(): void
    {
        $reader = $this->createUser('reader@example.test', 'exec_reader', clearance: Classification::Confidential);
        $hidden = $this->competitor('Hidden Holdings', Classification::Restricted); // restricted needs a grant
        $insider = $this->createUser('insider@example.test', 'ci_analyst', clearance: Classification::Restricted);
        DB::table('access_grants')->insert([
            'ulid' => (string) Str::ulid(), 'user_id' => $insider->id, 'scope_type' => 'all', 'max_classification' => 'restricted',
            'granted_by' => $this->analyst->id, 'reason' => 'Synthetic test grant', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $visible = $this->insert([]);
        $secret = $this->insert(['parent_competitor_id' => $hidden->id, 'child_competitor_id' => $this->acme->id, 'valid_from' => '2018-01-01']);

        $page = $this->actingAs($this->analyst)->get("/competitors/{$this->acme->ulid}")->assertOk();
        $this->assertSame([$visible->ulid], array_column($page->inertiaProps('relationships'), 'ulid'));
        $this->assertStringNotContainsString('Hidden Holdings', $page->getContent() ?: '');
        $this->assertStringNotContainsString($hidden->ulid, $page->getContent() ?: '');
        $this->assertStringNotContainsString($secret->ulid, $page->getContent() ?: '');
        $this->assertSame([$this->beta->ulid], array_column($page->inertiaProps('others'), 'ulid'));
        $this->actingAs($insider)->get("/competitors/{$this->acme->ulid}")->assertInertia(fn (AssertableInertia $page) => $page->count('relationships', 2));
        // Readers see relationships but are offered no candidates and cannot change anything.
        $this->actingAs($reader)->get("/competitors/{$this->acme->ulid}")->assertInertia(fn (AssertableInertia $page) => $page->count('relationships', 1)->where('others', []));
        $this->actingAs($reader)->post("/competitors/{$this->acme->ulid}/relationships", $this->form($this->beta))->assertForbidden();
        $this->actingAs($reader)->put("/competitors/{$this->acme->ulid}/relationships/{$visible->ulid}", ['valid_from' => '2001-01-01', 'valid_from_precision' => 'day'])->assertForbidden();
        $this->actingAs($reader)->delete("/competitors/{$this->acme->ulid}/relationships/{$visible->ulid}")->assertForbidden();

        // A hidden competitor is "not found" as a target, like one that does not exist, and its relationships are 404.
        foreach ([$hidden->ulid, (string) Str::ulid()] as $other) {
            $this->actingAs($this->analyst)->post("/competitors/{$this->acme->ulid}/relationships", ['other' => $other] + $this->form($this->beta))
                ->assertSessionHasErrors(['other' => 'Choose a competitor you can see.']);
        }
        $this->actingAs($this->analyst)->put("/competitors/{$this->acme->ulid}/relationships/{$secret->ulid}", ['valid_from' => '2018-01-01', 'valid_from_precision' => 'day', 'valid_to' => '2019-01-01'])->assertNotFound();
        $this->actingAs($this->analyst)->delete("/competitors/{$this->acme->ulid}/relationships/{$secret->ulid}")->assertNotFound();
        $this->actingAs($this->analyst)->delete("/competitors/{$hidden->ulid}/relationships/{$secret->ulid}")->assertNotFound();
        // A relationship is reached only through one of its own competitors.
        $gamma = $this->competitor('Gamma Foods');
        $this->actingAs($this->analyst)->delete("/competitors/{$gamma->ulid}/relationships/{$visible->ulid}")->assertNotFound();

        $this->assertSame(2, CompetitorRelationship::query()->count());
        $this->assertNull($secret->refresh()->valid_to);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'like', 'competitor.relationship%')->count());
    }

    public function test_removing_a_relationship_is_audited(): void
    {
        $relationship = $this->insert(['relation_type' => 'brand_owner']);

        $this->actingAs($this->analyst)->delete("/competitors/{$this->beta->ulid}/relationships/{$relationship->ulid}")->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->analyst)->delete("/competitors/{$this->beta->ulid}/relationships/{$relationship->ulid}")->assertNotFound();
        // A second removal through a stale copy (e.g. a concurrent request) changes nothing and is not audited.
        $this->app->make(CompetitorRelationshipService::class)->remove($this->analyst, $this->beta, $relationship);

        $this->assertSame(0, CompetitorRelationship::query()->count());
        $audit = DB::table('audit_events')->where('action', 'competitor.relationship_removed')->sole();
        $this->assertSame([$this->analyst->id, $relationship->ulid, 'confidential'], [$audit->actor_id, $audit->subject_ulid, $audit->classification]);
        $this->assertEquals(['parent' => $this->acme->ulid, 'child' => $this->beta->ulid, 'relation_type' => 'brand_owner'], json_decode((string) $audit->context, true));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(Competitor $other, array $overrides = []): array
    {
        return $overrides + ['role' => 'parent', 'other' => $other->ulid, 'relation_type' => 'subsidiary', 'valid_from' => '2020-01-01', 'valid_from_precision' => 'day', 'valid_to' => null, 'notes' => null];
    }

    /**
     * A row written directly (acme → beta subsidiary from 2020-01-01 unless overridden).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function insert(array $overrides): CompetitorRelationship
    {
        $ulid = (string) Str::ulid();
        DB::table('competitor_relationships')->insert($overrides + [
            'ulid' => $ulid, 'parent_competitor_id' => $this->acme->id, 'child_competitor_id' => $this->beta->id, 'relation_type' => 'subsidiary',
            'valid_from' => '2020-01-01', 'valid_from_precision' => 'day', 'created_by' => $this->analyst->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return CompetitorRelationship::query()->where('ulid', $ulid)->sole();
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
