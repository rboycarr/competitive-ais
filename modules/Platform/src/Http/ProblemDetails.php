<?php

namespace Modules\Platform\Http;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * RFC 9457 problem+json error responses for the API and JSON requests (P1-08, docs/08 Errors, docs/07 API).
 *
 * Fields: type, title, status, detail, instance, request_id, and errors for validation failures. Never a stack
 * trace, file, exception class or exception message (TASK-0014 #1): `detail` is a fixed text per status, except
 * for validation failures and PublicHttpException, whose message is written for the client. Only HTTP headers
 * on an allowlist are passed through. The request ID links the response to the log entry.
 */
final class ProblemDetails
{
    /** Fixed, safe details; statuses not listed get none (the title says enough). */
    private const DETAILS = [
        400 => 'The request could not be processed.',
        401 => 'Authentication is required.',
        403 => 'You are not allowed to do this.',
        404 => 'The requested resource was not found.',
        405 => 'This method is not allowed for the resource.',
        409 => 'The request conflicts with the current state of the resource.',
        410 => 'The resource is no longer available.',
        413 => 'The request is too large.',
        415 => 'The media type is not supported.',
        419 => 'The page expired; reload and try again.',
        422 => 'The given data was invalid.',
        429 => 'Too many requests; retry after the time given in Retry-After.',
        500 => 'An unexpected error occurred. Quote the request ID when reporting it.',
        503 => 'The service is temporarily unavailable.',
    ];

    private const PASSED_HEADERS = ['Retry-After', 'Allow', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'];

    public static function wants(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if ($e instanceof HttpResponseException || ! self::wants($request)) {
            return null; // explicit responses and HTML pages keep Laravel's handling
        }

        $status = match (true) {
            $e instanceof ValidationException => $e->status,
            $e instanceof AuthenticationException => 401,
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            default => 500,
        };
        $headers = $e instanceof HttpExceptionInterface
            ? array_intersect_key($e->getHeaders(), array_flip(self::PASSED_HEADERS))
            : [];
        $detail = $e instanceof PublicHttpException && $status < 500 && $e->getMessage() !== ''
            ? $e->getMessage()
            : (self::DETAILS[$status] ?? self::DETAILS[$status >= 500 ? 500 : 400]);

        $problem = [
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail,
            'instance' => '/'.ltrim($request->path(), '/'),
            'request_id' => Context::get('request_id'),
        ];
        if ($e instanceof ValidationException) {
            $problem['errors'] = $e->errors();
        }

        return new JsonResponse(array_filter($problem, fn (mixed $value): bool => $value !== null), $status,
            ['Content-Type' => 'application/problem+json'] + $headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
