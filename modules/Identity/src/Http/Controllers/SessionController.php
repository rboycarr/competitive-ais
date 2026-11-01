<?php

namespace Modules\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Identity\Actions\SessionService;
use Modules\Identity\Models\User;

/**
 * Signing out other devices (P1-17), from the security page and behind a recent password confirmation. The session
 * list itself is part of the security page (SecurityController).
 */
class SessionController
{
    public function destroy(Request $request, string $handle, SessionService $sessions): RedirectResponse
    {
        // Unknown, foreign or current sessions look the same: not found.
        abort_unless($sessions->revoke($this->user($request), $handle, $request->session()->getId()), 404);

        return back()->with('status', 'That device has been signed out.');
    }

    public function destroyOthers(Request $request, SessionService $sessions): RedirectResponse
    {
        $count = $sessions->revokeOthers($this->user($request), $request->session()->getId());

        return back()->with('status', $count === 1 ? '1 other device has been signed out.' : "$count other devices have been signed out.");
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
