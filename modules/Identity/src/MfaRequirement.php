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
     * Named routes a user who must enroll can still reach: the security page, Fortify's two-factor and
     * password-confirmation endpoints, and sign-out. Matched by route name, not path, so a new path under the same
     * prefix is not let through (TASK-0021 #3); a test checks every name is registered.
     */
    public const ENROLLMENT_ROUTES = [
        'security.show',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'logout',
    ];
}
