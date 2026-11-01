<?php

namespace Modules\Identity\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Identity\Models\User;

/**
 * Security settings (P1-11): turn TOTP two-factor authentication on or off and manage recovery codes. The actions
 * themselves are Fortify's endpoints, which require a recent password confirmation.
 */
class SecurityController
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('Identity/Security', [
            'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
            'twoFactorPending' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null,
        ]);
    }
}
