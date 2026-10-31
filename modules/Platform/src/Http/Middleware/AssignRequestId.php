<?php

namespace Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a fresh ULID (P1-08, docs/07 Logging). It goes into Laravel's Context, so log entries, audit
 * rows (AuditLogger) and problem+json errors carry it, and back to the client in X-Request-Id. A request ID sent by
 * the client is never trusted or reused.
 *
 * Registered first in the global middleware stack, so error responses carry the header too.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::ulid();
        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
