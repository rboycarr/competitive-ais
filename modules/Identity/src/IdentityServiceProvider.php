<?php

namespace Modules\Identity;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;
use Modules\Identity\Console\InviteUser;
use Modules\Identity\Listeners\AuditAuthentication;
use Modules\Identity\Models\User;
use Modules\Platform\Audit\AuditActions;
use Modules\Platform\Support\ModuleServiceProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identity (M01, docs/03): users, invitations (P1-10), login with TOTP two-factor authentication (P1-11), roles and
 * permissions; teams and access grants follow in Sprint 1.1.
 */
class IdentityServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        $this->loadViewsFrom($this->modulePath('resources/views'), 'identity');
        $this->defineAuditActions();
        $this->configureAuthentication();
        $this->shareAuthenticatedUser();

        RateLimiter::for('invitations', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([InviteUser::class]);
        }
    }

    private function defineAuditActions(): void
    {
        $actions = $this->app->make(AuditActions::class);
        $actions->define('user.invited', ['invitation_ulid' => 'ulid', 'role' => 'code', 'clearance' => 'code', 'replaced' => 'int']);
        $actions->define('user.activated', ['invitation_ulid' => 'ulid', 'role' => 'code', 'clearance' => 'code']);
        $actions->define('auth.login_succeeded', ['two_factor' => 'bool']);
        $actions->define('auth.login_failed', ['reason' => 'code']);
        $actions->define('auth.login_throttled');
        $actions->define('auth.logout');
        $actions->define('auth.two_factor_enabled');
        $actions->define('auth.two_factor_disabled');
        $actions->define('auth.two_factor_failed');
        $actions->define('auth.recovery_codes_generated');
        $actions->define('auth.recovery_code_used');
    }

    /**
     * Fortify (P1-11): Inertia pages, only active accounts can sign in (the same failure for every reason, so the
     * response does not reveal whether an account exists or is suspended), throttling per account+IP and per IP.
     */
    private function configureAuthentication(): void
    {
        Fortify::loginView(fn () => Inertia::render('Identity/Login'));
        Fortify::twoFactorChallengeView(fn () => Inertia::render('Identity/TwoFactorChallenge'));
        Fortify::confirmPasswordView(fn () => Inertia::render('Identity/ConfirmPassword'));

        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', Str::lower(trim((string) $request->input('email'))))->first();

            return $user !== null && $user->status === 'active' && Hash::check((string) $request->input('password'), $user->password)
                ? $user
                : null;
        });

        RateLimiter::for('login', function (Request $request) {
            $account = Str::lower(trim((string) $request->input('email'))).'|'.$request->ip();
            // The throttle middleware answers before Fortify runs, so the refusal is audited here (no Lockout event).
            $refuse = function (Request $request, array $headers): Response {
                event(new Lockout($request));

                return response('Too Many Attempts.', 429, $headers);
            };

            return [
                Limit::perMinute(5)->by('account:'.$account)->response($refuse),
                Limit::perMinute(30)->by('ip:'.$request->ip())->response($refuse),
            ];
        });
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by('2fa:'.$request->session()->get('login.id').'|'.$request->ip()));

        Event::listen(Login::class, [AuditAuthentication::class, 'login']);
        Event::listen(Failed::class, [AuditAuthentication::class, 'failed']);
        Event::listen(Lockout::class, [AuditAuthentication::class, 'lockout']);
        Event::listen(Logout::class, [AuditAuthentication::class, 'logout']);
        Event::listen(TwoFactorAuthenticationConfirmed::class, [AuditAuthentication::class, 'twoFactorConfirmed']);
        Event::listen(TwoFactorAuthenticationDisabled::class, [AuditAuthentication::class, 'twoFactorDisabled']);
        Event::listen(TwoFactorAuthenticationFailed::class, [AuditAuthentication::class, 'twoFactorFailed']);
        Event::listen(RecoveryCodesGenerated::class, [AuditAuthentication::class, 'recoveryCodesGenerated']);
        Event::listen(RecoveryCodeReplaced::class, [AuditAuthentication::class, 'recoveryCodeReplaced']);
    }

    /** The signed-in user's display data on every page; nothing else about the account is shared. */
    private function shareAuthenticatedUser(): void
    {
        Inertia::share('auth', fn (Request $request): array => [
            'user' => ($user = $request->user()) instanceof User ? [
                'ulid' => $user->ulid,
                'name' => $user->name,
                'email' => $user->email,
            ] : null,
        ]);
    }
}
