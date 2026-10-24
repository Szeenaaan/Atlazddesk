<?php

namespace App\Console\Commands;

use App\Services\System\RetentionService;
use Illuminate\Console\Command;

final class RunRetentionCleanup extends Command
{
    protected $signature = 'amc:retention-cleanup {--dry-run : Count due records without deleting}';
    protected $description = 'Run enabled, due SupportDesk retention policies in bounded batches';

    public function handle(RetentionService $service): int
    {
        $result = $service->run((bool) $this->option('dry-run'));
        if (! empty($result['locked'])) {
            $this->warn('Retention cleanup already running on this installation.');
            return self::SUCCESS;
        }
        foreach ($result['categories'] as $category => $count) {
            $this->line($category.': '.(int) $count.($this->option('dry-run') ? ' eligible' : ' deleted'));
        }
        if ($result['categories'] === []) $this->info('No enabled retention categories are due.');
        return self::SUCCESS;
    }
}
