<?php

namespace App\Jobs;

use App\Services\System\RetentionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunRetentionCleanup implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 50;
    public bool $failOnTimeout = true;
    public int $uniqueFor = 86400;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function uniqueId(): string
    {
        return 'scheduled-retention-cleanup';
    }

    public function handle(RetentionService $retention): void
    {
        // Re-read current policies at execution time. Disabled or not-due categories are skipped.
        // The service holds a shared lock and enforces the per-category batch limits.
        $retention->run();
    }
}
