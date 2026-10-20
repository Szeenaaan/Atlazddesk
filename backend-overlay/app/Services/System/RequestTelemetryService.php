<?php

namespace App\Services\System;

use Illuminate\Http\Request;
use App\Support\AmcAudit;
use Illuminate\Support\Facades\{Cache, DB};
use Throwable;

/** Privacy-minimized, bounded HTTP tracing and lightweight DB-duration profiling. */
final class RequestTelemetryService
{
    private const SETTINGS_KEY = 'amc:telemetry:settings:v1';

    public function settings(): array
    {
        $defaults = [
            'enabled' => (bool) config('amc_telemetry.enabled', false),
            'sample_percent' => min(100, max(0, (int) config('amc_telemetry.sample_percent', 10))),
            'retention_days' => min(30, max(1, (int) config('amc_telemetry.retention_days', 7))),
            'slow_request_ms' => min(60000, max(100, (int) config('amc_telemetry.slow_request_ms', 1000))),
        ];

        try {
            return Cache::remember(self::SETTINGS_KEY, 60, static function () use ($defaults): array {
                $row = DB::table('amc_telemetry_settings')->where('id', 1)->first();
                if ($row === null) {
                    return $defaults;
                }

                return [
                    'enabled' => (bool) $row->enabled,
                    'sample_percent' => (int) $row->sample_percent,
                    'retention_days' => (int) $row->retention_days,
                    'slow_request_ms' => (int) $row->slow_request_ms,
                ];
            });
        } catch (Throwable) {
            // Monitoring must never prevent normal business requests from completing.
            return $defaults;
        }
    }

    public function clearSettingsCache(): void
    {
        Cache::forget(self::SETTINGS_KEY);
    }

