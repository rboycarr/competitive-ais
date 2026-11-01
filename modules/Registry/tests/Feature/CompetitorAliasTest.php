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
use Modules\Registry\Actions\CompetitorAliasService;
use Modules\Registry\Models\Competitor;
use Modules\Registry\Models\CompetitorAlias;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-21 / docs/02 §3.3 / docs/03 Registry: aliases are normalized, unique per competitor ignoring case and accents
 * (application and UNIQUE index), visible with their competitor, changed by competitor.manage holders who can see it,
 * and audited without their text.
 */
class CompetitorAliasTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
    }

    public function test_an_analyst_adds_a_normalized_alias_and_it_is_audited(): void
    {
        $competitor = $this->competitor('Acme Foods', Classification::Confidential);

        $this->actingAs($this->analyst)->post("/competitors/{$competitor->ulid}/aliases", ['alias' => "  ACME \t  Brands\u{00A0}Co\n ", 'alias_type' => 'brand'])->assertSessionHasNoErrors();

        $alias = CompetitorAlias::query()->sole();
        $this->assertSame(['ACME Brands Co', 'brand', $competitor->id], [$alias->alias, $alias->alias_type, $alias->competitor_id]);
        $audit = DB::table('audit_events')->where('action', 'competitor.alias_added')->sole();
        $this->assertSame([$this->analyst->id, $competitor->ulid, 'confidential'], [$audit->actor_id, $audit->subject_ulid, $audit->classification]);
        $this->assertEquals(['alias_id' => $alias->id, 'alias_type' => 'brand'], json_decode((string) $audit->context, true));
        $this->assertStringNotContainsStringIgnoringCase('acme', (string) $audit->context); // no user input in audit context

        $this->actingAs($this->analyst)->get("/competitors/{$competitor->ulid}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('aliases', [['id' => $alias->id, 'alias' => 'ACME Brands Co', 'type' => 'brand']])
            ->where('aliasTypes', CompetitorAlias::TYPES));
    }

    public function test_aliases_are_unique_per_competitor_ignoring_case_and_accents(): void
    {
        $acme = $this->competitor('Acme Foods');
        $beta = $this->competitor('Beta Snacks');

        $this->actingAs($this->analyst)->post("/competitors/{$acme->ulid}/aliases", ['alias' => 'Acmé Brands', 'alias_type' => 'trade'])->assertSessionHasNoErrors();
        foreach (['acme brands', 'ACME  BRANDS', ' Acmé Brands '] as $duplicate) {
            $this->actingAs($this->analyst)->post("/competitors/{$acme->ulid}/aliases", ['alias' => $duplicate, 'alias_type' => 'brand'])->assertSessionHasErrors('alias');
        }
        // Another competitor may share it.
        $this->actingAs($this->analyst)->post("/competitors/{$beta->ulid}/aliases", ['alias' => 'Acme Brands', 'alias_type' => 'former'])->assertSessionHasNoErrors();
        $this->assertSame(2, CompetitorAlias::query()->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'competitor.alias_added')->where('subject_ulid', $acme->ulid)->count());

        // The index decides, so concurrent duplicates are refused as well.
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('competitor_aliases')->insert(['competitor_id' => $acme->id, 'alias' => 'ACME BRANDS', 'alias_type' => 'brand', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_invalid_aliases_are_refused_by_the_request_the_service_and_the_database(): void
    {
        $acme = $this->competitor('Acme Foods');
        foreach ([
            'alias' => ['alias' => '   ', 'alias_type' => 'brand'],
            'alias_type' => ['alias' => 'Acme', 'alias_type' => 'nickname'],
            'alias ' => ['alias' => str_repeat('a', 256), 'alias_type' => 'brand'],
        ] as $field => $form) {
            $this->actingAs($this->analyst)->post("/competitors/{$acme->ulid}/aliases", $form)->assertSessionHasErrors(trim($field));
        }

        $service = $this->app->make(CompetitorAliasService::class);
        foreach ([["\u{00A0}\u{0007}\n", 'brand'], ['Acme', 'nickname']] as [$alias, $type]) {
            try {
                $service->add($this->analyst, $acme, $alias, $type);
                $this->fail("Alias [$alias] of type [$type] was accepted.");
            } catch (ValidationException) {
            }
        }

        foreach ([[' padded', 'brand'], ['two  spaces', 'brand'], ["tab\there", 'brand'], ['', 'brand'], ['Acme', 'nickname']] as [$alias, $type]) {
            try {
                DB::table('competitor_aliases')->insert(['competitor_id' => $acme->id, 'alias' => $alias, 'alias_type' => $type, 'created_at' => now(), 'updated_at' => now()]);
                $this->fail("The database accepted alias [$alias] of type [$type].");
            } catch (QueryException $e) {
                $this->assertStringContainsString('competitor_aliases_', $e->getMessage());
            }
        }
        $this->assertSame(0, CompetitorAlias::query()->count());
    }

    public function test_changes_need_competitor_manage_and_a_visible_competitor(): void
    {
        $reader = $this->createUser('reader@example.test', 'exec_reader');
        $acme = $this->competitor('Acme Foods');
        $alias = $this->alias($acme, 'Acme Brands');
        $hidden = $this->competitor('Hidden Holdings', Classification::Restricted); // restricted needs a grant
        $hiddenAlias = $this->alias($hidden, 'Hidden Brands');

        $this->actingAs($reader)->get("/competitors/{$acme->ulid}")->assertInertia(fn (AssertableInertia $page) => $page->where('aliases.0.alias', 'Acme Brands'));
        $this->actingAs($reader)->post("/competitors/{$acme->ulid}/aliases", ['alias' => 'Other', 'alias_type' => 'brand'])->assertForbidden();
        $this->actingAs($reader)->delete("/competitors/{$acme->ulid}/aliases/{$alias->id}")->assertForbidden();
        $this->actingAs($this->analyst)->post("/competitors/{$hidden->ulid}/aliases", ['alias' => 'Other', 'alias_type' => 'brand'])->assertNotFound();
        $this->actingAs($this->analyst)->delete("/competitors/{$hidden->ulid}/aliases/{$hiddenAlias->id}")->assertNotFound();

        $this->assertSame(['Acme Brands', 'Hidden Brands'], CompetitorAlias::query()->orderBy('id')->pluck('alias')->all());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'like', 'competitor.alias%')->count());
    }

    public function test_removing_an_alias_is_scoped_to_its_competitor_and_audited(): void
    {
        $acme = $this->competitor('Acme Foods');
        $beta = $this->competitor('Beta Snacks');
        $betaAlias = $this->alias($beta, 'Beta Brands');
        $alias = $this->alias($acme, 'Acme Brands', 'abbreviation');

        $this->actingAs($this->analyst)->delete("/competitors/{$acme->ulid}/aliases/{$betaAlias->id}")->assertNotFound();
        $this->actingAs($this->analyst)->delete("/competitors/{$acme->ulid}/aliases/{$alias->id}")->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->analyst)->delete("/competitors/{$acme->ulid}/aliases/{$alias->id}")->assertNotFound();

        $this->assertSame(['Beta Brands'], CompetitorAlias::query()->pluck('alias')->all());
        $audit = DB::table('audit_events')->where('action', 'competitor.alias_removed')->sole();
        $this->assertSame([$acme->ulid, 'internal'], [$audit->subject_ulid, $audit->classification]);
        $this->assertEquals(['alias_id' => $alias->id, 'alias_type' => 'abbreviation'], json_decode((string) $audit->context, true));
    }

    private function alias(Competitor $competitor, string $alias, string $type = 'brand'): CompetitorAlias
    {
        $row = new CompetitorAlias;
        $row->forceFill(['competitor_id' => $competitor->id, 'alias' => $alias, 'alias_type' => $type])->save();

        return $row;
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
