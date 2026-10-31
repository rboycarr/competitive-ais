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
 * trace, file or exception class, also with APP_DEBUG on. Server errors get a generic detail; the request ID
 * links the response to the log entry.
 */
final class ProblemDetails
{
    public static function wants(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if ($e instanceof HttpResponseException || ! self::wants($request)) {
            return null; // explicit responses and HTML pages keep Laravel's handling
        }

        $headers = [];
        $problem = ['type' => 'about:blank'];
        if ($e instanceof ValidationException) {
            $status = $e->status;
            $detail = 'The given data was invalid.';
        } elseif ($e instanceof AuthenticationException) {
            $status = 401;
            $detail = 'Authentication is required.';
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $headers = $e->getHeaders();
            $detail = $status < 500 && $e->getMessage() !== '' ? $e->getMessage() : null;
        } else {
            $status = 500;
            $detail = null;
        }
        if ($status >= 500) {
            $detail = 'An unexpected error occurred. Quote the request ID when reporting it.';
        }

        $problem += [
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
