<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

final class ApiErrorResponse
{
    /** @param list<array{field: string, issue: string}> $details */
    public static function make(string $code, string $message, array $details, int $status): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ], $status);
    }
}
