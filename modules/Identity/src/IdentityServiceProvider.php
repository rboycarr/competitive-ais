<?php

namespace Modules\Identity;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;
use Modules\Identity\Actions\AuthenticateUser;
use Modules\Identity\Console\InviteUser;
use Modules\Identity\Http\Middleware\EnsureActiveAccount;
use Modules\Identity\Http\Middleware\EnsureMfaEnrolled;
use Modules\Identity\Listeners\AuditAuthentication;
use Modules\Identity\Models\User;
use Modules\Platform\Audit\AuditActions;
use Modules\Platform\Support\ModuleServiceProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identity (M01, docs/03): users, invitations (P1-10), login with TOTP two-factor authentication (P1-11), roles,
 * permissions and teams (P1-13), access grants (P1-14).
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
        $this->enforceMfaEnrollment();

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
        $actions->define('role.assigned', ['role' => 'code']);
        $actions->define('role.revoked', ['role' => 'code']);
        $actions->define('role.created', ['role' => 'code', 'permissions' => 'code[]']);
        $actions->define('role.permissions_changed', ['role' => 'code', 'added' => 'code[]', 'removed' => 'code[]']);
        $actions->define('team.created', ['lead' => 'ulid']);
        $actions->define('team.updated', ['fields' => 'code[]', 'lead' => 'ulid']);
        $actions->define('team.member_added', ['member' => 'ulid']);
        $actions->define('team.member_removed', ['member' => 'ulid']);
        $actions->define('access_grant.created', [
            'grantee_type' => 'code', 'grantee' => 'ulid', 'scope' => 'code', 'max_classification' => 'code', 'can_export' => 'bool',
            'expires_at' => 'int', // Unix seconds
        ]);
        $actions->define('access_grant.revoked', ['grantee_type' => 'code', 'grantee' => 'ulid']);
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

        Fortify::authenticateUsing(fn (Request $request): ?User => $this->app->make(AuthenticateUser::class)(
            (string) $request->input('email'),
            (string) $request->input('password'),
        ));

        // Login limits (TASK-0019 #2): per account from one IP, per account from all IPs (distributed guessing), and per
        // IP (spraying many accounts). The per-account limit lets an attacker keep one account throttled while they
        // keep trying; each refusal is audited, and the window is one minute.
        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));
            // The throttle middleware answers before Fortify runs, so the refusal is audited here (no Lockout event).
            $refuse = function (Request $request, array $headers): Response {
                event(new Lockout($request));

                return response('Too Many Attempts.', 429, $headers);
            };

            return [
                Limit::perMinute(5)->by('account-ip:'.$email.'|'.$request->ip())->response($refuse),
                Limit::perMinute(10)->by('account:'.$email)->response($refuse),
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

    /**
     * The signed-in user's display data on every page, whether they must still turn on two-factor authentication
     * (P1-12), which admin links to show (P1-13), and the last status message; nothing else about the account.
     */
    private function shareAuthenticatedUser(): void
    {
        Inertia::share('auth', function (Request $request): array {
            $user = $request->user();

            return [
                'user' => $user instanceof User ? ['ulid' => $user->ulid, 'name' => $user->name, 'email' => $user->email] : null,
                'mfaRequired' => $user instanceof User && ! $user->hasEnabledTwoFactorAuthentication() && $user->requiresMfa(),
                // Only decides which links to show; every action is authorized on the server (P1-13).
                'can' => $user instanceof User ? [
                    'userView' => $user->hasPermissionTo('user.view'),
                    'roleManage' => $user->hasPermissionTo('role.manage'),
                    'teamManage' => $user->hasPermissionTo('team.manage'),
                    'grantManage' => $user->hasPermissionTo('grant.manage'),
                ] : [],
            ];
        });
        Inertia::share('flash', fn (Request $request): array => [
            'status' => $request->hasSession() ? $request->session()->get('status') : null,
        ]);
    }

    /** Users holding a privileged permission can only enroll or sign out until two-factor is on (P1-12). */
    private function enforceMfaEnrollment(): void
    {
        /** @var Kernel $kernel the bound HTTP kernel (resolving the concrete class would build a second one) */
        $kernel = $this->app->make(HttpKernel::class);
        // Both route groups: API routes (/api/v1, Sanctum tokens later) are held to the same rules (TASK-0021 #2).
        // Inactive accounts are signed out first (TASK-0023 #3), then two-factor enrollment is enforced. Appending is
        // idempotent: the kernel skips middleware already in the group (TASK-0024 #2).
        foreach (['web', 'api'] as $group) {
            $kernel->appendMiddlewareToGroup($group, EnsureActiveAccount::class);
            $kernel->appendMiddlewareToGroup($group, EnsureMfaEnrolled::class);
        }
    }
}
