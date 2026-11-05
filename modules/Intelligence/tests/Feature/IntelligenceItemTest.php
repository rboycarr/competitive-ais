<?php

namespace Modules\Intelligence\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\User;
use Modules\Intelligence\Actions\IntelligenceClaimService;
use Modules\Intelligence\Actions\IntelligenceItemService;
use Modules\Intelligence\Models\IntelligenceClaim;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Platform\Classification;
use Modules\Registry\Actions\CompetitorService;
use Modules\Registry\Models\Competitor;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-40 / docs/03 Intelligence / docs/02 §3.6 / ADR-0010: an analyst writes an intelligence item about competitors,
 * products and markets, with typed claims rated on the Admiralty scale by a person. Drafts are seen only by their
 * author, owner, assigned reviewer and intelligence.review holders; every item is scoped by its subjects (all-of,
 * the shared matrix). Content changes only while editable, by the author or owner, from the version read; every change
 * raises the version and is audited without user text. The summary is Markdown shown through an allowlist.
 */
class IntelligenceItemTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $analyst;

    private int $acme;

    private int $globex;

    private int $market;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->grantAll($this->analyst, 'confidential');
        $this->acme = $this->competitor('Acme');
        $this->globex = $this->competitor('Globex');
        $this->market = (int) DB::table('markets')->insertGetId([
            'ulid' => (string) Str::ulid(), 'code' => 'PH-NCR', 'name' => 'Metro Manila', 'country' => 'PH', 'default_currency' => 'USD',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_an_analyst_writes_a_draft_about_competitors_products_and_markets(): void
    {
        $product = $this->product('Globex Widget', $this->globex);

        $response = $this->actingAs($this->analyst)->post('/intelligence', $this->fields([
            'competitors' => [$this->ulid('competitors', $this->acme)],
            'products' => [$this->ulid('products', $product)],
            'markets' => [$this->ulid('markets', $this->market)],
            'high_impact' => true,
            'observed_at' => '2026-11-01T10:00',
        ]));

        $item = IntelligenceItem::query()->sole();
        $response->assertRedirect("/intelligence/{$item->ulid}");
        $this->assertSame(['draft', 1, $this->analyst->id, $this->analyst->id, true], [$item->status, $item->version, $item->author_id, $item->owner_id, $item->high_impact]);
        $this->assertSame('2026-11-01 10:00:00', $item->observed_at?->format('Y-m-d H:i:s'));
        // The product's competitor is a subject (scope) as well.
        $this->assertSame([$this->acme, $this->globex], $item->competitorIds());
        $this->assertSame([$product], $item->productIds());
        $this->assertSame([$this->market], $item->marketIds());
        $audit = DB::table('audit_events')->where('action', 'intelligence.created')->sole();
        $this->assertSame($item->ulid, $audit->subject_ulid);
        $this->assertSame(['competitors' => 2, 'high_impact' => true, 'item_type' => 'product_change', 'markets' => 1, 'products' => 1], $this->sorted(json_decode((string) $audit->context, true)));
        $this->assertStringNotContainsString('Widget launch', (string) $audit->context);
    }

    public function test_the_writer_must_choose_subjects_they_can_see_and_a_level_they_could_read_back(): void
    {
        $restricted = $this->competitor('Hidden Co', 'restricted');
        $archived = $this->competitor('Gone Co');
        DB::table('competitors')->where('id', $archived)->update(['lifecycle' => 'archived']);
        $service = app(IntelligenceItemService::class);
        foreach ([
            'subjects' => [],
            'competitors' => ['competitors' => [$this->ulid('competitors', $restricted)]],
            'competitors ' => ['competitors' => [(string) Str::ulid()]],
            'competitors  ' => ['competitors' => [$this->ulid('competitors', $archived)]],
            'classification' => ['competitors' => [$this->ulid('competitors', $this->acme)], 'classification' => 'restricted'],
            'observed_at' => ['competitors' => [$this->ulid('competitors', $this->acme)], 'observed_at' => now('UTC')->addDay()->toIso8601String()],
            'title' => ['competitors' => [$this->ulid('competitors', $this->acme)], 'title' => "  \n "],
            'summary' => ['competitors' => [$this->ulid('competitors', $this->acme)], 'summary' => str_repeat('x', IntelligenceItemService::MAX_SUMMARY + 1)],
        ] as $field => $overrides) {
            try {
                $service->create($this->analyst, $this->fields($overrides + ['competitors' => []]));
                $this->fail("Accepted an item with a bad $field.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey(trim($field), $e->errors(), $field);
            }
        }
        $this->assertSame(0, IntelligenceItem::query()->count());

        $this->actingAs($this->createUser('reader@example.test', 'exec_reader'))->post('/intelligence', $this->fields())->assertForbidden();
    }

    public function test_only_the_author_or_owner_edits_an_editable_item_from_the_version_they_read(): void
    {
        $item = $this->draft();
        $this->actingAs($this->analyst)->put("/intelligence/{$item->ulid}", $this->fields([
            'title' => 'Widget launch confirmed', 'markets' => [$this->ulid('markets', $this->market)], 'version' => 1,
        ]))->assertRedirect("/intelligence/{$item->ulid}")->assertSessionHasNoErrors();
        $item->refresh();
        $this->assertSame(['Widget launch confirmed', 2], [$item->title, $item->version]);
        $this->assertSame([$this->market], $item->marketIds());
        $audit = DB::table('audit_events')->where('action', 'intelligence.updated')->sole();
        $this->assertSame(['fields' => ['title', 'subjects'], 'version' => 2], json_decode((string) $audit->context, true));

        // A stale form changes nothing.
        $this->actingAs($this->analyst)->put("/intelligence/{$item->ulid}", $this->fields(['title' => 'Stale', 'version' => 1]))->assertSessionHasErrors('version');
        // Saving what is there already keeps the version.
        $this->actingAs($this->analyst)->put("/intelligence/{$item->ulid}", $this->fields([
            'title' => 'Widget launch confirmed', 'markets' => [$this->ulid('markets', $this->market)], 'version' => 2,
        ]))->assertSessionHasNoErrors();
        $this->assertSame(2, $item->refresh()->version);

        // Another analyst (who may see the draft as a reviewer) may not edit it.
        $other = $this->createUser('other@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->grantAll($other, 'confidential');
        $this->actingAs($other)->get("/intelligence/{$item->ulid}")->assertOk()->assertInertia(fn ($page) => $page->where('canEdit', false)->where('form', null));
        $this->actingAs($other)->put("/intelligence/{$item->ulid}", $this->fields(['title' => 'Mine now', 'version' => 2]))->assertForbidden();

        // Once submitted, nobody edits the content.
        DB::table('intelligence_items')->where('id', $item->id)->update(['status' => 'submitted']);
        $this->actingAs($this->analyst)->put("/intelligence/{$item->ulid}", $this->fields(['title' => 'Late', 'version' => 2]))->assertSessionHasErrors('item');
        $this->assertSame('Widget launch confirmed', $item->refresh()->title);
    }

    public function test_lowering_a_classification_is_a_director_decision(): void
    {
        $item = $this->draft(['classification' => 'confidential']);
        $this->expectValidation(fn () => app(IntelligenceItemService::class)->update($this->analyst, $item, $this->fields(['classification' => 'internal', 'version' => 1])), 'classification');
        app(IntelligenceItemService::class)->update($this->analyst, $item, $this->fields(['classification' => 'confidential', 'title' => 'Still confidential', 'version' => 1]));
        $this->assertSame([Classification::Confidential, 2], [$item->refresh()->classification, $item->version]);
    }

    public function test_claims_carry_a_type_and_a_human_admiralty_rating_and_are_withdrawn_never_deleted(): void
    {
        $item = $this->draft();
        $claims = app(IntelligenceClaimService::class);

        // No rating is assumed: both must be chosen.
        foreach (['source_reliability', 'info_credibility', 'claim_type', 'statement'] as $missing) {
            $this->actingAs($this->analyst)->post("/intelligence/{$item->ulid}/claims", array_diff_key($this->claim() + ['version' => 1], [$missing => true]))->assertSessionHasErrors($missing);
        }
        $this->actingAs($this->analyst)->post("/intelligence/{$item->ulid}/claims", $this->claim() + ['version' => 1])->assertSessionHasNoErrors();
        $first = IntelligenceClaim::query()->sole();
        $this->assertSame(['observed_fact', 'B', 2, 'B2', 1, 'active'], [$first->claim_type, $first->source_reliability, $first->info_credibility, $first->rating(), $first->position, $first->status]);
        $second = $claims->add($this->analyst, $item->refresh(), $this->claim(['statement' => 'Retail price will be lower', 'claim_type' => 'analyst_inference', 'source_reliability' => 'F', 'info_credibility' => '6']) + ['version' => 2]);
        $this->assertSame([2, 3], [$second->position, $item->refresh()->version]);

        $claims->move($this->analyst, $item, $second->ulid, ['direction' => 'up', 'version' => 3]);
        $this->assertSame([$second->ulid, $first->ulid], $item->claims()->orderBy('position')->pluck('ulid')->all());
        $claims->update($this->analyst, $item, $first->ulid, $this->claim(['source_reliability' => 'A', 'info_credibility' => 1]) + ['version' => 4]);
        $this->assertSame(['A1', 5], [$first->refresh()->rating(), $item->refresh()->version]);

        $this->actingAs($this->analyst)->post("/intelligence/{$item->ulid}/claims/{$second->ulid}/withdraw", ['version' => 5])->assertSessionHasNoErrors();
        $this->assertSame(['withdrawn', 2, 6], [$second->refresh()->status, IntelligenceClaim::query()->count(), $item->refresh()->version]);
        $this->expectValidation(fn () => $claims->update($this->analyst, $item, $second->ulid, $this->claim() + ['version' => 6]), 'claim');
        $this->expectValidation(fn () => $claims->add($this->analyst, $item, $this->claim() + ['version' => 5]), 'version');

        $this->assertSame(
            ['intelligence.claim_added', 'intelligence.claim_added', 'intelligence.claim_moved', 'intelligence.claim_updated', 'intelligence.claim_withdrawn'],
            DB::table('audit_events')->where('action', 'like', 'intelligence.claim_%')->orderBy('id')->pluck('action')->all(),
        );
        $updated = json_decode((string) DB::table('audit_events')->where('action', 'intelligence.claim_updated')->value('context'), true);
        $this->assertSame(['claim_type' => 'observed_fact', 'claim_ulid' => $first->ulid, 'fields' => ['source_reliability', 'info_credibility'], 'rating' => 'a1', 'version' => 5], $this->sorted($updated));
        $this->assertStringNotContainsString('Widget', (string) DB::table('audit_events')->where('action', 'like', 'intelligence.claim_%')->pluck('context')->implode(' '));

        // The database holds the rating scales, and the app account cannot delete a claim.
        foreach (['source_reliability' => 'G', 'info_credibility' => 7, 'claim_type' => 'gossip', 'status' => 'gone'] as $column => $value) {
            $this->assertDatabaseRefuses(fn () => DB::table('intelligence_claims')->where('id', $first->id)->update([$column => $value]), 'intelligence_claims_');
        }
        $this->assertDatabaseRefuses(fn () => DB::table('intelligence_claims')->where('id', $first->id)->update(['source_reliability' => 'b']), 'intelligence_claims_reliability_check');
        $this->assertDatabaseRefuses(fn () => DB::table('intelligence_claims')->where('id', $first->id)->delete(), 'DELETE command denied');
        $this->assertDatabaseRefuses(fn () => DB::table('intelligence_items')->where('id', $item->id)->delete(), 'DELETE command denied');
    }

    public function test_drafts_are_seen_only_by_their_people_and_reviewers(): void
    {
        $collector = $this->createUser('collector@example.test', 'data_collector');
        $this->grantAll($collector, 'internal');
        $other = $this->createUser('collector2@example.test', 'data_collector');
        $this->grantAll($other, 'internal');
        $field = $this->createUser('field@example.test', 'sales_field');
        $this->grantAll($field, 'internal');
        $own = app(IntelligenceItemService::class)->create($collector, $this->fields(['competitors' => [$this->ulid('competitors', $this->acme)]]));
        $published = $this->draft(['title' => 'Released']);
        DB::table('intelligence_items')->where('id', $published->id)->update(['status' => 'published']);

        $seen = fn (User $user): array => array_column($this->actingAs($user)->get('/intelligence')->assertOk()->inertiaProps('items'), 'ulid');
        $this->assertEqualsCanonicalizing([$own->ulid, $published->ulid], $seen($collector));
        $this->assertSame([$published->ulid], $seen($other));
        $this->assertSame([$published->ulid], $seen($field));
        $this->assertEqualsCanonicalizing([$own->ulid, $published->ulid], $seen($this->analyst), 'intelligence.review holders see drafts');
        $this->actingAs($other)->get("/intelligence/{$own->ulid}")->assertNotFound();
        $this->actingAs($field)->get("/intelligence/{$own->ulid}")->assertNotFound();
        $this->actingAs($collector)->get("/intelligence/{$own->ulid}")->assertOk()->assertInertia(fn ($page) => $page->where('canEdit', true));
        $this->actingAs($other)->post("/intelligence/{$own->ulid}/claims", $this->claim() + ['version' => 1])->assertNotFound();
    }

    public function test_visibility_of_released_items_follows_the_matrix(): void
    {
        $users = AuthorizationMatrix::users(); // ci_analyst: intelligence.view, and intelligence.review (sees drafts)
        $records = AuthorizationMatrix::records();
        $ulids = [];
        foreach ($records as $record) {
            $ulids[$record['label']] = $this->released($record['label'], $record['classification'], $record['competitors'], $record['markets']);
        }
        $lists = [];
        foreach ($users as $entry) {
            $lists[$entry['user']->id] = IntelligenceItem::query()->visibleTo($entry['user'])->pluck('ulid')->all();
        }
        AuthorizationMatrix::assertMatrix($users, $records, fn (User $user, array $record): bool => in_array($ulids[$record['label']], $lists[$user->id], true), 'IntelligenceItem::visibleTo()');

        $active = array_values(array_filter($users, fn (array $u): bool => $u['status'] === 'active' && $u['clearance'] === 'restricted'));
        AuthorizationMatrix::assertMatrix($active, $records, function (User $user, array $record) use ($ulids): bool {
            $status = $this->actingAs($user)->get("/intelligence/{$ulids[$record['label']]}")->getStatusCode();
            $this->assertContains($status, [200, 404]);

            return $status === 200;
        }, 'GET /intelligence/{ulid}');
        $shown = [];
        foreach ($active as $entry) {
            $shown[$entry['user']->id] = array_column($this->actingAs($entry['user'])->get('/intelligence')->inertiaProps('items'), 'ulid');
        }
        AuthorizationMatrix::assertMatrix($active, $records, fn (User $user, array $record): bool => in_array($ulids[$record['label']], $shown[$user->id], true), 'GET /intelligence');
    }

    public function test_subjects_the_viewer_cannot_see_are_counted_not_named_and_block_editing(): void
    {
        $product = $this->product('Globex Widget', $this->globex);
        $item = $this->draft(['products' => [$this->ulid('products', $product)], 'markets' => [$this->ulid('markets', $this->market)]]);
        // Acme and the product become restricted: the analyst (confidential clearance) still sees the item through the
        // grant, Globex and the market, but neither Acme nor the product (TASK-0084: several hidden subjects).
        DB::table('competitors')->where('id', $this->acme)->update(['classification' => 'restricted']);
        DB::table('products')->where('id', $product)->update(['classification' => 'restricted']);
        $this->actingAs($this->analyst)->get('/intelligence')->assertOk()->assertInertia(fn ($page) => $page->where('items.0.competitors', ['Globex']));
        $this->actingAs($this->analyst)->get("/intelligence/{$item->ulid}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('subjects.competitors', [['ulid' => $this->ulid('competitors', $this->globex), 'name' => 'Globex']])
            ->where('subjects.products', [])->where('subjects.markets.0.code', 'PH-NCR')
            ->where('subjects.hidden', 2)->where('canEdit', false)->where('form', null));
        $this->expectValidation(fn () => app(IntelligenceItemService::class)->update($this->analyst, $item, $this->fields(['competitors' => [], 'markets' => [$this->ulid('markets', $this->market)], 'version' => 1])), 'subjects');
        $this->assertSame([$this->acme, $this->globex], $item->refresh()->competitorIds());
        $this->assertSame([$product], $item->productIds());
    }

    /** TASK-0084: nothing about the content changes in any status but draft and changes requested. */
    public function test_content_is_frozen_in_every_status_that_is_not_editable(): void
    {
        $item = $this->draft();
        $claim = app(IntelligenceClaimService::class)->add($this->analyst, $item, $this->claim() + ['version' => 1]);
        $items = app(IntelligenceItemService::class);
        $claims = app(IntelligenceClaimService::class);
        foreach (array_diff(IntelligenceItem::STATUSES, IntelligenceItem::EDITABLE) as $status) {
            DB::table('intelligence_items')->where('id', $item->id)->update(['status' => $status]);
            $item->refresh();
            foreach ([
                'edit' => fn () => $items->update($this->analyst, $item, $this->fields(['title' => 'Changed', 'version' => 2])),
                'add' => fn () => $claims->add($this->analyst, $item, $this->claim() + ['version' => 2]),
                'update' => fn () => $claims->update($this->analyst, $item, $claim->ulid, $this->claim(['statement' => 'Changed']) + ['version' => 2]),
                'withdraw' => fn () => $claims->withdraw($this->analyst, $item, $claim->ulid, ['version' => 2]),
                'move' => fn () => $claims->move($this->analyst, $item, $claim->ulid, ['direction' => 'down', 'version' => 2]),
            ] as $action => $call) {
                try {
                    $call();
                    $this->fail("$action changed a $status item.");
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('item', $e->errors(), "$action on $status");
                }
            }
            $this->actingAs($this->analyst)->get("/intelligence/{$item->ulid}")->assertInertia(fn ($page) => $page->where('canEdit', false));
        }
        $this->assertSame([2, 'Widget launch', 'Acme listed the widget on its site', 'active'], [$item->refresh()->version, $item->title, $claim->refresh()->statement, $claim->status]);
        $this->assertSame(1, IntelligenceClaim::query()->count());
    }

    /** TASK-0084: repeated subjects, and a product whose competitor is chosen too, give one row each. */
    public function test_repeated_subjects_are_recorded_once(): void
    {
        $product = $this->product('Acme Widget', $this->acme);
        $acme = $this->ulid('competitors', $this->acme);
        $item = app(IntelligenceItemService::class)->create($this->analyst, $this->fields([
            'competitors' => [$acme, strtolower($acme)],
            'products' => [$this->ulid('products', $product), $this->ulid('products', $product)],
            'markets' => [$this->ulid('markets', $this->market), $this->ulid('markets', $this->market)],
        ]));
        $this->assertSame([[$this->acme], [$product], [$this->market]], [$item->competitorIds(), $item->productIds(), $item->marketIds()]);
        $this->assertSame([1, 1, 1], [
            DB::table('competitor_intelligence_item')->count(), DB::table('intelligence_item_product')->count(), DB::table('intelligence_item_market')->count(),
        ]);
    }

    public function test_the_summary_is_shown_through_the_markdown_allowlist(): void
    {
        $item = $this->draft(['summary' => "## Launch\n\n**Bold** <script>alert(1)</script> [site](https://news.example/a) [bad](javascript:alert(1)) ![pixel](https://tracker.example/p.gif)"]);

        $props = $this->actingAs($this->analyst)->get("/intelligence/{$item->ulid}")->assertOk()->inertiaProps('item.summary');
        $json = (string) json_encode($props, JSON_UNESCAPED_SLASHES);
        $this->assertSame('h2', $props[0]['tag']);
        $this->assertStringNotContainsString('"tag":"script"', $json);
        $this->assertStringNotContainsString('javascript:', $json);
        $this->assertStringNotContainsString('tracker.example', $json);
        $this->assertStringContainsString('<script>alert(1)</script>', $json, 'raw HTML is kept as text, never as an element');
        $this->assertStringContainsString('"href":"https://news.example/a","rel":"noopener noreferrer nofollow"', $json);
        $this->assertStringContainsString('pixel', $json);
    }

    public function test_a_competitor_with_open_intelligence_cannot_be_archived(): void
    {
        $director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
        $item = $this->draft();
        $competitor = Competitor::query()->findOrFail($this->acme);
        $this->expectValidation(fn () => app(CompetitorService::class)->setLifecycle($director, $competitor, 'archived'), 'lifecycle');
        $this->assertSame('active', $competitor->refresh()->lifecycle);

        DB::table('intelligence_items')->where('id', $item->id)->update(['status' => 'published']);
        app(CompetitorService::class)->setLifecycle($director, $competitor, 'archived');
        $this->assertSame('archived', $competitor->refresh()->lifecycle);
    }

    /** @param  array<string, mixed>  $overrides */
    private function draft(array $overrides = []): IntelligenceItem
    {
        return app(IntelligenceItemService::class)->create($this->analyst, $this->fields($overrides + ['competitors' => [$this->ulid('competitors', $this->acme)]]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fields(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Widget launch', 'summary' => 'Acme announced a widget.', 'item_type' => 'product_change', 'classification' => 'internal',
            'competitors' => [$this->ulid('competitors', $this->acme)], 'products' => [], 'markets' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function claim(array $overrides = []): array
    {
        return $overrides + ['statement' => 'Acme listed the widget on its site', 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2];
    }

    /**
     * @param  list<int>  $competitors
     * @param  list<int>  $markets
     */
    private function released(string $title, string $classification, array $competitors, array $markets): string
    {
        $ulid = (string) Str::ulid();
        $id = DB::table('intelligence_items')->insertGetId([
            'ulid' => $ulid, 'title' => $title, 'summary' => '', 'item_type' => 'news', 'classification' => $classification, 'status' => 'published',
            'author_id' => $this->analyst->id, 'owner_id' => $this->analyst->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($competitors as $competitor) {
            DB::table('competitor_intelligence_item')->insert(['intelligence_item_id' => $id, 'competitor_id' => $competitor]);
        }
        foreach ($markets as $market) {
            DB::table('intelligence_item_market')->insert(['intelligence_item_id' => $id, 'market_id' => $market]);
        }

        return $ulid;
    }

    private function competitor(string $name, string $classification = 'internal'): int
    {
        return (int) DB::table('competitors')->insertGetId([
            'ulid' => (string) Str::ulid(), 'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)), 'lifecycle' => 'active',
            'classification' => $classification, 'created_by' => $this->analyst->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function product(string $name, ?int $competitor): int
    {
        return (int) DB::table('products')->insertGetId([
            'ulid' => (string) Str::ulid(), 'owner_kind' => $competitor === null ? 'own' : 'competitor', 'competitor_id' => $competitor, 'name' => $name,
            'status' => 'active', 'classification' => 'internal', 'created_by' => $this->analyst->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ulid(string $table, int $id): string
    {
        return (string) DB::table($table)->where('id', $id)->value('ulid');
    }

    private function grantAll(User $user, string $level): void
    {
        $grantor = User::query()->where('email', 'grantor@example.test')->first()
            ?? $this->createUser('grantor@example.test', 'ci_director', clearance: Classification::Restricted);
        DB::table('access_grants')->insert([
            'ulid' => (string) Str::ulid(), 'user_id' => $user->id, 'scope_type' => 'all', 'max_classification' => $level,
            'granted_by' => $grantor->id, 'reason' => 'Synthetic test grant', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function expectValidation(callable $call, string $field): void
    {
        try {
            $call();
            $this->fail("Expected a validation error on $field.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    private function assertDatabaseRefuses(callable $statement, string $message): void
    {
        try {
            $statement();
            $this->fail("The database accepted a statement it should refuse ($message).");
        } catch (QueryException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }
}
