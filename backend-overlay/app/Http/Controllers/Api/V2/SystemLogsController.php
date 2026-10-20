<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Support\SafeDiagnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Read-only and deliberately sanitized per-installation diagnostics.
 * NEVER return Laravel log files, exception messages, job payloads, SQL or request bodies.
 */
final class SystemLogsController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->role === 'super_admin', 403);

        // AMC-DIAGNOSTIC-SEARCH-V10.9: normalize and validate bounded search input.
        if (is_string($request->query('q'))) {
            $request->merge(['q' => trim($request->query('q'))]);
        }
        $filters = $request->validate([
            'category' => ['sometimes', Rule::in(['all', 'application', 'security', 'jobs', 'mail', 'audit'])],
            'level' => ['sometimes', Rule::in(['all', 'info', 'warning', 'error', 'critical'])],
            'q' => ['sometimes', 'string', 'min:2', 'max:100'],
        ]);

        $search = (string) ($filters['q'] ?? '');
        $entries = collect();
        $since = now()->subDays(30);

        // Exceptions: expose class, count and correlation ID, not original message or trace.
        $incidents = DB::table('amc_incidents')->where('last_seen_at', '>=', $since);
        SafeDiagnosticSearch::apply($incidents, ['exception_class', 'request_id'], $search);
        foreach ($incidents->orderByDesc('last_seen_at')->limit(100)
            ->get(['id', 'severity', 'exception_class', 'kind', 'request_id', 'occurrences', 'last_seen_at']) as $incident) {
            $class = (string) ($incident->exception_class ?? 'Application exception');
            $class = preg_replace('/[^A-Za-z0-9_]/', '', $class) ?: 'Application exception';
            $level = in_array($incident->severity, ['warning', 'error', 'critical'], true)
                ? $incident->severity : 'error';
            $entries->push([
                'id' => 'incident:'.$incident->id,
                'category' => 'application',
                'level' => $level,
                'message' => $class.' ('.(int) $incident->occurrences.' occurrence(s))',
                'request_id' => $this->safeRequestId($incident->request_id),
                'occurred_at' => (string) $incident->last_seen_at,
            ]);
        }

        // Queue failure metadata only. No serialized jobs, exception text or payload.
        $jobs = DB::table('failed_jobs')->where('failed_at', '>=', $since);
        // The log viewer exposes job UUIDs only, never private queue names or job payloads.
        SafeDiagnosticSearch::apply($jobs, ['uuid'], $search);
        foreach ($jobs->orderByDesc('failed_at')->limit(100)->get(['uuid', 'failed_at']) as $job) {
            $entries->push([
                'id' => 'job:'.$job->uuid,
                'category' => 'jobs',
                'level' => 'error',
                'message' => 'Background job failed',
                'request_id' => null,
                'occurred_at' => (string) $job->failed_at,
            ]);
        }

        // Technical audit *names* only. Business customer/ticket activity is excluded.
        $auditQuery = DB::table('activity_logs')->where('created_at', '>=', $since)
            ->where(static function ($query): void {
                foreach (['system.', 'auth.', 'mfa.', 'security.', 'user.mfa.', 'user.login.'] as $prefix) {
                    $query->orWhere('event', 'like', $prefix.'%');
                }
            });
        SafeDiagnosticSearch::apply($auditQuery, ['event', 'request_id'], $search);
        $audit = $auditQuery->orderByDesc('created_at')->limit(100)
            ->get(['id', 'created_at', 'event', 'request_id']);
        foreach ($audit as $item) {
            $event = (string) $item->event;
            // Event codes are system-defined but never send arbitrary properties to the browser.
            $eventCode = preg_replace('/[^a-zA-Z0-9_.-]/', '', $event) ?: 'system.event';
            $category = str_contains($eventCode, 'mail') || str_contains($eventCode, 'email')
                ? 'mail' : (str_contains($eventCode, 'mfa') || str_contains($eventCode, 'auth')
                    || str_contains($eventCode, 'security') || str_contains($eventCode, 'login')
                    || str_contains($eventCode, 'recovery') ? 'security' : 'audit');
            $level = str_contains($eventCode, 'failed') || str_contains($eventCode, 'denied')
                || str_contains($eventCode, 'locked') ? 'warning' : 'info';
            $entries->push([
                'id' => 'audit:'.$item->id,
                'category' => $category,
                'level' => $level,
                'message' => $eventCode,
                'request_id' => $this->safeRequestId($item->request_id),
                'occurred_at' => (string) $item->created_at,
            ]);
        }

        $category = $filters['category'] ?? 'all';
        $level = $filters['level'] ?? 'all';
        $results = $entries
            ->filter(static fn (array $row): bool => ($category === 'all' || $row['category'] === $category)
                && ($level === 'all' || $row['level'] === $level))
            ->sortByDesc('occurred_at')->take(100)->values();

        return response()->json(['data' => $results, 'window_days' => 30, 'limit' => 100])
            ->header('Cache-Control', 'no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    private function safeRequestId(?string $id): ?string
    {
        return is_string($id) && preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $id) ? $id : null;
    }
}
