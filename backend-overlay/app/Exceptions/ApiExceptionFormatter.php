<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Normalizes API failures without exposing PHP traces or server secrets. */
final class ApiExceptionFormatter
{
    public static function render(Throwable $error, Request $request): ?JsonResponse
    {
        if (! $request->is('api/v2/*')) {
            return null;
        }

        $status = match (true) {
            $error instanceof ValidationException => 422,
            $error instanceof AuthenticationException => 401,
            $error instanceof AuthorizationException => 403,
            $error instanceof ModelNotFoundException => 404,
            $error instanceof HttpExceptionInterface => $error->getStatusCode(),
            default => 500,
        };

        $message = match (true) {
            $error instanceof ValidationException => 'The supplied information is invalid.',
            $error instanceof AuthenticationException => 'Authentication is required.',
            $error instanceof AuthorizationException => 'You do not have permission to perform this action.',
            $error instanceof HttpExceptionInterface && $error->getMessage() !== '' && $status < 500
                => $error->getMessage(),
            $status === 404 => 'The requested resource was not found.',
            $status === 403 => 'You do not have permission to perform this action.',
            $status === 429 => 'Too many requests. Please try again later.',
            $status >= 500 => 'An internal server error occurred.',
            default => Response::$statusTexts[$status] ?? 'Request failed.',
        };

        $payload = [
            'success' => false,
            'statuscode' => $status,
            'message' => $message,
        ];

        if ($status === 503 && app()->isDownForMaintenance()) {
            $payload['code'] = 'APPLICATION_MAINTENANCE';
            $payload['message'] = 'The application is temporarily unavailable for maintenance.';
        }

        if ($error instanceof ValidationException) {
            $payload['errors'] = $error->errors();
        }

        // Correlate incidents via a generated request id, never raw stack traces.
        if ($status >= 500 && $request->attributes->has('request_id')) {
            $payload['request_id'] = $request->attributes->get('request_id');
        }

        $response = response()->json($payload, $status)->header('Cache-Control', 'no-store');

        if ($error instanceof HttpExceptionInterface) {
            foreach ($error->getHeaders() as $header => $value) {
                $response->headers->set($header, $value);
            }
        }

        return $response;
    }
}
