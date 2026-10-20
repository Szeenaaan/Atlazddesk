<?php

namespace App\Http\Middleware;

use App\Support\AmcAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureRecognizedAmcRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && in_array($user->role, AmcAccess::ROLES, true), 403,
            'Your account has a legacy or unrecognized role. Contact Super Admin for review.');
        return $next($request);
    }
}
