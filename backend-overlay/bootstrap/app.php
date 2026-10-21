<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\{AmcSecurityHeaders, RequestContext, EnsureActiveAccount};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->preventRequestsDuringMaintenance(except: ['api/maintenance-status']);
        $middleware->statefulApi();
        $middleware->append(RequestContext::class);
        $middleware->append(AmcSecurityHeaders::class);
        $middleware->alias(['amc.active' => EnsureActiveAccount::class,
            'amc.mfa' => \App\Http\Middleware\EnsureMfaCompleted::class,
            'amc.boundary' => \App\Http\Middleware\EnsureTechnicalBoundary::class,
            'amc.role' => \App\Http\Middleware\EnsureRecognizedAmcRole::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (\Throwable $error): void {
            $request = request();
            \App\Support\IncidentRecorder::record(
                $error, 'exception',
                $request->attributes->get('request_id'),
                $request->route()?->uri(),
                $request->user()?->id
            );
        });
        $exceptions->render(function (\Throwable $error, \Illuminate\Http\Request $request) {
            if ($error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                && $error->getStatusCode() === 503 && app()->isDownForMaintenance()
                && $request->isMethod('GET') && ! $request->expectsJson()
                && str_contains((string) $request->header('Accept'), 'text/html')
                && ! $request->is('maintenance')) {
                $origin = rtrim((string) config('amc.frontend_url', ''), '/');
                return redirect()->to($origin.'/maintenance')->header('Cache-Control', 'no-store');
            }
            return \App\Exceptions\ApiExceptionFormatter::render($error, $request);
        });
    })->create();
