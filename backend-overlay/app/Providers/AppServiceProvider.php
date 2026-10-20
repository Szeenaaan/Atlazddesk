<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Log, RateLimiter};
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // AMC-SUPPORT-LOGS-V10.6: never log queue payloads or exception messages.
        \Illuminate\Support\Facades\Queue::failing(static function (\Illuminate\Queue\Events\JobFailed $event): void {
            try {
                \Illuminate\Support\Facades\Log::channel('amc_jobs')->error('queue.job_failed', [
                    'connection' => $event->connectionName,
                ]);
            } catch (\Throwable) { /* Do not interfere with failed-job handling. */ }
        });
        \Illuminate\Support\Facades\Queue::failing(function (\Illuminate\Queue\Events\JobFailed $event): void {
            \App\Support\IncidentRecorder::record($event->exception, 'failed_job');
        });
        RateLimiter::for('amc-api', fn (Request $request) => Limit::perMinute(120)
            ->by('api:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('amc-write', fn (Request $request) => Limit::perMinute(30)
            ->by('write:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('amc-login', fn (Request $request) => [
            Limit::perMinute(5)->by('login-account:'.hash('sha256', mb_strtolower((string)$request->input('email')).'|'.$request->ip())),
            Limit::perMinute(30)->by('login-ip:'.$request->ip()),
        ]);
        RateLimiter::for('amc-mfa', fn (Request $request) => [
            Limit::perMinute(15)->by('mfa-ip:'.$request->ip()),
            Limit::perMinutes(5, 25)->by('mfa-session:'.hash('sha256', $request->session()->getId())),
        ]);
        RateLimiter::for('amc-support-mail', fn (Request $request) => [
            Limit::perMinutes(10, 2)->by('support-mail-user:'.($request->user()?->id ?? $request->ip())),
            Limit::perHour(5)->by('support-mail-ip:'.$request->ip()),
        ]);
        RateLimiter::for('amc-health', fn (Request $request) => Limit::perMinute(30)->by('health:'.$request->ip()));

        DB::listen(function (QueryExecuted $query) {
            if (app()->bound('request')) {
                $context = request()->attributes->get('amc.trace_context');
                if ($context instanceof \App\Services\System\RequestTraceContext) {
                    $context->query((float) $query->time);
                }
            }
            if ($query->time >= config('amc.slow_query_ms', 500)) {
                // Never log raw SQL bindings: they may include private customer data.
                Log::warning('amc.slow_query', ['duration_ms'=>$query->time,'connection'=>$query->connectionName]);
            }
        });
    }
}
