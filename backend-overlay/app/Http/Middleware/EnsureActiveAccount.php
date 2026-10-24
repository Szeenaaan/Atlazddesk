<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $user?->refresh();
        abort_unless($user && $user->status === 'active', 403, 'This account is inactive.');
        return $next($request);
    }
}
