<?php

namespace Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Identity\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * A session whose account was suspended or deactivated ends at its next request (TASK-0023 #3): the user is signed
 * out, the session invalidated, and the request treated as a guest's. Permissions already refuse inactive accounts
 * (User::hasPermissionTo); this also stops them from reading anything else.
 *
 * Every configured guard is checked, not only "web" (TASK-0024 #1): a stateful guard is logged out, any other guard
 * (API tokens, when Sanctum arrives) forgets the user, so the request is refused whichever guard authenticated it.
 * Revoking stored tokens on suspension belongs to the suspension story; until then they are refused on every request.
 */
class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        foreach (array_keys((array) config('auth.guards')) as $name) {
            /** @var Guard $guard a token guard is not stateful, despite the facade's return type */
            $guard = Auth::guard($name);
            $user = $guard->user();
            if (! $user instanceof User || $user->status === 'active') {
                continue;
            }

            if ($guard instanceof StatefulGuard) {
                $guard->logout();
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }

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
