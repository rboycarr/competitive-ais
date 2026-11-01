<?php

namespace Modules\Identity\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Modules\Identity\Actions\InvitationService;
use Modules\Identity\MfaRequirement;
use Modules\Identity\Tests\Support\CreatesUsers;
use Modules\Platform\Classification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * P1-12 / docs/03 Identity / docs/09 #2: privileged permissions need confirmed two-factor authentication, tested per
 * permission; users holding one can only enroll or sign out until then.
 */
class MfaEnforcementTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_each_privileged_permission_counts_only_after_two_factor_is_on(): void
    {
        foreach (MfaRequirement::PRIVILEGED_PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
            $role = Role::create(['name' => 'only_'.str_replace('.', '_', $permission), 'guard_name' => 'web']);
            $role->givePermissionTo([$permission, 'competitor.view']);
            $user = $this->createUser("holder.$permission@example.test", $role->name);

            $this->assertTrue($user->requiresMfa(), $permission);
            $this->assertFalse($user->hasPermissionTo($permission), "$permission without two-factor");
            $this->assertFalse(Gate::forUser($user)->allows($permission), "$permission via Gate without two-factor");
            $this->assertTrue($user->hasPermissionTo('competitor.view'), 'Non-privileged permissions are unaffected.');

            $this->actingAs($user)->get('/')->assertRedirect('/user/security');
            $this->actingAs($user)->getJson('/')->assertForbidden()->assertJsonPath('detail', 'Two-factor authentication is required for your role. Turn it on in your security settings.');

            $this->enableTwoFactor($user);
            $this->assertTrue($user->hasPermissionTo($permission), "$permission with two-factor");
            $this->assertTrue(Gate::forUser($user)->allows($permission), "$permission via Gate with two-factor");
            $this->actingAs($user)->get('/')->assertOk();
        }
    }

    public function test_the_seeded_privileged_roles_are_held_back_until_enrollment(): void
    {
        foreach (['super_admin' => 'user.manage', 'ci_director' => 'intelligence.publish'] as $role => $permission) {
            $user = $this->createUser("$role@example.test", $role);

            $this->assertTrue($user->requiresMfa());
            $this->assertFalse($user->can($permission));
            $this->actingAs($user)->get('/')->assertRedirect('/user/security');
        }
    }

    public function test_roles_without_privileged_permissions_are_not_affected(): void
    {
        foreach (['ci_analyst', 'data_collector', 'sales_field', 'exec_reader', 'auditor'] as $role) {
            $user = $this->createUser("$role@example.test", $role);

            $this->assertFalse($user->requiresMfa(), $role);
            $this->actingAs($user)->get('/')->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.mfaRequired', false));
        }
    }

    public function test_enrollment_and_sign_out_stay_reachable(): void
    {
        $admin = $this->createUser('enroll@example.test', 'super_admin');
        $this->actingAs($admin);

        $this->get('/user/security')->assertRedirect('/user/confirm-password');
        $this->get('/user/confirm-password')->assertOk();
        $this->post('/user/confirm-password', ['password' => self::TEST_PASSWORD])->assertRedirect('/user/security');
        $this->get('/user/security')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Identity/Security')->where('auth.mfaRequired', true));
        $this->post('/user/two-factor-authentication')->assertRedirect();
        $this->getJson('/user/two-factor-qr-code')->assertOk();
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_directly_assigned_privileged_permissions_need_two_factor_through_every_check(): void
    {
        // TASK-0021 #1: a permission given to the user directly, not through a role.
        $user = $this->createUser('direct@example.test', 'exec_reader');
        $user->givePermissionTo('user.manage');

        $this->assertTrue($user->requiresMfa());
        $this->assertFalse($user->hasDirectPermission('user.manage'));
        $this->assertFalse($user->hasPermissionTo('user.manage'));
        $this->assertFalse($user->hasAnyPermission(['user.manage', 'role.manage']));
        $this->assertFalse($user->can('user.manage'));
        $this->assertTrue($user->hasPermissionTo('intelligence.view'), 'The role\'s own permissions are unaffected.');

        $this->enableTwoFactor($user);
        $this->assertTrue($user->hasDirectPermission('user.manage'));
        $this->assertTrue($user->can('user.manage'));
    }

    public function test_a_pending_secret_is_not_enough_and_turning_two_factor_off_withdraws_the_permissions(): void
    {
        $admin = $this->createUser('pending@example.test', 'super_admin');
        $admin->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP')])->save();
        $this->assertFalse($admin->refresh()->can('user.manage'), 'An unconfirmed secret does not count.');
        $this->actingAs($admin)->get('/')->assertRedirect('/user/security');

        $this->enableTwoFactor($admin);
        $this->assertTrue($admin->can('user.manage'));

        $admin->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $this->assertFalse($admin->refresh()->can('user.manage'));
        $this->actingAs($admin)->get('/')->assertRedirect('/user/security');
    }

    public function test_api_routes_are_held_to_the_same_rule(): void
    {
        // TASK-0021 #2: the middleware runs in the api group too.
        Route::middleware('api')->get('/api/v1/_test/privileged', fn () => 'ok');
        $admin = $this->createUser('api@example.test', 'super_admin');

        $this->actingAs($admin)->getJson('/api/v1/_test/privileged')->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json');
        $this->actingAs($this->enableTwoFactor($admin))->getJson('/api/v1/_test/privileged')->assertOk();
    }

    public function test_the_allowlist_is_by_route_name_and_every_name_exists(): void
    {
        // TASK-0021 #3: a typo would silently block enrollment; a look-alike path must not be let through.
        foreach (MfaRequirement::ENROLLMENT_ROUTES as $name) {
            $this->assertTrue(Route::has($name), "Route [$name] is not registered.");
        }

        Route::middleware('web')->get('/user/security-anything', fn () => 'ok');
        $admin = $this->createUser('lookalike@example.test', 'super_admin');
        $this->actingAs($admin)->get('/user/security-anything')->assertRedirect('/user/security');
        $this->actingAs($admin)->get('/user/security?x=1')->assertRedirect('/user/confirm-password');
    }

    public function test_an_unenrolled_super_admin_cannot_invite(): void
    {
        Mail::fake();
        $admin = $this->createUser('admin@example.test', 'super_admin', clearance: Classification::Restricted);
        $invitations = $this->app->make(InvitationService::class);

        try {
            $invitations->invite('new@example.test', 'ci_analyst', Classification::Internal, $admin);
            $this->fail('Expected the invitation to be refused without two-factor.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('role', $e->errors());
        }

        $invitations->invite('new@example.test', 'ci_analyst', Classification::Internal, $this->enableTwoFactor($admin));
        Mail::assertSentCount(1);
    }
}
