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
        if (! $this->option('dry-run')) {
            $connection = config('queue.default');
            $driver = config("queue.connections.{$connection}.driver");
            if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
                $this->error('Automatic cleanup requires a persistent queue connection and a worker listening on the maintenance queue. Ask the server administrator to configure them.');
                return self::FAILURE;
            }
            \App\Jobs\RunRetentionCleanup::dispatch();
            $this->info('Background cleanup requested. Check retention settings for the last completed run and removal count.');
            return self::SUCCESS;
        }
        $result = $service->run(true);
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
