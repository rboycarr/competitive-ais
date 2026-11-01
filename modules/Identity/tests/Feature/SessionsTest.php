<?php

namespace Modules\Identity\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Modules\Identity\Models\User;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-17: users see their own sessions and sign out other devices; session IDs never reach the browser; revoking
 * also cycles the remember token; every revocation is audited.
 */
class SessionsTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $user;

    private string $current;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['session.driver' => 'database']); // PO decision 2026-11-01; phpunit.xml uses the array driver
        $this->user = $this->createUser('analyst@example.test', 'ci_analyst');
        // This device's session already exists, as it would in a browser that is signed in.
        $this->current = $this->otherSession($this->user, 'This browser', '10.0.0.2', minutesAgo: 0);
    }

    public function test_the_security_page_lists_only_my_sessions_without_their_ids(): void
    {
        $phone = $this->otherSession($this->user, 'Phone browser', '10.0.0.7');
        $foreign = $this->otherSession($this->createUser('other@example.test', 'ci_analyst'), 'Other person', '10.0.0.9');

        $response = $this->asCurrentDevice()->get('/user/security')->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page->component('Identity/Security')
            ->has('sessions', 2)
            ->where('sessions.0.current', true)
            ->where('sessions.1.userAgent', 'Phone browser')
            ->where('sessions.1.ipAddress', '10.0.0.7')
            ->where('sessions.1.current', false));
        $body = (string) $response->getContent();
        foreach ([$this->current, $phone, $foreign, 'Other person'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_signing_out_one_device_ends_only_that_session_and_is_audited(): void
    {
        $phone = $this->otherSession($this->user, 'Phone browser');
        $laptop = $this->otherSession($this->user, 'Laptop browser');
        $foreign = $this->otherSession($this->createUser('other@example.test', 'ci_analyst'), 'Other person');
        $this->user->forceFill(['remember_token' => 'old-remember-token'])->save();

        $handle = $this->handleFor('Phone browser');
        $this->asCurrentDevice()->delete("/user/sessions/$handle")->assertRedirect()->assertSessionHas('status');

        $this->assertSame([$laptop, $foreign], DB::table('sessions')->whereIn('id', [$phone, $laptop, $foreign])->orderBy('user_agent')->pluck('id')->all());
        $this->assertTrue(DB::table('sessions')->where('id', $this->current)->exists(), 'this device stays signed in');
        $this->assertNotSame('old-remember-token', $this->user->refresh()->getRememberToken());
        $audit = DB::table('audit_events')->where('action', 'session.revoked')->sole();
        $this->assertSame([$this->user->id, $this->user->ulid], [$audit->actor_id, $audit->subject_ulid]);
        $this->assertEquals(['scope' => 'one', 'count' => 1], json_decode((string) $audit->context, true));

        // The same handle again, another user's session or this device: not found, nothing changes.
        $this->asCurrentDevice()->delete("/user/sessions/$handle")->assertNotFound();
        $this->asCurrentDevice()->delete('/user/sessions/'.hash_hmac('sha256', 'session:'.$foreign, (string) config('app.key')))->assertNotFound();
        $this->asCurrentDevice()->delete('/user/sessions/'.hash_hmac('sha256', 'session:'.$this->current, (string) config('app.key')))->assertNotFound();
        $this->asCurrentDevice()->delete('/user/sessions/'.$phone)->assertNotFound(); // a raw session ID is not a handle
        $this->assertSame(3, DB::table('sessions')->whereIn('id', [$laptop, $foreign, $this->current])->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'session.revoked')->count());
    }

    public function test_signing_out_all_other_devices_keeps_this_one(): void
    {
        $this->otherSession($this->user, 'Phone browser');
        $this->otherSession($this->user, 'Laptop browser');
        $foreign = $this->otherSession($this->createUser('other@example.test', 'ci_analyst'), 'Other person');

        $this->asCurrentDevice()->delete('/user/sessions')->assertRedirect()->assertSessionHas('status', '2 other devices have been signed out.');

        $this->assertSame([$this->current], DB::table('sessions')->where('user_id', $this->user->id)->pluck('id')->all());
        $this->assertTrue(DB::table('sessions')->where('id', $foreign)->exists());
        $this->assertEquals(['scope' => 'others', 'count' => 2], json_decode((string) DB::table('audit_events')->where('action', 'session.revoked')->value('context'), true));
    }

    public function test_a_request_in_flight_cannot_bring_a_revoked_session_back(): void
    {
        // TASK-0029 #2: the other device's request read its session before the revocation and writes after it.
        $phone = $this->otherSession($this->user, 'Phone browser');
        $signedIn = (string) json_encode([$this->app->make('auth')->guard('web')->getName() => $this->user->id, '_token' => Str::random(40)]); // config: session.serialization = json
        DB::table('sessions')->where('id', $phone)->update(['payload' => base64_encode($signedIn)]);
        $fromPhone = function () use ($phone) {
            $this->flushSession();
            $this->defaultCookies = [];
            $this->app->make('auth')->forgetGuards();

            return $this->withCookie((string) config('session.cookie'), $phone)->get('/user/security');
        };
        $fromPhone()->assertRedirect('/user/confirm-password'); // the phone is signed in

        $handler = $this->app->make('session')->driver('database')->getHandler();
        $handler->read($phone);
        $this->app->make('auth')->forgetGuards();
        $this->asCurrentDevice()->delete('/user/sessions')->assertRedirect();
        $handler->write($phone, $signedIn);

        $this->assertFalse(DB::table('sessions')->where('id', $phone)->exists()); // an UPDATE of a missing row, no insert
        $fromPhone()->assertRedirect('/login'); // TASK-0030: the phone's session cookie no longer signs anyone in
    }

    public function test_the_revoked_devices_remember_cookie_stops_working(): void
    {
        $this->user->forceFill(['remember_token' => Str::random(60)])->save();
        $recaller = fn (): string => $this->user->id.'|'.$this->user->getRememberToken().'|'.$this->user->getAuthPassword();
        $oldCookie = $recaller();
        $fromPhone = function () use ($oldCookie) {
            // A device with only its remember cookie: no session data or signed-in guard left from other requests.
            $this->flushSession();
            $this->defaultCookies = []; // withCookie() would also send this device's session cookie
            $this->app->make('auth')->forgetGuards();

            return $this->withCookie($this->app->make('auth')->guard('web')->getRecallerName(), $oldCookie)->get('/user/security');
        };

        $fromPhone()->assertRedirect('/user/confirm-password'); // signed back in by the cookie
        $this->app->make('auth')->forgetGuards();
        $this->asCurrentDevice()->delete('/user/sessions')->assertRedirect();
        $this->user->refresh();
        $this->assertNotSame($oldCookie, $recaller());
        $fromPhone()->assertRedirect('/login');
    }

    public function test_malformed_handles_change_nothing(): void
    {
        $this->otherSession($this->user, 'Phone browser');
        $handle = $this->handleFor('Phone browser');

        foreach ([strtoupper($handle), substr($handle, 0, 63), $handle.'0', 'not-a-handle'] as $bad) {
            $this->asCurrentDevice()->delete('/user/sessions/'.$bad)->assertNotFound();
        }
        $this->assertSame(2, DB::table('sessions')->where('user_id', $this->user->id)->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'session.revoked')->count());
    }

    public function test_a_recent_password_confirmation_is_required(): void
    {
        $this->otherSession($this->user, 'Phone browser');

        $this->actingAs($this->user)->withCookie((string) config('session.cookie'), $this->current)->delete('/user/sessions')->assertRedirect('/user/confirm-password');
        $this->assertSame(2, DB::table('sessions')->where('user_id', $this->user->id)->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'session.revoked')->count());
    }

    public function test_privileged_users_can_sign_out_devices_before_enrolling(): void
    {
        // Allowlisted during enrollment (P1-12): it only protects the account.
        $admin = $this->createUser('admin@example.test', 'super_admin');
        $this->otherSession($admin, 'Phone browser');

        $this->actingAs($admin)->withCookie((string) config('session.cookie'), $this->current)
            ->withSession(['auth.password_confirmed_at' => time()])->delete('/user/sessions')->assertRedirect()->assertSessionHas('status');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $admin->id)->where('user_agent', 'Phone browser')->count());
    }

    private function asCurrentDevice(): static
    {
        return $this->actingAs($this->user)->withCookie((string) config('session.cookie'), $this->current)
            ->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function otherSession(User $user, string $agent, string $ip = '10.0.0.1', int $minutesAgo = 5): string
    {
        $id = Str::random(40);
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => $ip, 'user_agent' => $agent, 'payload' => base64_encode('[]'), 'last_activity' => now()->subMinutes($minutesAgo)->getTimestamp()]);

        return $id;
    }

    private function handleFor(string $agent): string
    {
        /** @var list<array{handle: string, userAgent: ?string}> $sessions */
        $sessions = $this->asCurrentDevice()->get('/user/security')->inertiaProps('sessions');

        return array_column($sessions, 'handle', 'userAgent')[$agent];
    }
}
