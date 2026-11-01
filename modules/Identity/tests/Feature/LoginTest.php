<?php

namespace Modules\Identity\Tests\Feature;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Modules\Identity\Actions\AuthenticateUser;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * P1-11 / docs/07 Authentication / docs/09 #2: password login for active accounts only, TOTP two-factor with recovery
 * codes, throttling, and an audit trail that never contains credentials or codes.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery staple';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_the_login_page_renders_for_guests(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Identity/Login'));
    }

    public function test_an_active_user_signs_in_and_it_is_audited(): void
    {
        $user = $this->user('ana@example.test');

        $response = $this->post('/login', ['email' => 'ANA@example.test ', 'password' => self::PASSWORD]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->last_login_at);
        $audit = DB::table('audit_events')->where('action', 'auth.login_succeeded')->sole();
        $this->assertSame(['user', $user->id, $user->ulid, '{"two_factor": false}'], [$audit->actor_type, $audit->actor_id, $audit->subject_ulid, $audit->context]);
    }

    public function test_every_failure_looks_the_same_and_records_only_a_reason(): void
    {
        $this->user('active@example.test');
        $this->user('suspended@example.test', 'suspended');

        $attempts = [
            ['active@example.test', 'wrong password entirely', 'invalid_password'],
            ['nobody@example.test', self::PASSWORD, 'unknown_account'],
            ['suspended@example.test', self::PASSWORD, 'inactive_account'],
        ];
        $messages = [];
        foreach ($attempts as [$email, $password, $reason]) {
            $response = $this->from('/login')->post('/login', ['email' => $email, 'password' => $password]);
            $response->assertRedirect('/login')->assertSessionHasErrors('email');
            $messages[] = session('errors')->first('email');
            $this->assertGuest();

            $context = DB::table('audit_events')->where('action', 'auth.login_failed')->orderByDesc('id')->value('context');
            $this->assertSame(['reason' => $reason], json_decode((string) $context, true));
        }

        $this->assertCount(1, array_unique($messages), 'Unknown, inactive and wrong-password failures must look identical.');
        $everything = json_encode(DB::table('audit_events')->get());
        foreach (['wrong password entirely', self::PASSWORD, 'nobody@example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $everything);
        }
    }

    public function test_every_attempt_costs_exactly_one_password_hash_check(): void
    {
        // TASK-0019 #1: unknown and inactive accounts must not answer faster than a wrong password.
        $this->user('active@example.test');
        $this->user('suspended@example.test', 'suspended');
        $this->user('gone@example.test', 'deactivated');
        $hasher = new CountingHasher($this->app->make('hash'));
        $this->app->instance(Hasher::class, $hasher);

        foreach (['nobody@example.test' => true, 'suspended@example.test' => false, 'gone@example.test' => false, 'active@example.test' => false] as $email => $usesDummy) {
            $hasher->reset();
            $this->post('/login', ['email' => $email, 'password' => 'wrong password entirely'])->assertSessionHasErrors('email');
            $this->assertCount(1, $hasher->checks(), "One hash check for [$email].");
            $this->assertSame($usesDummy, ($hasher->checks()[0] ?? null) === AuthenticateUser::DUMMY_HASH, "Dummy hash only for unknown accounts [$email].");
        }
        $this->post('/login', ['email' => 'gone@example.test', 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_the_dummy_hash_costs_the_same_as_a_real_password_hash(): void
    {
        $production = (require config_path('hashing.php'))['argon'];
        $info = password_get_info(AuthenticateUser::DUMMY_HASH);

        $this->assertSame('argon2id', $info['algoName']);
        $this->assertSame(
            ['memory_cost' => $production['memory'], 'time_cost' => $production['time'], 'threads' => $production['threads']],
            $info['options'],
            'Regenerate AuthenticateUser::DUMMY_HASH when the Argon2id parameters change.',
        );
    }

    public function test_guessing_one_account_from_many_ips_is_throttled_too(): void
    {
        // TASK-0019 #2: the per-account limit does not depend on the source IP.
        $this->user('target@example.test');

        foreach (range(1, 10) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.$i"])
                ->post('/login', ['email' => 'target@example.test', 'password' => 'guess '.$i])->assertSessionHasErrors('email');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->post('/login', ['email' => 'target@example.test', 'password' => self::PASSWORD])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_signing_in_starts_a_new_session(): void
    {
        $this->user('session@example.test');
        $this->get('/login');
        $before = $this->app['session']->getId();

        $this->post('/login', ['email' => 'session@example.test', 'password' => self::PASSWORD])->assertRedirect('/');

        $this->assertNotSame($before, $this->app['session']->getId());
    }

    public function test_two_factor_endpoints_refuse_guests(): void
    {
        $this->post('/user/two-factor-authentication')->assertRedirect('/login');
        $this->delete('/user/two-factor-authentication')->assertRedirect('/login');
        $this->getJson('/user/two-factor-qr-code')->assertUnauthorized();
        $this->getJson('/user/two-factor-recovery-codes')->assertUnauthorized();
        $this->get('/user/security')->assertRedirect('/login');
    }

    public function test_new_recovery_codes_replace_the_old_ones(): void
    {
        $user = $this->userWithTwoFactor('regen@example.test');
        $old = $user->recoveryCodes();

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->post('/user/two-factor-recovery-codes')->assertRedirect();

        $new = $user->refresh()->recoveryCodes();
        $this->assertCount(8, $new);
        $this->assertSame([], array_intersect($old, $new));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'auth.recovery_codes_generated')->count());
    }

    public function test_logins_are_throttled_per_account_and_ip(): void
    {
        $this->user('target@example.test');

        foreach (range(1, 5) as $_) {
            $this->post('/login', ['email' => 'target@example.test', 'password' => 'not the password']);
        }
        $this->post('/login', ['email' => 'target@example.test', 'password' => self::PASSWORD])->assertStatus(429);
        $this->assertGuest();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'auth.login_throttled')->count());
    }

    public function test_two_factor_can_be_enabled_confirmed_and_then_required_at_login(): void
    {
        $user = $this->user('mfa@example.test');
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        $this->post('/user/two-factor-authentication')->assertRedirect();
        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $secret = (string) decrypt((string) $user->two_factor_secret);
        $this->assertStringNotContainsString($secret, (string) DB::table('users')->where('id', $user->id)->value('two_factor_secret'), 'The secret is stored encrypted.');

        $this->post('/user/confirmed-two-factor-authentication', ['code' => '000000'])->assertSessionHasErrors();
        $this->post('/user/confirmed-two-factor-authentication', ['code' => $this->totp($secret)])->assertSessionHasNoErrors();
        $this->assertTrue($user->refresh()->hasEnabledTwoFactorAuthentication());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'auth.two_factor_enabled')->count());

        $this->post('/logout');
        $this->assertGuest();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'auth.logout')->count());

        // Password alone is not enough any more.
        $this->post('/login', ['email' => 'mfa@example.test', 'password' => self::PASSWORD])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->get('/two-factor-challenge')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Identity/TwoFactorChallenge'));

        $this->post('/two-factor-challenge', ['code' => '123456'])->assertSessionHasErrors();
        $this->assertGuest();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'auth.two_factor_failed')->count());

        // The confirmation code is spent (Fortify refuses a replayed code); the next window's code is still valid.
        $this->post('/two-factor-challenge', ['code' => $this->totp($secret, 1)])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('{"two_factor": true}', DB::table('audit_events')->where('action', 'auth.login_succeeded')->orderByDesc('id')->value('context'));
    }

    public function test_a_recovery_code_works_once(): void
    {
        $user = $this->userWithTwoFactor('recover@example.test');
        $codes = $user->recoveryCodes();
        $this->assertCount(8, $codes);

        $this->post('/login', ['email' => 'recover@example.test', 'password' => self::PASSWORD])->assertRedirect('/two-factor-challenge');
        $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertNotContains($codes[0], $user->refresh()->recoveryCodes());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'auth.recovery_code_used')->count());

        $this->post('/logout');
        $this->post('/login', ['email' => 'recover@example.test', 'password' => self::PASSWORD]);
        $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])->assertSessionHasErrors();
        $this->assertGuest();

        $everything = json_encode(DB::table('audit_events')->get());
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, (string) $everything);
        }
    }

    public function test_two_factor_settings_need_a_recent_password_confirmation(): void
    {
        $user = $this->user('confirm@example.test');

        $this->actingAs($user)->post('/user/two-factor-authentication')->assertRedirect('/user/confirm-password');
        $this->assertNull($user->refresh()->two_factor_secret);

        $this->get('/user/security')->assertRedirect('/user/confirm-password');
        $this->get('/user/confirm-password')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Identity/ConfirmPassword'));
        $this->post('/user/confirm-password', ['password' => 'not my password'])->assertSessionHasErrors('password');
        $this->post('/user/confirm-password', ['password' => self::PASSWORD])->assertRedirect('/user/security');
        $this->get('/user/security')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Identity/Security')
            ->where('twoFactorEnabled', false)->where('twoFactorPending', false));
    }

    public function test_turning_two_factor_off_is_audited(): void
    {
        $user = $this->userWithTwoFactor('off@example.test');

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->delete('/user/two-factor-authentication')->assertRedirect();

        $this->assertFalse($user->refresh()->hasEnabledTwoFactorAuthentication());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'auth.two_factor_disabled')->count());
    }

    public function test_the_security_page_and_shared_props_expose_no_secrets(): void
    {
        $user = $this->userWithTwoFactor('props@example.test');

        $page = $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get('/user/security')->assertOk();

        $page->assertInertia(fn (AssertableInertia $p) => $p->where('auth.user', ['ulid' => $user->ulid, 'name' => $user->name, 'email' => 'props@example.test'])
            ->where('twoFactorEnabled', true));
        $body = (string) $page->getContent();
        foreach ([(string) decrypt((string) $user->two_factor_secret), ...$user->recoveryCodes(), 'two_factor_secret', 'password'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_features_left_off_have_no_routes(): void
    {
        foreach (['/register', '/forgot-password', '/reset-password/x', '/user/profile-information', '/user/password'] as $path) {
            $this->get($path)->assertNotFound();
        }
        $this->post('/register', ['email' => 'self@example.test', 'password' => self::PASSWORD])->assertNotFound();
    }

    private function user(string $email, string $status = 'active'): User
    {
        $user = new User;
        $user->forceFill([
            'ulid' => (string) Str::ulid(), 'name' => 'Test '.$email, 'email' => $email, 'password' => self::PASSWORD,
            'clearance' => Classification::Internal, 'status' => $status, 'email_verified_at' => now(),
        ])->save();
        $user->assignRole('ci_analyst');

        return $user;
    }

    private function userWithTwoFactor(string $email): User
    {
        $user = $this->user($email);
        $user->forceFill([
            'two_factor_secret' => encrypt((new Google2FA)->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode(array_map(fn () => Str::random(10).'-'.Str::random(10), range(1, 8)))),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }

    /** The TOTP code for the current 30-second window, or a later one. */
    private function totp(string $secret, int $windowsAhead = 0): string
    {
        $google2fa = new Google2FA;

        return $google2fa->oathTotp($secret, $google2fa->getTimestamp() + $windowsAhead);
    }
}

/** Records which hash each check compared against, and delegates to the real hasher. */
final class CountingHasher implements Hasher
{
    /** @var list<string> */
    private array $checks = [];

    public function __construct(private readonly Hasher $inner) {}

    public function reset(): void
    {
        $this->checks = [];
    }

    /** @return list<string> the hashes compared against since the last reset */
    public function checks(): array
    {
        return $this->checks;
    }

    /** @param array<string, mixed> $options */
    public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        $this->checks[] = (string) $hashedValue;

        return $this->inner->check($value, $hashedValue, $options);
    }

    /** @return array<string, mixed> */
    public function info($hashedValue): array
    {
        return $this->inner->info($hashedValue);
    }

    /** @param array<string, mixed> $options */
    public function make(#[\SensitiveParameter] $value, array $options = []): string
    {
        return $this->inner->make($value, $options);
    }

    /** @param array<string, mixed> $options */
    public function needsRehash($hashedValue, array $options = []): bool
    {
        return $this->inner->needsRehash($hashedValue, $options);
    }
}
