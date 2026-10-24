<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Technical owner does not gain the contractor's commercial permissions. */
final class EnsureTechnicalBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role === 'super_admin') {
            $allowed = [
                'api/v2/auth/me', 'api/v2/auth/profile', 'api/v2/auth/logout', 'api/v2/auth/activity', 'api/v2/auth/mfa/status',
                'api/v2/auth/mfa/email/enroll/send', 'api/v2/auth/mfa/email/enroll/confirm',
                'api/v2/auth/mfa/email/disable/send', 'api/v2/auth/mfa/disable',
                'api/v2/auth/mfa/recovery/regenerate',
                'api/v2/auth/mfa/totp/replace/start',
                'api/v2/auth/mfa/totp/replace/confirm',
                'api/v2/auth/mfa/totp/replace/cancel',
                'api/v2/installation/timezone',
                'api/v2/system/security', 'api/v2/system/security/*',
                'api/v2/health', 'api/v2/admin/metrics', 'api/v2/admin/audit',
                'api/v2/admin/incidents', 'api/v2/admin/incidents/*',
                'api/v2/system/support/*', 'api/v2/system/admins', 'api/v2/system/admins/*',
            ];
            abort_unless($request->is($allowed), 403, 'Only Contractor Admin can perform this action.');
        }
        return $next($request);
    }
}
