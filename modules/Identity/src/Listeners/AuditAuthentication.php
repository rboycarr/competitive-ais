<?php

namespace Modules\Identity\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Modules\Identity\Models\User;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Classification;

/**
 * Audit trail for authentication (P1-11, docs/07 Authentication, skills/06: "immutable audit events for login").
 * Never records passwords, TOTP codes, recovery codes or secrets; a failed login's submitted email is used only to
 * find the account it targeted, and only a reason code is stored.
 */
class AuditAuthentication
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
    ) {}

    public function login(Login $event): void
    {
        $user = $event->user;
        if (! $user instanceof User) {
            return;
        }
        $this->db->transaction(function () use ($user): void {
            $user->forceFill(['last_login_at' => now('UTC')])->saveQuietly();
            $this->audit->record('auth.login_succeeded', $user, ['two_factor' => $user->hasEnabledTwoFactorAuthentication()], Actor::user($user->id), Classification::Internal);
        });
    }

    public function failed(Failed $event): void
    {
        // $event->credentials holds the submitted password: only the email is read, to find the targeted account.
        $email = Str::lower(trim((string) ($event->credentials['email'] ?? '')));
        $user = $email === '' ? null : User::query()->where('email', $email)->first();
        $reason = match (true) {
            $user === null => 'unknown_account',
            $user->status !== 'active' => 'inactive_account',
            default => 'invalid_password',
        };

        $this->record('auth.login_failed', $user, ['reason' => $reason]);
    }

    public function lockout(Lockout $event): void
    {
        $email = Str::lower(trim((string) $event->request->input('email', '')));
        $this->record('auth.login_throttled', $email === '' ? null : User::query()->where('email', $email)->first(), []);
    }

    public function logout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->record('auth.logout', $event->user, [], Actor::user($event->user->id));
        }
    }

    public function twoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->forUser('auth.two_factor_enabled', $event->user);
    }

    public function twoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->forUser('auth.two_factor_disabled', $event->user);
    }

    public function recoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->forUser('auth.recovery_codes_generated', $event->user);
    }

    public function recoveryCodeReplaced(RecoveryCodeReplaced $event): void
    {
        $this->forUser('auth.recovery_code_used', $event->user);
    }

    public function twoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->record('auth.two_factor_failed', $event->user instanceof User ? $event->user : null, []);
    }

    private function forUser(string $action, mixed $user): void
    {
        if ($user instanceof User) {
            $this->record($action, $user, [], Actor::user($user->id));
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $action, ?User $subject, array $context, ?Actor $actor = null): void
    {
        $this->db->transaction(fn () => $this->audit->record($action, $subject, $context, $actor ?? Actor::system(), Classification::Internal));
    }
}
