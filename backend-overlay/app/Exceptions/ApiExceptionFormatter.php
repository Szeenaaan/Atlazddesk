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
            $error instanceof ValidationException => 'Check the highlighted fields and submit again.',
            $error instanceof AuthenticationException => 'Sign in to access this page or complete this action.',
            $error instanceof AuthorizationException => 'Your account cannot perform this action. Contact your administrator if you need access.',
            $error instanceof HttpExceptionInterface && $error->getMessage() !== '' && $status < 500
                => $error->getMessage(),
            $status === 404 => 'This record is unavailable or your account cannot access it.',
            $status === 401 => 'Sign in to access this page or complete this action.',
            $status === 403 => 'Your account cannot perform this action. Contact your administrator if you need access.',
            $status === 422 => 'Check the highlighted fields and submit again.',
            $status === 429 => 'Too many requests. Wait a moment before trying again.',
            $status === 503 => 'This service is temporarily unavailable. Try again shortly. If the problem continues, contact your administrator.',
            $status >= 500 => 'We could not complete this request. Try again. If the problem continues, contact your administrator.',
            default => Response::$statusTexts[$status] ?? 'Request failed.',
        };

        $payload = [
            'success' => false,
            'statuscode' => $status,
            'message' => $message,
        ];

        if ($status >= 500 && $request->is('api/v2/auth/mfa/email/*')) {
            $payload['message'] = 'We could not send your verification code. Try again. If the problem continues, contact your administrator.';
        } elseif ($status >= 500 && $request->is('api/v2/admin/mail/*')) {
            $payload['message'] = $request->isMethod('GET')
                ? 'Email settings could not be loaded. Try again or contact your server administrator.'
                : 'The email settings request could not be completed. Try again or contact your server administrator.';
        }

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
