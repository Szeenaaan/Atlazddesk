<?php

use App\Jobs\{CheckSlaDeadlines, CheckAmcExpiry, GenerateMaintenanceVisits};
use Illuminate\Support\Facades\Schedule;

// AMC-RETENTION-V10.11: opt-in cleanup; enabled policies and cadence checked in the service.
Schedule::command('amc:retention-cleanup')->dailyAt('03:30')->withoutOverlapping(60)->onOneServer();

Schedule::job(new CheckSlaDeadlines, 'maintenance')->everyFiveMinutes()
    ->withoutOverlapping(10)->onOneServer();
Schedule::job(new CheckAmcExpiry, 'maintenance')->dailyAt('08:00')
    ->withoutOverlapping(30)->onOneServer();

Schedule::job(new GenerateMaintenanceVisits, 'maintenance')->dailyAt('06:00')
    ->withoutOverlapping(30)->onOneServer();

// A healthy heartbeat requires the real scheduler to run every minute.
Schedule::call(static function (): void {
    \Illuminate\Support\Facades\Cache::put('amc:scheduler:heartbeat', now()->timestamp, now()->addMinutes(5));
})->everyMinute()->name('amc.scheduler.heartbeat');

// Daily pruning of privacy-minimized performance observations; no customer payloads.
Schedule::call(static function (): void {
    try {
        app(\App\Services\System\RequestTelemetryService::class)->prune();
    } catch (\Throwable) {
        \Illuminate\Support\Facades\Log::warning('amc.telemetry.pruning_unavailable');
    }
})->dailyAt('02:20')->name('amc.telemetry.prune')->withoutOverlapping(30);
