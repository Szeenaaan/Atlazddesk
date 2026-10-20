<?php

namespace App\Http\Middleware;

use App\Services\System\{RequestTraceContext, RequestTelemetryService};
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::uuid();
        $start = hrtime(true);
        $request->attributes->set('request_id', $id);
        $incoming = (string) $request->header('traceparent', '');
        $traceId = preg_match('/^[\da-f]{2}-([\da-f]{32})-[\da-f]{16}-[\da-f]{2}$/', $incoming, $match) ? $match[1] : bin2hex(random_bytes(16));
        $parentSpanId = preg_match('/^[\da-f]{2}-[\da-f]{32}-([\da-f]{16})-[\da-f]{2}$/', $incoming, $parent) ? $parent[1] : null;
        $spanId = bin2hex(random_bytes(8));
        $traceContext = new RequestTraceContext();
        $request->attributes->set('amc.trace_context', $traceContext);
        Log::withContext(['request_id' => $id, 'trace_id' => $traceId]);
        $status = 500;
        try {
            $response = $next($request);
            $status = $response->getStatusCode();
            $response->headers->set('X-Request-ID', $id);
            $response->headers->set('X-Trace-ID', $traceId);
            return $response;
        } finally {
            $elapsed = round((hrtime(true) - $start) / 1_000_000, 2);
            $profile = $traceContext->finish();
            app(RequestTelemetryService::class)->record($request, [
                'request_id' => $id, 'trace_id' => $traceId, 'span_id' => $spanId,
                'parent_span_id' => $parentSpanId, 'status_code' => $status,
                'duration_ms' => $elapsed, 'db_duration_ms' => $profile['db_duration_ms'],
                'db_query_count' => $profile['db_query_count'],
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            ]);
            Log::info('amc.http_request', [
                'method' => $request->method(),
                'route' => $request->route()?->uri() ?? 'unmatched',
                'status' => $status,
                'user_id' => $request->user()?->id,
                'duration_ms' => $elapsed,
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            ]);
            if ($elapsed >= config('amc.slow_request_ms', 1000)) {
                Log::warning('amc.slow_request', ['duration_ms' => $elapsed]);
            }
        }
    }
}
