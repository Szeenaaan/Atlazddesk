<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

final class AmcAudit
{
    public static function record(Request $request, string $event, object $subject, array $properties = []): void
    {
        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'customer_id' => $subject->customer_id ?? $request->user()?->customer_id,
            'event' => $event,
            'subject_type' => $subject::class,
            'subject_id' => $subject->id,
            'request_id' => $request->attributes->get('request_id'),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'properties' => $properties,
        ]);
        // AMC-SUPPORT-LOGS-V10.6: emit only safe event identifiers, never audit properties.
        if (str_starts_with($event, 'system.') || str_starts_with($event, 'auth.')
            || str_starts_with($event, 'mfa.') || str_starts_with($event, 'security.')) {
            $channel = str_contains($event, 'mail') || str_contains($event, 'email')
                ? 'amc_mail' : 'amc_security';
            // Mail provider success is an informational audit, not proof of inbox delivery.
            try {
                $level = str_contains($event, 'failed') || str_contains($event, 'denied') ? 'warning' : 'info';
                \Illuminate\Support\Facades\Log::channel($channel)->log($level, 'technical.audit', [
                    'event' => preg_replace('/[^A-Za-z0-9_.-]/', '', $event),
                    'actor_id' => $request->user()?->id,
                ]);
            } catch (\Throwable) { /* Audit record remains authoritative. */ }
        }
    }
}
