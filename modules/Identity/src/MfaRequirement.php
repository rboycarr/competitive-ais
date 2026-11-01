<?php

namespace Modules\Identity;

/**
 * Permissions that only count once the user has confirmed TOTP two-factor authentication (P1-12, docs/03 Identity
 * rules, docs/07 Authentication). export.run arrives in Phase 2; listing it now keeps the rule complete.
 */
final class MfaRequirement
{
    public const PRIVILEGED_PERMISSIONS = [
        'user.manage',
        'role.manage',
        'grant.manage',
        'intelligence.publish',
        'source.approve',
        'export.run',
    ];

    /**
     * Routes a user who must enroll can still reach: the security page, Fortify's two-factor and password-confirmation
     * endpoints, and sign-out. A route passes only if both its name and its URI match (TASK-0021 #3, TASK-0022), so
     * neither a look-alike path nor another route reusing one of these names is let through; tests check both.
     */
    public const ENROLLMENT_ROUTES = [
        'security.show' => 'user/security',
        'two-factor.enable' => 'user/two-factor-authentication',
        'two-factor.disable' => 'user/two-factor-authentication',
        'two-factor.confirm' => 'user/confirmed-two-factor-authentication',
        'two-factor.qr-code' => 'user/two-factor-qr-code',
        'two-factor.secret-key' => 'user/two-factor-secret-key',
        'two-factor.recovery-codes' => 'user/two-factor-recovery-codes',
        'two-factor.regenerate-recovery-codes' => 'user/two-factor-recovery-codes',
        'password.confirm' => 'user/confirm-password',
        'password.confirm.store' => 'user/confirm-password',
        'password.confirmation' => 'user/confirmed-password-status',
        'logout' => 'logout',
        // Signing out other devices protects the account, so it stays reachable before enrollment (P1-17).
        'sessions.destroy' => 'user/sessions/{handle}',
        'sessions.destroy-others' => 'user/sessions',
    ];
}
