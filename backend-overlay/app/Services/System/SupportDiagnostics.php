<?php

namespace App\Services\System;

use App\Support\InstallationSettings;
use App\Services\Mail\ContractorMailService;
use Illuminate\Support\Facades\{Cache, DB, Queue, Storage};
use Throwable;

/** Sanitized operational facts, never .env content, credentials, ticket payloads, or full log lines. */
final class SupportDiagnostics
{
    public function __construct(
        private readonly InstallationSettings $settings,
        private readonly ContractorMailService $mail,
    ) {}

    public function collect(): array
    {
        $preferences = $this->settings->all();
        $checks = [];
        $checks['database'] = $this->check(function (): array {
            DB::select('SELECT 1');
            return ['status' => 'healthy', 'detail' => 'Database query succeeded'];
        });
        $checks['cache'] = $this->check(function (): array {
            $key = 'amc:support:probe:'.bin2hex(random_bytes(8));
            try {
                Cache::put($key, 'ok', 20);
                if (Cache::get($key) !== 'ok') throw new \RuntimeException('Cache value mismatch');
            } finally {
                Cache::forget($key);
            }
            return ['status' => 'healthy', 'detail' => 'Cache read/write succeeded'];
        });
        $checks['storage'] = $this->check(function (): array {
            $path = 'amc-support-probe/'.bin2hex(random_bytes(8)).'.txt';
            try {
                if (! Storage::disk('local')->put($path, 'probe') || Storage::disk('local')->get($path) !== 'probe') {
                    throw new \RuntimeException('Storage probe failed');
                }
            } finally {
                Storage::disk('local')->delete($path);
            }
            return ['status' => 'healthy', 'detail' => 'Private local storage is writable'];
        });

        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        $percent = is_numeric($free) && is_numeric($total) && $total > 0 ? round(100 * $free / $total, 1) : null;
        $checks['disk'] = [
            'status' => $percent === null ? 'unknown' : ($percent < $preferences['disk_warning_percent'] ? 'warning' : 'healthy'),
            'free_percent' => $percent,
            'detail' => $percent === null ? 'Disk data unavailable' : 'Free disk space percentage',
        ];

        $queueName = config('queue.default');
        $checks['queue'] = $queueName === 'sync'
            ? ['status' => 'warning', 'detail' => 'Sync queue: background execution is not enabled', 'pending' => null]
            : $this->check(function () use ($preferences): array {
                // This indicates backlog only. Queue::size does not prove a worker process is alive.
                $depth = Queue::connection()->size('default') + Queue::connection()->size('notifications')
                    + Queue::connection()->size('maintenance');
                return ['status' => $depth >= $preferences['queue_warning_depth'] ? 'warning' : 'healthy',
                    'detail' => 'Queue backlog across default, notifications and maintenance; worker uptime not verified',
                    'pending' => $depth];
            });
        $checks['failed_jobs'] = $this->check(function (): array {
            $count = DB::table('failed_jobs')->count();
            return ['status' => $count > 0 ? 'warning' : 'healthy', 'count' => $count,
                'detail' => 'Failed jobs requiring investigation'];
        });
        $checks['scheduler'] = $this->check(function (): array {
            $heartbeat = Cache::get('amc:scheduler:heartbeat');
            $age = is_numeric($heartbeat) ? max(0, now()->timestamp - (int) $heartbeat) : null;
            return [
                'status' => $age === null ? 'unknown' : ($age <= 150 ? 'healthy' : 'warning'),
                'last_seen_seconds_ago' => $age,
                'detail' => $age === null ? 'No scheduler heartbeat recorded yet' : 'Last schedule:run heartbeat; not an OS health check',
            ];
        });

        $mailer = $this->mail->hasProvider(null) ? 'contractor_provider' : 'unconfigured';
        $checks['mail'] = [
            'status' => $mailer === 'contractor_provider' ? 'unknown' : 'warning',
            'detail' => $mailer === 'contractor_provider'
                ? 'Admin-configured mail provider available; delivery unverified'
                : 'No active default email provider configured by Contractor Admin',
        ];
        $checks['security'] = [
            'status' => config('app.debug') ? 'warning' : 'healthy',
            'detail' => config('app.debug') ? 'APP_DEBUG enabled; disable for production' : 'Application debug output disabled',
        ];

        $technicalReady = app(\App\Services\System\TechnicalSecurityService::class)->mailReady();
        $checks['technical_mail'] = [
            'status' => $technicalReady ? 'unknown' : 'warning',
            'detail' => $technicalReady ? 'Technical email provider configured; inbox delivery unverified'
                : 'Technical email provider not enabled',
        ];

        return [
            'generated_at' => now()->toIso8601String(),
            'installation' => $preferences['installation_label'],
            'checks' => $checks,
            'runtime' => [
                'laravel' => app()->version(), 'php' => PHP_VERSION,
                'environment' => app()->environment(), 'app_debug' => (bool) config('app.debug'),
                'https_request' => request()->isSecure(),
                'timezone' => config('app.timezone'),
                'queue_driver' => $queueName, 'cache_driver' => config('cache.default'),
                'session_driver' => config('session.driver'), 'mail_driver' => $mailer,
                'php_memory_limit' => ini_get('memory_limit'),
                'max_upload_size' => ini_get('upload_max_filesize'),
                'request_id' => request()->attributes->get('request_id'),
            ],
            'limitations' => [
                'Queue backlog does not prove worker availability.',
                'Mail driver configuration does not prove delivery.',
                'This app cannot diagnose or restart Nginx, PHP-FPM, database services, or a powered-off VPS.',
                'Verify backup restoration and external uptime alerts outside this console.',
            ],
        ];
    }

    private function check(callable $callback): array
    {
        try { return $callback(); }
        catch (Throwable) { return ['status' => 'unhealthy', 'detail' => 'Check failed; inspect server logs']; }
    }
}
