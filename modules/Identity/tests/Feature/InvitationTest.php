<?php

namespace Modules\Identity\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Modules\Identity\Actions\InvitationService;
use Modules\Identity\Mail\InvitationMail;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * P1-10 / docs/07 Authentication / docs/09 #2: invitations only; hashed, single-use tokens that expire after 72 h;
 * audited invitation and activation. MySQL 8.4, real app account.
 */
class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery staple';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_phase_1_roles_and_permissions_are_seeded(): void
    {
        $roles = DB::table('roles')->where('guard_name', 'web')->orderBy('name')->pluck('name')->all();
        $this->assertSame(['auditor', 'ci_analyst', 'ci_director', 'data_collector', 'exec_reader', 'sales_field', 'service_account', 'super_admin'], $roles);

        $can = fn (string $role, string $permission): bool => DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('roles.name', $role)->where('permissions.name', $permission)->exists();
        $this->assertTrue($can('super_admin', 'user.manage'));
        $this->assertFalse($can('super_admin', 'intelligence.view'), 'Super admin manages the platform, not content.');
        $this->assertTrue($can('ci_director', 'intelligence.publish'));
        $this->assertFalse($can('ci_analyst', 'intelligence.publish'));
        $this->assertTrue($can('auditor', 'audit.view'));
        $this->assertFalse($can('auditor', 'user.manage'));
        $this->assertSame(0, DB::table('role_has_permissions')->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')->where('roles.name', 'service_account')->count());
    }

    public function test_the_command_invites_by_email_and_stores_only_the_token_hash(): void
    {
        $this->assertSame(0, Artisan::call('identity:invite', ['email' => ' New.Analyst@Example.TEST ', '--role' => 'ci_analyst', '--clearance' => 'confidential']));

        $token = $this->sentToken('new.analyst@example.test');
        $invitation = DB::table('user_invitations')->sole();
        $this->assertSame('new.analyst@example.test', $invitation->email);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertStringNotContainsString($token, json_encode(DB::table('user_invitations')->get()).json_encode(DB::table('audit_events')->get()));
        $this->assertEqualsWithDelta(now()->addHours(72)->getTimestamp(), Carbon::parse($invitation->expires_at)->getTimestamp(), 5);

        $audit = DB::table('audit_events')->where('action', 'user.invited')->sole();
        $this->assertSame('system', $audit->actor_type);
        $this->assertSame(['clearance' => 'confidential', 'invitation_ulid' => $invitation->ulid, 'replaced' => 0, 'role' => 'ci_analyst'], $this->sorted(json_decode($audit->context, true)));
    }

    public function test_the_email_links_to_the_acceptance_page(): void
    {
        $this->invite('reader@example.test');

        Mail::assertSent(InvitationMail::class, function (InvitationMail $mail): bool {
            $body = $mail->render();

            return str_contains($body, route('invitations.show', ['token' => $mail->token])) && str_contains($body, 'expires');
        });
    }

    public function test_a_valid_link_shows_the_form_and_accepting_creates_an_active_account(): void
    {
        $this->invite('analyst@example.test', 'ci_analyst', Classification::Confidential);
        $token = $this->sentToken('analyst@example.test');

        $this->get("/invitations/$token")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Identity/AcceptInvitation')->where('email', 'analyst@example.test'));

        $this->post("/invitations/$token", ['name' => 'Ana Lyst', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertRedirect(route('invitations.accepted'));

        $user = User::query()->where('email', 'analyst@example.test')->sole();
        $this->assertSame(['active', Classification::Confidential, 'Ana Lyst'], [$user->status, $user->clearance, $user->name]);
        $this->assertTrue($user->hasRole('ci_analyst'));
        $this->assertTrue($user->hasPermissionTo('intelligence.create'));
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Str::isUlid($user->ulid));
        $this->assertStringStartsWith('$argon2id$', (string) DB::table('users')->where('id', $user->id)->value('password'));
        $this->assertTrue(Hash::check(self::PASSWORD, (string) DB::table('users')->where('id', $user->id)->value('password')));
        $this->assertNotNull(DB::table('user_invitations')->value('accepted_at'));

        $audit = DB::table('audit_events')->where('action', 'user.activated')->sole();
        $this->assertSame(['user', $user->id, $user->ulid], [$audit->actor_type, $audit->actor_id, $audit->subject_ulid]);
    }

    public function test_a_link_works_once(): void
    {
        $this->invite('once@example.test');
        $token = $this->sentToken('once@example.test');
        $this->accept($token)->assertRedirect(route('invitations.accepted'));

        $this->get("/invitations/$token")->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page->component('Identity/InvitationUnavailable'));
        $this->accept($token)->assertNotFound();
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'user.activated')->count());
    }

    public function test_a_link_expires_after_72_hours(): void
    {
        $this->invite('late@example.test');
        $token = $this->sentToken('late@example.test');

        Carbon::setTestNow(now()->addHours(72)->addSecond());

        $this->get("/invitations/$token")->assertNotFound();
        $this->accept($token)->assertNotFound();
        $this->assertSame(0, User::query()->count());
    }

    public function test_unknown_and_malformed_links_get_the_same_answer(): void
    {
        $this->get('/invitations/'.str_repeat('a', 64))->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page->component('Identity/InvitationUnavailable'));
        $this->get('/invitations/not-a-token')->assertNotFound();
        $this->assertNull($this->app->make(InvitationService::class)->accept('not-a-token', 'X', self::PASSWORD));
    }

    public function test_a_new_invitation_revokes_the_open_one_for_the_same_address(): void
    {
        $this->invite('again@example.test');
        $first = $this->sentToken('again@example.test');
        $this->invite('again@example.test');

        $this->get("/invitations/$first")->assertNotFound();
        $this->assertSame(1, DB::table('user_invitations')->whereNotNull('revoked_at')->count());
        $this->assertSame(1, json_decode((string) DB::table('audit_events')->where('action', 'user.invited')->orderByDesc('id')->value('context'), true)['replaced']);
    }

    public function test_existing_accounts_and_unknown_roles_cannot_be_invited(): void
    {
        $this->invite('taken@example.test');
        $this->accept($this->sentToken('taken@example.test'));

        $this->assertInvalid(fn () => $this->invite('TAKEN@example.test'), 'email');
        $this->assertInvalid(fn () => $this->invite('new@example.test', 'emperor'), 'role');
        $this->assertInvalid(fn () => $this->invite('not-an-address'), 'email');
        $this->assertSame(1, Artisan::call('identity:invite', ['email' => 'x@example.test', '--role' => 'ci_analyst', '--clearance' => 'top-secret']));
    }

    public function test_inviters_cannot_exceed_their_own_clearance_or_create_super_admins(): void
    {
        $this->invite('director@example.test', 'ci_director', Classification::Confidential);
        $this->accept($this->sentToken('director@example.test'));
        $director = User::query()->where('email', 'director@example.test')->sole();

        $this->assertInvalid(fn () => $this->invite('above@example.test', 'ci_analyst', Classification::Restricted, $director), 'clearance');
        $this->assertInvalid(fn () => $this->invite('boss@example.test', 'super_admin', Classification::Internal, $director), 'role');

        $this->invite('peer@example.test', 'ci_analyst', Classification::Confidential, $director);
        $audit = DB::table('audit_events')->where('action', 'user.invited')->orderByDesc('id')->first();
        $this->assertSame(['user', $director->id], [$audit?->actor_type, $audit?->actor_id]);
    }

    public function test_weak_or_mismatched_passwords_keep_the_invitation_open(): void
    {
        $this->invite('careful@example.test');
        $token = $this->sentToken('careful@example.test');

        $this->post("/invitations/$token", ['name' => 'C', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post("/invitations/$token", ['name' => 'C', 'password' => self::PASSWORD, 'password_confirmation' => 'something else entirely'])->assertSessionHasErrors('password');
        $this->post("/invitations/$token", ['name' => '', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertSessionHasErrors('name');

        $this->assertSame(0, User::query()->count());
        $this->get("/invitations/$token")->assertOk();
    }

    public function test_acceptance_is_rate_limited_per_ip(): void
    {
        foreach (range(1, 10) as $_) {
            $this->get('/invitations/'.str_repeat('b', 64))->assertNotFound();
        }
        $this->get('/invitations/'.str_repeat('b', 64))->assertStatus(429);
    }

    public function test_a_second_concurrent_acceptance_cannot_create_another_account(): void
    {
        $this->invite('race@example.test');
        $token = $this->sentToken('race@example.test');
        $service = $this->app->make(InvitationService::class);

        $first = $service->accept($token, 'First', self::PASSWORD);
        $second = $service->accept($token, 'Second', self::PASSWORD);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(['First'], User::query()->pluck('name')->all());
    }

    private function invite(string $email, string $role = 'exec_reader', Classification $clearance = Classification::Internal, ?User $inviter = null): void
    {
        $this->app->make(InvitationService::class)->invite($email, $role, $clearance, $inviter);
    }

    /**
     * @return TestResponse<Response>
     */
    private function accept(string $token): TestResponse
    {
        return $this->post("/invitations/$token", ['name' => 'New User', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
    }

    private function sentToken(string $email): string
    {
        $token = null;
        Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) use ($email, &$token): bool {
            if ($mail->hasTo($email)) {
                $token = $mail->token;
            }

            return true;
        });
        $this->assertIsString($token, "No invitation email to $email.");

        return $token;
    }

    private function assertInvalid(callable $invite, string $field): void
    {
        try {
            $invite();
            $this->fail("Expected a validation error on [$field].");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
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
