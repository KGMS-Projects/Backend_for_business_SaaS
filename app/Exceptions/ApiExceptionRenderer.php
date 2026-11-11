<?php

namespace App\Exceptions;

use App\Support\ApiError;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Turns every exception raised under /api into the consistent JSON error envelope (see ApiError)
 * with the right status code: 401, 403, 404, 405, 422, 429 or 500. Nothing under /api ever returns HTML.
 */
final class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => self::isApi($request));

        $exceptions->render(fn (AuthenticationException $e, Request $request) => self::isApi($request)
            ? ApiError::response(401, 'unauthenticated', 'Authentication is required. Send a valid Bearer token.')
            : null);

        $exceptions->render(fn (ValidationException $e, Request $request) => self::isApi($request)
            ? ApiError::response(422, 'validation_failed', $e->getMessage(), $e->errors())
            : null);

        // State rules: "this request cannot do that right now".
        $exceptions->render(fn (WorkflowException $e, Request $request) => self::isApi($request)
            ? ApiError::response(422, $e->errorCode, $e->getMessage())
            : null);

        // Must come before the generic HttpException handler: it is an HttpException too.
        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => self::isApi($request)
            ? ApiError::response(429, 'too_many_requests', 'Too many requests. Please slow down.', null, array_map('strval', $e->getHeaders()))
            : null);

        // Laravel converts AuthorizationException (policy / gate denial) into this one.
        $exceptions->render(fn (AccessDeniedHttpException $e, Request $request) => self::isApi($request)
            ? ApiError::response(403, 'forbidden', $e->getMessage() !== '' ? $e->getMessage() : 'You are not allowed to perform this action.')
            : null);

        // Covers unknown routes and Eloquent's ModelNotFoundException (converted by Laravel).
        // The message is generic on purpose: the default one leaks model class names.
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => self::isApi($request)
            ? ApiError::response(404, 'not_found', 'The requested resource was not found.')
            : null);

        $exceptions->render(fn (HttpExceptionInterface $e, Request $request) => self::isApi($request)
            ? ApiError::response(
                $e->getStatusCode(),
                $e->getStatusCode() === 405 ? 'method_not_allowed' : 'http_error',
                $e->getMessage() !== '' ? $e->getMessage() : (Response::$statusTexts[$e->getStatusCode()] ?? 'HTTP error'),
                null,
                array_map('strval', $e->getHeaders()),
            )
            : null);

        // Anything unexpected. Details are only exposed when APP_DEBUG is on.
        $exceptions->render(fn (Throwable $e, Request $request) => self::isApi($request)
            ? ApiError::response(500, 'server_error', config('app.debug') ? $e->getMessage() : 'Something went wrong on our side.')
            : null);
    }

    private static function isApi(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }
}
