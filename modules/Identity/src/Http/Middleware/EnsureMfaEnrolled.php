<?php

namespace Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Modules\Identity\MfaRequirement;
use Modules\Identity\Models\User;
use Modules\Platform\Http\PublicHttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in user who holds a privileged permission but has not confirmed two-factor authentication can only
 * enroll or sign out (P1-12, docs/03 Identity: "EnsureMfa blocks access until MFA is enrolled"). Pages redirect to
 * the security settings; JSON requests get 403. The permission check itself also refuses privileged permissions
 * until then (User::hasPermissionTo), so this is the second of two layers.
 */
class EnsureMfaEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route();
        $isEnrollmentRoute = $route instanceof Route
            && (MfaRequirement::ENROLLMENT_ROUTES[(string) $route->getName()] ?? null) === $route->uri();

        if (! $user instanceof User
            || $user->hasEnabledTwoFactorAuthentication()
            || $isEnrollmentRoute
            || ! $user->requiresMfa()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            throw new PublicHttpException(403, 'Two-factor authentication is required for your role. Turn it on in your security settings.');
        }

        return redirect()->route('security.show');
    }
}
