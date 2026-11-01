<?php

namespace Modules\Identity\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Modules\Identity\Actions\AccessGrantService;
use Modules\Identity\Actions\TeamService;
use Modules\Identity\Models\AccessGrant;
use Modules\Identity\Models\Team;
use Modules\Identity\Models\User;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Classification;
use RuntimeException;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-14 / docs/02 §3.2 / docs/07 T2: access grants are created by grant.manage holders within their own clearance,
 * never to themselves (directly or through a team), expire, and are revoked, never deleted; every change is audited.
 */
class AccessGrantTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
    }

    public function test_the_page_needs_grant_manage(): void
    {
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst');
        $admin = $this->enableTwoFactor($this->createUser('admin@example.test', 'super_admin', clearance: Classification::Restricted));

        $this->actingAs($analyst)->get('/admin/access-grants')->assertForbidden();
        $this->actingAs($analyst)->post('/admin/access-grants', $this->form($analyst))->assertForbidden();
        $this->actingAs($admin)->get('/admin/access-grants')->assertOk();
        $this->actingAs($this->director)->get('/admin/access-grants')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Identity/Admin/AccessGrants')
            ->where('classifications', ['public', 'internal', 'confidential'])
            ->where('canGrantExport', false)
            ->where('users', [['ulid' => $admin->ulid, 'name' => $admin->name], ['ulid' => $analyst->ulid, 'name' => $analyst->name]])); // not the director
        $this->assertSame(0, AccessGrant::query()->count());
    }

    public function test_a_director_grants_a_user_access_until_an_expiry_and_it_is_audited(): void
    {
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst');
        $expires = now('UTC')->addDays(7)->startOfSecond();

        $this->actingAs($this->director)->post('/admin/access-grants', $this->form($analyst, expires: $expires->toIso8601ZuluString()))
            ->assertSessionHasNoErrors()->assertRedirect();

        $grant = AccessGrant::query()->sole();
        $this->assertSame([$analyst->id, null, 'all', Classification::Confidential, false, $this->director->id, 'Pricing study for Q1'],
            [$grant->user_id, $grant->team_id, $grant->scope_type, $grant->max_classification, $grant->can_export, $grant->granted_by, $grant->reason]);
        $this->assertSame($expires->format('Y-m-d H:i:s'), $grant->expires_at?->format('Y-m-d H:i:s'));
        $this->assertSame('active', $grant->status());
        $this->assertTrue(AccessGrant::query()->active()->heldBy($analyst)->whereKey($grant->id)->exists());

        $audit = DB::table('audit_events')->where('action', 'access_grant.created')->sole();
        $this->assertSame($grant->ulid, $audit->subject_ulid);
        $this->assertEquals([ // MySQL JSON reorders the keys
            'grantee_type' => 'user', 'grantee' => $analyst->ulid, 'scope' => 'all', 'max_classification' => 'confidential',
            'can_export' => false, 'expires_at' => $expires->getTimestamp(),
        ], json_decode((string) $audit->context, true));
    }

    public function test_team_grants_reach_members_and_expired_or_revoked_grants_do_not(): void
    {
        $member = $this->createUser('member@example.test', 'ci_analyst');
        $outsider = $this->createUser('outsider@example.test', 'ci_analyst');
        $team = $this->team('Pricing', [$member]);

        $this->actingAs($this->director)->post('/admin/access-grants', $this->form($team))->assertSessionHasNoErrors();
        $teamGrant = AccessGrant::query()->sole();
        $this->assertTrue(AccessGrant::query()->active()->heldBy($member)->exists());
        $this->assertFalse(AccessGrant::query()->active()->heldBy($outsider)->exists());

        $this->actingAs($this->director)->post('/admin/access-grants', $this->form($outsider, expires: now('UTC')->addHour()->toIso8601ZuluString()));
        $this->travel(2)->hours();
        $this->assertFalse(AccessGrant::query()->active()->heldBy($outsider)->exists());
        $this->assertSame('expired', AccessGrant::query()->where('user_id', $outsider->id)->sole()->status());

        $this->actingAs($this->director)->post("/admin/access-grants/{$teamGrant->ulid}/revoke")->assertSessionHasNoErrors();
        $this->assertFalse(AccessGrant::query()->active()->heldBy($member)->exists());
    }

    public function test_nobody_grants_access_to_themselves_directly_or_through_a_team(): void
    {
        // docs/07 T2. The director holds grant.manage; the super admin also holds team.manage.
        $this->actingAs($this->director)->post('/admin/access-grants', $this->form($this->director))->assertForbidden();
        $own = $this->team('Directors', [$this->director]);
        $this->actingAs($this->director)->post('/admin/access-grants', $this->form($own))->assertForbidden();
        $this->assertSame(0, AccessGrant::query()->count());

        $admin = $this->enableTwoFactor($this->createUser('admin@example.test', 'super_admin', clearance: Classification::Restricted));
        $granted = $this->team('Analysts', []);
        $this->actingAs($admin)->post('/admin/access-grants', $this->form($granted))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/admin/teams/{$granted->ulid}/members", ['member' => $admin->ulid])->assertSessionHasErrors('member');
        $this->assertFalse($granted->members()->whereKey($admin->id)->exists());

        // Someone else may add them; adding yourself to a team without grants stays allowed.
        $this->actingAs($admin)->post("/admin/teams/{$granted->ulid}/members", ['member' => $this->director->ulid])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/admin/teams/{$own->ulid}/members", ['member' => $admin->ulid])->assertSessionHasNoErrors();
    }

    public function test_grants_stay_within_the_actors_clearance_and_permissions(): void
    {
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst');

        $this->actingAs($this->director)->post('/admin/access-grants', $this->form($analyst, classification: 'restricted'))->assertSessionHasErrors('max_classification');
        // Nobody holds export.run before exports exist (Phase 2), so nobody can hand it out.
        $this->actingAs($this->director)->post('/admin/access-grants', ['can_export' => true] + $this->form($analyst))->assertSessionHasErrors('can_export');
        $this->assertSame(0, AccessGrant::query()->count());
    }

    public function test_invalid_grants_are_refused(): void
    {
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst');
        $suspended = $this->createUser('suspended@example.test', 'ci_analyst', 'suspended');

        $this->actingAs($this->director)->post('/admin/access-grants', ['reason' => ' '] + $this->form($analyst))->assertSessionHasErrors('reason');
        $this->actingAs($this->director)->post('/admin/access-grants', $this->form($analyst, expires: now('UTC')->subMinute()->toIso8601ZuluString()))->assertSessionHasErrors('expires_at');
        $this->actingAs($this->director)->post('/admin/access-grants', $this->form($suspended))->assertSessionHasErrors('grantee');
        $this->actingAs($this->director)->post('/admin/access-grants', ['grantee' => (string) Str::ulid()] + $this->form($analyst))->assertSessionHasErrors('grantee');
        $this->actingAs($this->director)->post('/admin/access-grants', ['grantee_type' => 'team'] + $this->form($analyst))->assertSessionHasErrors('grantee');
        $this->actingAs($this->director)->post('/admin/access-grants', $this->form($analyst, classification: 'secret'))->assertSessionHasErrors('max_classification');
        $this->assertSame(0, AccessGrant::query()->count());
    }

    public function test_grants_are_revoked_once_audited_and_never_deleted(): void
    {
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst');
        $this->actingAs($this->director)->post('/admin/access-grants', $this->form($analyst));
        $grant = AccessGrant::query()->sole();

        $this->actingAs($this->director)->delete("/admin/access-grants/{$grant->ulid}")->assertNotFound(); // no such route
        $this->actingAs($this->createUser('other@example.test', 'ci_analyst'))->post("/admin/access-grants/{$grant->ulid}/revoke")->assertForbidden();
        $this->actingAs($this->director)->post("/admin/access-grants/{$grant->ulid}/revoke")->assertSessionHasNoErrors();
        $this->actingAs($this->director)->post("/admin/access-grants/{$grant->ulid}/revoke")->assertSessionHasErrors('grant');

        $grant->refresh();
        $this->assertSame([$this->director->id, 'revoked'], [$grant->revoked_by, $grant->status()]);
        $this->assertNotNull($grant->revoked_at);
        $this->assertSame(['access_grant.created', 'access_grant.revoked'], DB::table('audit_events')->where('action', 'like', 'access_grant.%')->orderBy('id')->pluck('action')->all());
        $this->assertEquals(['grantee_type' => 'user', 'grantee' => $analyst->ulid], json_decode((string) DB::table('audit_events')->where('action', 'access_grant.revoked')->value('context'), true));
    }

    public function test_the_service_refuses_inactive_or_unenrolled_actors_without_the_routes(): void
    {
        // TASK-0025: the service checks the actor itself, not only the route middleware.
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst');
        $service = $this->app->make(AccessGrantService::class);
        $unenrolled = $this->createUser('new-director@example.test', 'ci_director', clearance: Classification::Confidential);
        $suspended = $this->enableTwoFactor($this->createUser('gone@example.test', 'ci_director', 'suspended', Classification::Confidential));

        foreach ([$unenrolled, $suspended, $analyst] as $actor) {
            try {
                $service->grant($actor, $analyst->is($actor) ? $unenrolled : $analyst, Classification::Internal, false, 'Pricing study', null);
                $this->fail("Granted as {$actor->email}.");
            } catch (AuthorizationException) {
                // expected
            }
        }
        $grant = $service->grant($this->director, $analyst, Classification::Internal, false, 'Pricing study', null);
        $this->expectException(AuthorizationException::class);
        $service->revoke($suspended, $grant);
    }

    public function test_expiry_is_stored_in_utc_and_ends_at_the_exact_instant(): void
    {
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst');
        $expires = now('UTC')->addDay()->startOfSecond()->toImmutable();

        // Sent with a +08:00 offset; stored and compared in UTC.
        $this->actingAs($this->director)->post('/admin/access-grants', $this->form($analyst, expires: $expires->setTimezone('Asia/Manila')->toIso8601String()))
            ->assertSessionHasNoErrors();
        $grant = AccessGrant::query()->sole();
        $this->assertSame($expires->format('Y-m-d H:i:s'), substr((string) DB::table('access_grants')->value('expires_at'), 0, 19));

        $this->travelTo($expires->subSecond());
        $this->assertSame('active', $grant->status());
        $this->assertTrue(AccessGrant::query()->active()->whereKey($grant->id)->exists());
        $this->travelTo($expires);
        $this->assertSame('expired', $grant->status());
        $this->assertFalse(AccessGrant::query()->active()->whereKey($grant->id)->exists());
    }

    public function test_the_table_constraints_hold_without_the_service(): void
    {
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst');
        $team = $this->team('Pricing', []);
        $row = fn (array $overrides): array => $overrides + [
            'ulid' => (string) Str::ulid(), 'user_id' => $analyst->id, 'team_id' => null, 'scope_type' => 'all',
            'max_classification' => 'internal', 'granted_by' => $this->director->id, 'reason' => 'Constraint probe',
        ];

        foreach ([
            'both grantees' => ['team_id' => $team->id],
            'no grantee' => ['user_id' => null],
            'competitor scope without a competitor' => ['scope_type' => 'competitor'],
            'all scope with a competitor' => ['competitor_id' => $this->competitor('Acme Foods')],
            'market scope before the market registry' => ['scope_type' => 'market'],
            'unknown classification' => ['max_classification' => 'secret'],
            'revoked without revoker' => ['revoked_at' => now('UTC')],
            'blank reason' => ['reason' => '  '],
        ] as $case => $overrides) {
            try {
                DB::table('access_grants')->insert($row($overrides));
                $this->fail("Inserted a grant with $case.");
            } catch (QueryException $e) {
                $this->assertSame(3819, $e->errorInfo[1] ?? null, "$case: ".$e->getMessage()); // ER_CHECK_CONSTRAINT_VIOLATED
            }
        }
    }

    public function test_a_grant_can_open_one_competitor_the_grantor_can_see(): void
    {
        // P1-20a.
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst');
        $acme = $this->competitor('Acme Foods');
        $hidden = $this->competitor('Secret Co', 'restricted'); // the director has no restricted grant
        $acmeUlid = (string) DB::table('competitors')->where('id', $acme)->value('ulid');

        $this->actingAs($this->director)->get('/admin/access-grants')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('competitors', [['ulid' => $acmeUlid, 'name' => 'Acme Foods']]));
        $this->actingAs($this->director)->post('/admin/access-grants', ['scope' => 'competitor', 'competitor' => $acmeUlid] + $this->form($analyst))->assertSessionHasNoErrors();

        $grant = AccessGrant::query()->sole();
        $this->assertSame(['competitor', $acme], [$grant->scope_type, $grant->competitor_id]);
        $this->assertSame([$acme => Classification::Confidential], $this->app->make(AccessScope::class)->effectiveGrants($analyst)->competitors);
        $this->assertEquals(['grantee_type' => 'user', 'grantee' => $analyst->ulid, 'scope' => 'competitor', 'competitor' => $acmeUlid, 'max_classification' => 'confidential', 'can_export' => false],
            json_decode((string) DB::table('audit_events')->where('action', 'access_grant.created')->value('context'), true));
        $this->actingAs($this->director)->get('/admin/access-grants')->assertInertia(fn (AssertableInertia $page) => $page->where('grants.0.competitor', 'Acme Foods'));

        // A competitor the grantor cannot see, a missing competitor, or a competitor with the `all` scope: refused.
        $hiddenUlid = (string) DB::table('competitors')->where('id', $hidden)->value('ulid');
        $this->actingAs($this->director)->post('/admin/access-grants', ['scope' => 'competitor', 'competitor' => $hiddenUlid] + $this->form($analyst))->assertSessionHasErrors('competitor');
        $this->actingAs($this->director)->post('/admin/access-grants', ['scope' => 'competitor'] + $this->form($analyst))->assertSessionHasErrors('competitor');
        $this->actingAs($this->director)->post('/admin/access-grants', ['competitor' => $acmeUlid] + $this->form($analyst))->assertSessionHasErrors('competitor');
        $this->actingAs($this->director)->post('/admin/access-grants', ['scope' => 'market'] + $this->form($analyst))->assertSessionHasErrors('scope');
        $this->assertSame(1, AccessGrant::query()->count());

        // TASK-0033: a grant on a competitor the viewing admin cannot see shows no name.
        DB::table('access_grants')->insert([
            'ulid' => (string) Str::ulid(), 'user_id' => $analyst->id, 'scope_type' => 'competitor', 'competitor_id' => $hidden,
            'max_classification' => 'internal', 'granted_by' => $this->director->id, 'reason' => 'Granted elsewhere', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $response = $this->actingAs($this->director)->get('/admin/access-grants')->assertInertia(fn (AssertableInertia $page) => $page->where('grants.0.competitor', 'A competitor you cannot see'));
        $this->assertStringNotContainsString('Secret Co', (string) $response->getContent());
    }

    public function test_rolling_back_refuses_while_competitor_grants_exist(): void
    {
        // TASK-0033: down() checks before any DDL, so nothing changes and the grant stays a competitor grant.
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst');
        $acme = $this->competitor('Acme Foods');
        DB::table('access_grants')->insert([
            'ulid' => (string) Str::ulid(), 'user_id' => $analyst->id, 'scope_type' => 'competitor', 'competitor_id' => $acme,
            'max_classification' => 'internal', 'granted_by' => $this->director->id, 'reason' => 'Rollback probe', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration = require base_path('modules/Identity/database/migrations/2026_11_07_000100_add_competitor_scope_to_access_grants.php');

        try {
            $migration->down();
            $this->fail('The rollback ran although competitor grants exist.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Competitor-scoped access grants exist', $e->getMessage());
        }
        $this->assertSame(['competitor', $acme], [DB::table('access_grants')->value('scope_type'), (int) DB::table('access_grants')->value('competitor_id')]);
    }

    private function competitor(string $name, string $classification = 'internal'): int
    {
        return DB::table('competitors')->insertGetId([
            'ulid' => (string) Str::ulid(), 'name' => $name, 'slug' => Str::slug($name), 'lifecycle' => 'active',
            'classification' => $classification, 'created_by' => $this->director->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function form(User|Team $grantee, string $classification = 'confidential', ?string $expires = null): array
    {
        return [
            'grantee_type' => $grantee instanceof User ? 'user' : 'team', 'grantee' => $grantee->ulid, 'scope' => 'all',
            'max_classification' => $classification, 'can_export' => false, 'reason' => 'Pricing study for Q1', 'expires_at' => $expires,
        ];
    }

    /** @param list<User> $members */
    private function team(string $name, array $members): Team
    {
        $admin = User::role('super_admin')->first()
            ?? $this->enableTwoFactor($this->createUser('teams@example.test', 'super_admin', clearance: Classification::Restricted));
        $teams = $this->app->make(TeamService::class);
        $team = $teams->create($admin, $name, null, null);
        foreach ($members as $member) {
            $teams->addMember($admin, $team, $member);
        }

        return $team;
    }
}
