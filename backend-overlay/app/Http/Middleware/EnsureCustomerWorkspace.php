<?php

namespace App\Http\Middleware;

use App\Services\Auth\CustomerWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCustomerWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->hasSession() ? $request->session()->get(CustomerWorkspace::KEY) : null;
        if ($id === null) return $next($request);
        // MFA, active-account checks and single-session enforcement already ran for the real actor.
        abort_unless($request->user()?->role === 'admin', 403);
        if ($request->is('api/v2/customer-workspace', 'api/v2/auth/logout', 'api/v2/auth/activity')) return $next($request);
        // Delegation requires the original cookie login and its live session claim on every request.
        $actor = $request->user();
        abort_unless($request->hasSession()
            && (int) $request->session()->get(\Illuminate\Support\Facades\Auth::guard('web')->getName()) === (int) $actor->id
            && (int) $request->session()->get(CustomerWorkspace::KEY.'_actor') === (int) $actor->id,
            403);
        app(\App\Services\Auth\AccountSession::class)->validateAndTouch($request, $actor, false);
        // Never expose another person's password, MFA, recovery or profile settings through delegation.
        abort_if($request->is('api/v2/auth/*') && ! $request->is('api/v2/auth/me'), 403,
            'Return to your own workspace to manage account security.');
        $target = app(CustomerWorkspace::class)->target((int) $id);
        $request->attributes->set('amc_workspace_actor', $request->user());
        $request->setUserResolver(fn ($guard = null) => $target);
        return $next($request);
    }
}
