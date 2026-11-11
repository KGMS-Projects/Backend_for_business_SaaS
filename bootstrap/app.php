<?php

use App\Exceptions\WorkflowException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi();
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $isApi($request));

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json([
                    'message' => 'Authentication is required. Send a valid Bearer token.',
                    'code' => 'unauthenticated',
                ], 401);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => 'validation_failed',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (WorkflowException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => $e->errorCode,
                ], 422);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json([
                    'message' => 'Too many requests. Please slow down.',
                    'code' => 'too_many_requests',
                ], 429, array_map('strval', $e->getHeaders()));
            }
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json([
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : 'You are not allowed to perform this action.',
                    'code' => 'forbidden',
                ], 403);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json([
                    'message' => 'The requested resource was not found.',
                    'code' => 'not_found',
                ], 404);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json([
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : 'An error occurred.',
                    'code' => $e->getStatusCode() === 405 ? 'method_not_allowed' : 'http_error',
                ], $e->getStatusCode(), array_map('strval', $e->getHeaders()));
            }
        });
    })->create();