    public function record(Request $request, array $measurement): void
    {
        // Do not instrument health probes and observability queries themselves.
        if (! $request->is('api/*') || $request->is('api/v2/system/support/*', 'api/v2/health/*', 'api/v2/health')) {
            return;
        }

        try {
            $policy = $this->settings();
            if (! $policy['enabled']) {
                return;
            }

            $status = (int) $measurement['status_code'];
            $slow = (float) $measurement['duration_ms'] >= $policy['slow_request_ms'];
            $keep = $status >= 500 || $slow || random_int(1, 100) <= $policy['sample_percent'];
            if (! $keep) {
                return;
            }

            // Laravel's parameterized route URI cannot contain concrete customer IDs or query strings.
            $route = (string) ($request->route()?->uri() ?? 'api/unmatched');
            DB::table('amc_request_traces')->insert([
                'request_id' => $measurement['request_id'],
                'trace_id' => $measurement['trace_id'],
                'span_id' => $measurement['span_id'],
                'parent_span_id' => $measurement['parent_span_id'],
                'route' => mb_substr($route, 0, 190),
                'method' => mb_substr($request->method(), 0, 8),
                'status_code' => $status,
                'duration_ms' => $measurement['duration_ms'],
                'db_duration_ms' => $measurement['db_duration_ms'],
                'db_query_count' => $measurement['db_query_count'],
                'peak_memory_mb' => $measurement['peak_memory_mb'],
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Never let missing telemetry tables, disk/database failures, or cache outages break login/tickets.
        }
    }

    public function performance(int $hours = 24): array
    {
        $hours = min(168, max(1, $hours));
        $cutoff = now()->subHours($hours);
        // Clearly described as a bounded set of observations, NOT a complete traffic count.
        $rows = DB::table('amc_request_traces')->where('created_at', '>=', $cutoff)
            ->orderByDesc('id')->limit(2000)
            ->get(['request_id', 'trace_id', 'span_id', 'parent_span_id', 'route', 'method', 'status_code',
                'duration_ms', 'db_duration_ms', 'db_query_count', 'peak_memory_mb', 'created_at']);

        $grouped = $rows->groupBy(static fn ($row) => $row->method.' '.$row->route);
        $routes = $grouped->map(function ($group, $route): array {
            $latencies = $group->pluck('duration_ms')->map(static fn ($v) => (float) $v)->sort()->values();
            $index = max(0, (int) ceil($latencies->count() * 0.95) - 1);

            return [
                'route' => $route,
                'samples' => $group->count(),
                'avg_ms' => round($latencies->avg() ?? 0, 2),
                'p95_ms' => round((float) $latencies->get($index, 0), 2),
                'avg_db_ms' => round($group->avg('db_duration_ms') ?? 0, 2),
                'avg_queries' => round($group->avg('db_query_count') ?? 0, 1),
                'server_errors' => $group->where('status_code', '>=', 500)->count(),
            ];
        })->sortByDesc('samples')->take(20)->values()->all();

        return [
            'window_hours' => $hours,
            'sampled_requests' => $rows->count(),
            'server_error_observations' => $rows->where('status_code', '>=', 500)->count(),
            'average_latency_ms' => round($rows->avg('duration_ms') ?? 0, 2),
            'average_db_ms' => round($rows->avg('db_duration_ms') ?? 0, 2),
            'slowest_observed_ms' => round($rows->max('duration_ms') ?? 0, 2),
            'routes' => $routes,
            'recent_traces' => $rows->take(30)->map(static fn ($row) => [
                'request_id' => $row->request_id,
                'trace_id' => $row->trace_id,
                'route' => $row->route,
                'method' => $row->method,
                'status_code' => (int) $row->status_code,
                'duration_ms' => (float) $row->duration_ms,
                'db_duration_ms' => (float) $row->db_duration_ms,
                'db_query_count' => (int) $row->db_query_count,
                'created_at' => (string) $row->created_at,
            ])->values()->all(),
            'settings' => $this->settings(),
            'scope' => 'Up to 2,000 sampled HTTP observations in the selected window; not total traffic or full distributed traces.',
            'limitations' => [
                'Request and database-query timing only; no PHP function profiler or SQL plans.',
                'Errors and slow requests are oversampled; do not interpret observed error ratios as system-wide error rates.',
                'Only generic route templates are saved; no request bodies, SQL text, client IDs, IPs or credentials.',
                'Database outages can prevent collecting telemetry; use independent uptime monitoring.',
            ],
        ];
    }


    /** Detail for one correlation ID. SQL statements and customer payloads are never retained. */
    public function trace(string $traceId): array
    {
        return DB::table('amc_request_traces')->where('trace_id', $traceId)
            ->orderBy('created_at')->limit(100)
            ->get(['request_id', 'trace_id', 'span_id', 'parent_span_id', 'route', 'method',
                'status_code', 'duration_ms', 'db_duration_ms', 'db_query_count', 'peak_memory_mb', 'created_at'])
            ->map(static fn ($record): array => (array) $record)
            ->all();
    }

    /** Persist Super Admin technical preferences and an audit event atomically. */
    public function updateSettings(Request $request, array $input): array
    {
        DB::transaction(static function () use ($request, $input): void {
            DB::table('amc_telemetry_settings')->updateOrInsert(['id' => 1], [
                'enabled' => (bool) $input['enabled'],
                'sample_percent' => (int) $input['sample_percent'],
                'retention_days' => (int) $input['retention_days'],
                'slow_request_ms' => (int) $input['slow_request_ms'],
                'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ]);
            AmcAudit::record($request, 'system.telemetry.settings_updated', $request->user(), [
                'changed_keys' => ['enabled', 'sample_percent', 'retention_days', 'slow_request_ms'],
            ]);
        });
        $this->clearSettingsCache();

        return $this->settings();
    }

    public function prune(): int
    {
        $settings = $this->settings();
        return DB::table('amc_request_traces')
            ->where('created_at', '<', now()->subDays($settings['retention_days']))->delete();
    }
}
