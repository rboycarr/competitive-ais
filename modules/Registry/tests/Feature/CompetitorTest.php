<?php

namespace Modules\Registry\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Modules\Registry\Actions\CompetitorService;
use Modules\Registry\Models\Competitor;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-20 / docs/02 §3.3 / docs/03 Registry: competitor.view holders read the registry within AccessScope (entries are
 * classified, unscoped records; PO decision 2026-11-01), competitor.manage holders maintain it; every change is
 * audited; nothing is deleted.
 */
class CompetitorTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
    }

    public function test_reading_needs_competitor_view_and_changing_needs_competitor_manage(): void
    {
        $reader = $this->createUser('reader@example.test', 'exec_reader');
        $admin = $this->enableTwoFactor($this->createUser('admin@example.test', 'super_admin', clearance: Classification::Restricted));
        $competitor = $this->competitor('Acme Foods');

        $this->actingAs($reader)->get('/competitors')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Registry/Competitors/Index')->where('canManage', false)->where('classifications', []));
        $this->actingAs($reader)->get("/competitors/{$competitor->ulid}")->assertOk();
        $this->actingAs($reader)->post('/competitors', $this->form('Beta Snacks'))->assertForbidden();
        $this->actingAs($reader)->put("/competitors/{$competitor->ulid}/lifecycle", ['lifecycle' => 'archived'])->assertForbidden();
        // Super admins manage the platform, not content (docs/07 ¹).
        $this->actingAs($admin)->get('/competitors')->assertForbidden();
        $this->actingAs($this->analyst)->delete("/competitors/{$competitor->ulid}")->assertStatus(405);
        $this->assertSame(['Acme Foods'], Competitor::query()->pluck('name')->all());
    }

    public function test_an_analyst_registers_a_competitor_and_it_is_audited(): void
    {
        $response = $this->actingAs($this->analyst)->post('/competitors', $this->form('  Acme   Foods  ', [
            'legal_name' => 'Acme Foods Inc.', 'website' => 'https://acme.example', 'hq_country' => 'ph', 'tier' => 'primary',
            'analyst' => $this->analyst->ulid, 'description' => 'Synthetic test entry.',
        ]))->assertSessionHasNoErrors();

        $competitor = Competitor::query()->sole();
        $response->assertRedirect("/competitors/{$competitor->ulid}");
        $this->assertSame(['Acme Foods', 'acme-foods', 'PH', 'active', 'primary', $this->analyst->id, $this->analyst->id, Classification::Internal],
            [$competitor->name, $competitor->slug, $competitor->hq_country, $competitor->lifecycle, $competitor->tier, $competitor->assigned_analyst_id, $competitor->created_by, $competitor->classification]);

        $audit = DB::table('audit_events')->where('action', 'competitor.created')->sole();
        $this->assertSame([$this->analyst->id, $competitor->ulid, 'internal'], [$audit->actor_id, $audit->subject_ulid, $audit->classification]);
        $this->assertSame(0, DB::table('outbox_events')->count()); // events come with their first consumer (Phase 2)

        // Same name again: its own slug.
        $this->actingAs($this->analyst)->post('/competitors', $this->form('Acme Foods'))->assertSessionHasNoErrors();
        $this->assertSame(['acme-foods', 'acme-foods-2'], Competitor::query()->orderBy('id')->pluck('slug')->all());
    }

    public function test_invalid_profiles_are_refused(): void
    {
        $reader = $this->createUser('reader@example.test', 'exec_reader');
        $gone = $this->createUser('gone@example.test', 'ci_analyst', 'suspended');

        foreach ([
            'website' => ['website' => 'javascript:alert(1)'],
            'website ' => ['website' => 'ftp://files.example'],
            'hq_country' => ['hq_country' => 'P1'],
            'tier' => ['tier' => 'top'],
            'analyst' => ['analyst' => $reader->ulid], // no competitor.manage
            'analyst ' => ['analyst' => $gone->ulid],  // inactive
            'name' => ['name' => '   '],
            'classification' => ['classification' => 'secret'],
        ] as $field => $overrides) {
            $this->actingAs($this->analyst)->post('/competitors', $this->form('Acme Foods', $overrides))->assertSessionHasErrors(trim($field));
        }
        $this->assertSame(0, Competitor::query()->count());
    }

    public function test_one_website_rule_for_the_request_and_the_service(): void
    {
        // TASK-0031 #3: a URL with an http(s) scheme and a host, checked the same way everywhere.
        foreach (['https://', 'http://exa mple.com', 'https:///path-only', 'mailto:team@acme.example', 'acme.example'] as $bad) {
            $this->actingAs($this->analyst)->post('/competitors', $this->form('Acme Foods', ['website' => $bad]))->assertSessionHasErrors('website');
            $this->assertFalse(CompetitorService::isWebAddress($bad), $bad);
        }
        $this->actingAs($this->analyst)->post('/competitors', $this->form('Acme Foods', ['website' => 'https://acme.example/about?lang=en']))->assertSessionHasNoErrors();
        $this->assertSame('https://acme.example/about?lang=en', Competitor::query()->sole()->website);
    }

    public function test_the_service_refuses_actors_without_competitor_manage(): void
    {
        $admin = $this->enableTwoFactor($this->createUser('admin@example.test', 'super_admin', clearance: Classification::Restricted));
        $service = $this->app->make(CompetitorService::class);

        $this->expectException(AuthorizationException::class);
        $service->create($admin, ['name' => 'Acme Foods', 'classification' => 'internal']);
    }

    public function test_a_new_analyst_must_qualify_but_a_kept_one_stays(): void
    {
        $colleague = $this->createUser('colleague@example.test', 'ci_analyst');
        $competitor = $this->competitor('Acme Foods', analyst: $colleague);
        DB::table('users')->where('id', $colleague->id)->update(['status' => 'suspended']);

        // Editing another field keeps the (now suspended) analyst; choosing them anew is refused.
        $this->actingAs($this->analyst)->put("/competitors/{$competitor->ulid}", $this->form('Acme Foods Inc', ['analyst' => $colleague->ulid]))->assertSessionHasNoErrors();
        $this->assertSame([$colleague->id, 'Acme Foods Inc'], [$competitor->refresh()->assigned_analyst_id, $competitor->name]);
        $other = $this->competitor('Beta Snacks');
        $this->actingAs($this->analyst)->put("/competitors/{$other->ulid}", $this->form('Beta Snacks', ['analyst' => $colleague->ulid]))->assertSessionHasErrors('analyst');
        $this->assertNull($other->refresh()->assigned_analyst_id);
    }

    public function test_nobody_classifies_above_what_they_can_see_and_only_directors_lower_it(): void
    {
        $internal = $this->createUser('internal@example.test', 'ci_analyst');
        $this->actingAs($internal)->post('/competitors', $this->form('Acme Foods', ['classification' => 'confidential']))->assertSessionHasErrors('classification');
        // Restricted needs a grant as well (AccessScope), even within clearance.
        $this->actingAs($this->analyst)->post('/competitors', $this->form('Acme Foods', ['classification' => 'restricted']))->assertSessionHasErrors('classification');
        $this->actingAs($this->analyst)->get('/competitors')->assertInertia(fn (AssertableInertia $page) => $page->where('classifications', ['public', 'internal', 'confidential']));

        $competitor = $this->competitor('Acme Foods');
        $this->actingAs($this->analyst)->put("/competitors/{$competitor->ulid}", $this->form('Acme Foods', ['classification' => 'confidential']))->assertSessionHasNoErrors();
        $this->actingAs($this->analyst)->put("/competitors/{$competitor->ulid}", $this->form('Acme Foods', ['classification' => 'internal']))->assertSessionHasErrors('classification');
        $this->assertSame(Classification::Confidential, $competitor->refresh()->classification);

        $director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
        $this->actingAs($director)->put("/competitors/{$competitor->ulid}", $this->form('Acme Foods', ['classification' => 'internal']))->assertSessionHasNoErrors();
        $this->assertSame(Classification::Internal, $competitor->refresh()->classification);
        $this->assertSame([['fields' => ['classification']], ['fields' => ['classification']]],
            DB::table('audit_events')->where('action', 'competitor.updated')->orderBy('id')->pluck('context')->map(fn ($c) => json_decode((string) $c, true))->all());
    }

    public function test_visibility_follows_the_matrix_for_lists_and_profiles(): void
    {
        $users = AuthorizationMatrix::users(); // ci_analyst: competitor.view
        $records = array_values(array_filter(AuthorizationMatrix::records(), fn (array $r): bool => $r['competitors'] === [] && $r['markets'] === []));
        $ulids = [];
        foreach ($records as $record) {
            $ulids[$record['label']] = $this->competitor('Entry '.$record['label'], Classification::from($record['classification']))->ulid;
        }

        $lists = [];
        foreach ($users as $entry) {
            $lists[$entry['user']->id] = Competitor::query()->visibleTo($entry['user'])->pluck('ulid')->all();
        }
        AuthorizationMatrix::assertMatrix($users, $records, fn (User $user, array $record): bool => in_array($ulids[$record['label']], $lists[$user->id], true), 'Competitor::visibleTo()');

        $viewers = array_values(array_filter($users, fn (array $u): bool => $u['status'] === 'active' && in_array($u['grant'], ['none', 'all:restricted'], true)));
        AuthorizationMatrix::assertMatrix($viewers, $records, function (User $user, array $record) use ($ulids): bool {
            $status = $this->actingAs($user)->get("/competitors/{$ulids[$record['label']]}")->getStatusCode();
            $this->assertContains($status, [200, 404]);

            return $status === 200;
        }, 'GET /competitors/{ulid}');
        $shown = [];
        foreach ($viewers as $entry) {
            $shown[$entry['user']->id] = array_column($this->actingAs($entry['user'])->get('/competitors')->inertiaProps('competitors'), 'ulid');
        }
        AuthorizationMatrix::assertMatrix($viewers, $records, fn (User $user, array $record): bool => in_array($ulids[$record['label']], $shown[$user->id], true), 'GET /competitors');

        // Changes need visibility too: a hidden entry is 404 for an editor as well.
        $hidden = $ulids['restricted/unscoped'];
        $this->actingAs($this->analyst)->put("/competitors/$hidden", $this->form('Renamed'))->assertNotFound();
        $this->actingAs($this->analyst)->put("/competitors/$hidden/lifecycle", ['lifecycle' => 'archived'])->assertNotFound();
    }

    public function test_lifecycle_changes_are_audited_and_archived_entries_leave_the_working_list(): void
    {
        $competitor = $this->competitor('Acme Foods');

        $this->actingAs($this->analyst)->put("/competitors/{$competitor->ulid}/lifecycle", ['lifecycle' => 'watch'])->assertSessionHasNoErrors();
        $this->actingAs($this->analyst)->put("/competitors/{$competitor->ulid}/lifecycle", ['lifecycle' => 'archived'])->assertSessionHasNoErrors();
        $this->actingAs($this->analyst)->put("/competitors/{$competitor->ulid}/lifecycle", ['lifecycle' => 'archived'])->assertSessionHasNoErrors(); // no change
        $this->actingAs($this->analyst)->put("/competitors/{$competitor->ulid}/lifecycle", ['lifecycle' => 'gone'])->assertSessionHasErrors('lifecycle');

        $this->assertEquals([['from' => 'active', 'to' => 'watch'], ['from' => 'watch', 'to' => 'archived']], // MySQL JSON reorders keys
            DB::table('audit_events')->where('action', 'competitor.lifecycle_changed')->orderBy('id')->pluck('context')->map(fn ($c) => json_decode((string) $c, true))->all());

        $this->actingAs($this->analyst)->get('/competitors')->assertInertia(fn (AssertableInertia $page) => $page->has('competitors', 0));
        $this->actingAs($this->analyst)->get('/competitors?lifecycle=archived')->assertInertia(fn (AssertableInertia $page) => $page->where('competitors.0.ulid', $competitor->ulid));
        $this->actingAs($this->analyst)->get("/competitors/{$competitor->ulid}")->assertOk(); // still on record
    }

    public function test_the_list_filters_by_tier_and_analyst(): void
    {
        $other = $this->createUser('other@example.test', 'ci_analyst');
        $mine = $this->competitor('Acme Foods', analyst: $this->analyst, tier: 'primary');
        $theirs = $this->competitor('Beta Snacks', analyst: $other, tier: 'emerging');

        $list = fn (string $query): array => array_column($this->actingAs($this->analyst)->get("/competitors?$query")->assertOk()->inertiaProps('competitors'), 'ulid');
        $this->assertSame([$mine->ulid], $list('tier=primary'));
        $this->assertSame([$theirs->ulid], $list('analyst='.$other->ulid));
        $this->assertSame([], $list('analyst='.Str::ulid()));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(string $name, array $overrides = []): array
    {
        return $overrides + ['name' => $name, 'classification' => 'internal'];
    }

    private function competitor(string $name, Classification $classification = Classification::Internal, ?User $analyst = null, ?string $tier = null): Competitor
    {
        $competitor = new Competitor;
        $competitor->forceFill([
            'ulid' => (string) Str::ulid(), 'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)), 'lifecycle' => 'active',
            'classification' => $classification, 'assigned_analyst_id' => $analyst?->id, 'tier' => $tier, 'created_by' => $this->analyst->id,
        ])->save();

        return $competitor;
    }
}
