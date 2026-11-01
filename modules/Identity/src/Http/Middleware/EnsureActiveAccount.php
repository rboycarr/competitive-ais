<?php

namespace Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Identity\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * A session whose account was suspended or deactivated ends at its next request (TASK-0023 #3): the user is signed
 * out, the session invalidated, and the request treated as a guest's. Permissions already refuse inactive accounts
 * (User::hasPermissionTo); this also stops them from reading anything else.
 */
class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && $user->status !== 'active') {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            // Rendered like any unauthenticated request: redirect to login, or 401 problem+json for JSON.
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
