<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * The one error envelope used by every failing API response:
 *
 *   { "message": "Human readable text", "code": "machine_readable_code", "errors": { "field": ["..."] } }
 *
 * `errors` is only present for validation failures.
 */
final class ApiError
{
    /**
     * @param  array<string, list<string>>|null  $errors
     * @param  array<string, string>  $headers
     */
    public static function response(int $status, string $code, string $message, ?array $errors = null, array $headers = []): JsonResponse
    {
        $body = ['message' => $message, 'code' => $code];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status, $headers);
    }
}
