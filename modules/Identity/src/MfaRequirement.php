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
     * Paths a user who must enroll can still reach: the security page, Fortify's two-factor and password-confirmation
     * endpoints, and sign-out.
     */
    public const ENROLLMENT_PATHS = [
        'user/security',
        'user/two-factor-authentication',
        'user/confirmed-two-factor-authentication',
        'user/two-factor-qr-code',
        'user/two-factor-secret-key',
        'user/two-factor-recovery-codes',
        'user/confirm-password',
        'user/confirmed-password-status',
        'logout',
    ];
}
