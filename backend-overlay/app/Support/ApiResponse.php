<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/** Only the HTTP layer converts service results to responses. */
final class ApiResponse
{
    public static function from(ServiceResult $result, bool $private = false): JsonResponse
    {
        $response = response()->json($result->toArray(), $result->statuscode);

        if ($private) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
