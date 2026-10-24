<?php

namespace App\Http\Middleware;

use App\Services\Auth\MfaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A prior non-MFA session does not satisfy MFA after enrollment or policy changes. */
final class EnsureMfaCompleted
{
    public function __construct(private readonly MfaService $mfa) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Recovery and administrative MFA resets can update the database while
        // this guard still holds an older User instance. Always check the
        // current database state before trusting MFA session verification.
        $user->refresh();

        $required = $this->mfa->requiredMethods($user);
        // API token and non-stateful requests may have no session store.
        // Treat missing session state as NO verified MFA factors (fail closed).
        $sessionMethods = $request->hasSession()
            ? (array) $request->session()->get('amc_mfa_verified_methods', [])
            : [];
        $sessionUser = $request->hasSession()
            ? (int) $request->session()->get('amc_mfa_verified_user_id', 0)
            : 0;

        $sessionVersion = $request->hasSession()
            ? (int) $request->session()->get('amc_mfa_verified_security_version', 0)
            : 0;
        $userVersion = (int) $user->mfa_security_version;

        if (($this->mfa->required($user) && $required === [])
            || (($required !== [] || $userVersion > 0) && (
                $sessionUser !== (int) $user->id
                || $sessionVersion !== $userVersion
                || array_diff($required, $sessionMethods) !== []
            ))) {
            return response()->json([
                'message' => 'Sign in again and complete every required MFA step.',
                'code' => 'MFA_REQUIRED',
            ], 403);
        }

        // Real cookie-authenticated logins carry the web guard's session key.
        // Session IDs may rotate during profile/MFA actions; the claim token survives.
        if ($request->hasSession() && $request->session()->has(\Illuminate\Support\Facades\Auth::guard('web')->getName())) {
            // Polling and ordinary API reads do not count as user interaction.
            try {
                app(\App\Services\Auth\AccountSession::class)->validateAndTouch($request, $user, false);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                if (in_array($error->getStatusCode(), [401, 409], true)) {
                    \Illuminate\Support\Facades\Auth::guard('web')->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                    abort(401, 'Your session has expired. Sign in again.');
                }
                throw $error;
            }
        }

        return $next($request);
    }
}
