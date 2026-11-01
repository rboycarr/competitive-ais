<?php

namespace Modules\Identity\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Modules\Identity\Mail\InvitationMail;
use Modules\Identity\Models\Team;
use Modules\Identity\Models\User;
use Modules\Identity\Tests\Support\CreatesUsers;
use Modules\Platform\Classification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * P1-13 / docs/03 Identity / docs/07 §3: roles, role definitions and teams are managed by the permission holders,
 * every change is audited, and nobody changes their own roles.
 */
class AdministrationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_the_admin_pages_need_their_permission(): void
    {
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst');
        $auditor = $this->createUser('auditor@example.test', 'auditor');
        $admin = $this->enableTwoFactor($this->createUser('admin@example.test', 'super_admin', clearance: Classification::Restricted));

        foreach (['/admin/users', '/admin/roles', '/admin/teams'] as $page) {
            $this->actingAs($analyst)->get($page)->assertForbidden();
            $this->actingAs($admin)->get($page)->assertOk();
        }
        $this->actingAs($auditor)->get('/admin/users')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Identity/Admin/Users')->where('canManage', false));
        $this->actingAs($auditor)->get('/admin/roles')->assertForbidden();
        $this->actingAs($analyst)->put("/admin/users/{$auditor->ulid}/roles", ['roles' => ['super_admin']])->assertForbidden();
        $this->get('/admin/users'); // still signed in as the analyst
        $this->assertTrue($auditor->refresh()->hasRole('auditor'));
    }

    public function test_the_user_list_shows_no_secrets(): void
    {
        $admin = $this->enableTwoFactor($this->createUser('admin@example.test', 'super_admin', clearance: Classification::Restricted));

        $response = $this->actingAs($admin)->get('/admin/users')->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page->where('users.0.email', 'admin@example.test')->where('users.0.twoFactor', true)->where('users.0.isSelf', true));
        $body = (string) $response->getContent();
        foreach ([(string) DB::table('users')->value('password'), (string) decrypt((string) $admin->two_factor_secret), 'two_factor_recovery_codes'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_an_admin_invites_from_the_users_page(): void
    {
        Mail::fake();
        $admin = $this->enableTwoFactor($this->createUser('admin@example.test', 'super_admin', clearance: Classification::Restricted));

        $this->actingAs($admin)->post('/admin/users/invitations', ['email' => 'new@example.test', 'role' => 'ci_analyst', 'clearance' => 'confidential'])
            ->assertRedirect()->assertSessionHas('status', 'Invitation sent to new@example.test.');

        Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail): bool => $mail->hasTo('new@example.test'));
        $this->assertSame($admin->id, DB::table('audit_events')->where('action', 'user.invited')->value('actor_id'));
        $this->actingAs($admin)->post('/admin/users/invitations', ['email' => 'x@example.test', 'role' => 'ci_analyst', 'clearance' => 'top'])->assertSessionHasErrors('clearance');
    }

    public function test_roles_are_changed_and_every_change_is_audited(): void
    {
        $admin = $this->enableTwoFactor($this->createUser('admin@example.test', 'super_admin', clearance: Classification::Restricted));
        $user = $this->createUser('user@example.test', 'exec_reader');

        $this->actingAs($admin)->put("/admin/users/{$user->ulid}/roles", ['roles' => ['ci_analyst', 'auditor']])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(['ci_analyst', 'auditor'], $user->refresh()->getRoleNames()->all());
        $rows = DB::table('audit_events')->whereIn('action', ['role.assigned', 'role.revoked'])->orderBy('id')->get(['action', 'context', 'actor_id', 'subject_ulid']);
        $this->assertEqualsCanonicalizing(
            ['role.assigned:ci_analyst', 'role.assigned:auditor', 'role.revoked:exec_reader'],
            $rows->map(fn (object $row): string => $row->action.':'.json_decode((string) $row->context, true)['role'])->all(),
        );
        $this->assertSame([$admin->id], $rows->pluck('actor_id')->unique()->values()->all());
        $this->assertSame([$user->ulid], $rows->pluck('subject_ulid')->unique()->values()->all());
    }

    public function test_nobody_changes_their_own_roles(): void
    {
        $admin = $this->enableTwoFactor($this->createUser('admin@example.test', 'super_admin', clearance: Classification::Restricted));
        $this->createUser('other@example.test', 'super_admin');

        $this->actingAs($admin)->put("/admin/users/{$admin->ulid}/roles", ['roles' => ['super_admin', 'ci_director']])->assertSessionHasErrors('roles');
        $this->assertSame(['super_admin'], $admin->refresh()->getRoleNames()->all());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'like', 'role.%')->count());
    }

    public function test_super_admins_can_remove_each_other_but_one_always_remains(): void
    {
        $first = $this->enableTwoFactor($this->createUser('first@example.test', 'super_admin', clearance: Classification::Restricted));
        $second = $this->enableTwoFactor($this->createUser('second@example.test', 'super_admin', clearance: Classification::Restricted));
        $suspended = $this->createUser('suspended@example.test', 'super_admin', 'suspended');

        $this->actingAs($first)->put("/admin/users/{$suspended->ulid}/roles", ['roles' => []])->assertSessionHasNoErrors();
        $this->actingAs($first)->put("/admin/users/{$second->ulid}/roles", ['roles' => ['ci_director']])->assertSessionHasNoErrors();

        // The demoted admin no longer holds user.manage, so they cannot take the role back from the last one.
        $this->actingAs($second->refresh())->put("/admin/users/{$first->ulid}/roles", ['roles' => []])->assertForbidden();
        $this->assertSame([$first->id], User::role('super_admin')->where('status', 'active')->pluck('id')->all());
    }

    public function test_only_super_admins_handle_super_admin_and_others_stay_below_their_own_permissions(): void
    {
        $lead = Role::create(['name' => 'people_lead', 'guard_name' => 'web']);
        $lead->givePermissionTo(['user.view', 'user.manage', 'competitor.view', 'product.view', 'intelligence.view']);
        $actor = $this->enableTwoFactor($this->createUser('lead@example.test', 'people_lead', clearance: Classification::Confidential));
        $user = $this->createUser('user@example.test', 'exec_reader');

        $this->actingAs($actor)->put("/admin/users/{$user->ulid}/roles", ['roles' => ['sales_field']])->assertSessionHasNoErrors();
        $this->actingAs($actor)->put("/admin/users/{$user->ulid}/roles", ['roles' => ['super_admin']])->assertSessionHasErrors('roles');
        $this->actingAs($actor)->put("/admin/users/{$user->ulid}/roles", ['roles' => ['ci_analyst']])->assertSessionHasErrors('roles');
        $this->assertSame(['sales_field'], $user->refresh()->getRoleNames()->all());
    }

    public function test_role_definitions_are_managed_and_audited_but_super_admin_and_own_roles_are_fixed(): void
    {
        $admin = $this->enableTwoFactor($this->createUser('admin@example.test', 'super_admin', clearance: Classification::Restricted));

        $this->actingAs($admin)->post('/admin/roles', ['name' => 'regional_analyst', 'permissions' => ['competitor.view', 'product.view']])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['competitor.view', 'product.view'], Role::findByName('regional_analyst', 'web')->permissions->pluck('name')->all());

        $this->actingAs($admin)->put('/admin/roles/regional_analyst/permissions', ['permissions' => ['competitor.view', 'intelligence.view']])->assertSessionHasNoErrors();
        $context = json_decode((string) DB::table('audit_events')->where('action', 'role.permissions_changed')->value('context'), true);
        $this->assertSame(['added' => ['intelligence.view'], 'removed' => ['product.view'], 'role' => 'regional_analyst'], $this->sorted($context));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'role.created')->count());

        $this->actingAs($admin)->put('/admin/roles/super_admin/permissions', ['permissions' => []])->assertSessionHasErrors('permissions');
        $this->actingAs($admin)->post('/admin/roles', ['name' => 'Bad Name', 'permissions' => []])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/admin/roles', ['name' => 'ghost', 'permissions' => ['does.not_exist']])->assertSessionHasErrors('permissions');
        $this->actingAs($admin)->put('/admin/roles/nope/permissions', ['permissions' => []])->assertNotFound();

        // A role.manage holder cannot edit a role they hold.
        $editor = Role::create(['name' => 'role_editor', 'guard_name' => 'web']);
        $editor->givePermissionTo(['role.manage']);
        $holder = $this->enableTwoFactor($this->createUser('editor@example.test', 'role_editor'));
        $this->actingAs($holder)->put('/admin/roles/role_editor/permissions', ['permissions' => ['role.manage', 'user.manage']])->assertSessionHasErrors('permissions');
        $this->assertFalse($holder->refresh()->hasPermissionTo('user.manage'));
    }

    public function test_teams_and_their_members_are_managed_and_audited(): void
    {
        $admin = $this->enableTwoFactor($this->createUser('admin@example.test', 'super_admin', clearance: Classification::Restricted));
        $member = $this->createUser('member@example.test', 'ci_analyst');
        $suspended = $this->createUser('gone@example.test', 'ci_analyst', 'suspended');

        $this->actingAs($admin)->post('/admin/teams', ['name' => 'Pricing', 'description' => 'Price watchers', 'lead' => $member->ulid])->assertSessionHasNoErrors();
        $team = Team::query()->where('name', 'Pricing')->sole();
        $this->assertSame($member->id, $team->lead_user_id);
        $this->actingAs($admin)->post('/admin/teams', ['name' => 'Pricing'])->assertSessionHasErrors('name');

        $this->actingAs($admin)->put("/admin/teams/{$team->ulid}", ['name' => 'Pricing EU', 'description' => null, 'lead' => null])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/admin/teams/{$team->ulid}/members", ['member' => $member->ulid])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/admin/teams/{$team->ulid}/members", ['member' => $suspended->ulid])->assertSessionHasErrors('member');
        $this->assertSame([$member->id], $team->members()->pluck('users.id')->all());
        $this->assertSame($admin->id, (int) DB::table('team_user')->value('added_by'));
        $this->actingAs($admin)->delete("/admin/teams/{$team->ulid}/members/{$member->ulid}")->assertRedirect();
        $this->assertSame(0, $team->members()->count());

        $this->assertSame(
            ['team.created', 'team.updated', 'team.member_added', 'team.member_removed'],
            DB::table('audit_events')->where('action', 'like', 'team.%')->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame(['fields' => ['name', 'description', 'lead_user_id']], json_decode((string) DB::table('audit_events')->where('action', 'team.updated')->value('context'), true));
    }

    public function test_a_suspended_account_loses_its_session_and_every_permission(): void
    {
        // TASK-0023 #3: suspension takes effect at the next request, also for a session that is still open.
        $admin = $this->enableTwoFactor($this->createUser('admin@example.test', 'super_admin', clearance: Classification::Restricted));
        $this->actingAs($admin)->get('/admin/users')->assertOk();

        DB::table('users')->where('id', $admin->id)->update(['status' => 'suspended']);
        $admin->refresh();
        $this->assertFalse($admin->hasPermissionTo('user.view'));
        $this->assertFalse($admin->hasDirectPermission('user.view'));

        foreach (['/admin/users', '/admin/roles', '/admin/teams', '/'] as $page) {
            $this->actingAs($admin)->get($page)->assertRedirect('/login');
            $this->assertGuest();
        }
        $this->actingAs($admin)->getJson('/admin/users')->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_admins_without_two_factor_are_sent_to_enroll_first(): void
    {
        $admin = $this->createUser('admin@example.test', 'super_admin');

        $this->actingAs($admin)->get('/admin/users')->assertRedirect('/user/security');
        $this->actingAs($admin)->postJson('/admin/teams', ['name' => 'X'])->assertForbidden();
        $this->assertSame(0, Team::query()->count());
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
