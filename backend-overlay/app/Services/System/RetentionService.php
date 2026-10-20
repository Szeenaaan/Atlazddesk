<?php

namespace App\Services\System;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Only allowlisted, non-business-history housekeeping; NEVER delete ticket, asset or audit history. */
final class RetentionService
{
    public const CATEGORIES = [
        'request_traces' => ['scope' => 'technical', 'label' => 'Request traces', 'minimum_days' => 7, 'default_days' => 30],
        'resolved_incidents' => ['scope' => 'technical', 'label' => 'Resolved incidents', 'minimum_days' => 30, 'default_days' => 90],
        'failed_jobs' => ['scope' => 'technical', 'label' => 'Failed queue jobs', 'minimum_days' => 30, 'default_days' => 90],
        'rotated_logs' => ['scope' => 'technical', 'label' => 'Rotated technical log files', 'minimum_days' => 30, 'default_days' => 90],
        'read_notifications' => ['scope' => 'business', 'label' => 'Read user notifications', 'minimum_days' => 30, 'default_days' => 90],
    ];

    public function policies(string $scope): array
    {
        $stored = DB::table('amc_retention_policies')->where('scope', $scope)->get()->keyBy('category');
        $result = [];
        foreach (self::CATEGORIES as $category => $definition) {
            if ($definition['scope'] !== $scope) continue;
            $record = $stored->get($category);
            $row = [
                'category' => $category,
                'label' => $definition['label'],
                'minimum_days' => $definition['minimum_days'],
                'enabled' => (bool) ($record->enabled ?? false),
                'retention_days' => (int) ($record->retention_days ?? $definition['default_days']),
                'run_every_days' => (int) ($record->run_every_days ?? 1),
                'batch_size' => (int) ($record->batch_size ?? 100),
                'max_batches' => (int) ($record->max_batches ?? 2),
                'last_run_at' => $record->last_run_at ?? null,
                'last_deleted_count' => (int) ($record->last_deleted_count ?? 0),
            ];
            // Counts are aggregate only; no customer, job or log content leaves this API.
            $row['eligible_count'] = $this->eligibleCount($category, CarbonImmutable::now('UTC')->subDays($row['retention_days']));
            $result[] = $row;
        }
        return $result;
    }

    public function save(string $scope, array $input, int $actorId): array
    {
        $allowed = array_keys(array_filter(self::CATEGORIES, fn ($def) => $def['scope'] === $scope));
        $received = array_column($input, 'category');
        if (count($received) !== count($allowed) || count(array_unique($received)) !== count($allowed)
            || array_diff($allowed, $received) || array_diff($received, $allowed)) {
            throw new RuntimeException('Retention settings must include exactly the categories authorized for this role.');
        }
        // Atomic configuration update; never reset last_run_at on a settings change.
        DB::transaction(function () use ($scope, $input, $actorId): void {
            foreach ($input as $row) {
                $category = $row['category'];
                if (self::CATEGORIES[$category]['scope'] !== $scope) {
                    throw new RuntimeException('Retention category is outside the current administrator scope.');
                }
                if ($row['retention_days'] < self::CATEGORIES[$category]['minimum_days'] || ($category === 'rotated_logs' && $row['retention_days'] > 365)) {
                    throw new RuntimeException('Retention period is below the minimum for '.$category.'.');
                }
                DB::table('amc_retention_policies')->updateOrInsert(
                    ['category' => $category],
                    [
                        'scope' => $scope,
                        'enabled' => (bool) $row['enabled'],
                        'retention_days' => (int) $row['retention_days'],
                        'run_every_days' => (int) $row['run_every_days'],
                        'batch_size' => (int) $row['batch_size'],
                        'max_batches' => (int) $row['max_batches'],
                        'updated_by' => $actorId,
                        'updated_at' => now(),
                    ]
                );
            }
        });
        return $this->policies($scope);
    }

    /** Scheduler invokes daily. Settings decide if each category is due. */
    public function run(bool $dryRun = false): array
    {
        if ($dryRun) return $this->runUnlocked(true);
        $lock = Cache::lock('amc:retention:v1011', 600);
        if (! $lock->get()) return ['locked' => true, 'categories' => []];
        try { return $this->runUnlocked(false); }
        finally { $lock->release(); }
    }

    private function runUnlocked(bool $dryRun): array
    {
        $now = CarbonImmutable::now('UTC');
        $result = [];
        foreach (DB::table('amc_retention_policies')->where('enabled', true)->orderBy('category')->get() as $policy) {
            if (! isset(self::CATEGORIES[$policy->category])) continue;
            $definition = self::CATEGORIES[$policy->category];
            if ($definition['scope'] !== $policy->scope || (int) $policy->retention_days < $definition['minimum_days'] || (int) $policy->retention_days > ($policy->category === 'rotated_logs' ? 365 : 3650)) continue;
            if ((int) $policy->run_every_days < 1 || (int) $policy->run_every_days > 30 || (int) $policy->batch_size < 25
                || (int) $policy->batch_size > 1000 || (int) $policy->max_batches < 1 || (int) $policy->max_batches > 20) continue;
            $last = $policy->last_run_at ? CarbonImmutable::parse($policy->last_run_at, 'UTC') : null;
            if ($last && $last->addDays((int) $policy->run_every_days)->greaterThan($now)) continue;
            $cutoff = $now->subDays((int) $policy->retention_days);
            $count = $dryRun ? $this->eligibleCount($policy->category, $cutoff)
                : $this->purgeCategory($policy->category, $cutoff, (int) $policy->batch_size, (int) $policy->max_batches);
            $result[$policy->category] = $count;
            if (! $dryRun) {
                DB::table('amc_retention_policies')->where('id', $policy->id)->update([
                    'last_run_at' => $now, 'last_deleted_count' => $count, 'updated_at' => now(),
                ]);
                // Never include deleted content, exception text, file names, or job payloads in logs.
                DB::table('activity_logs')->insert([
                    'user_id' => null, 'customer_id' => null,
                    'event' => $policy->scope === 'technical' ? 'system.retention.completed' : 'contractor.retention.completed',
                    'properties' => json_encode(['category' => $policy->category, 'deleted_count' => $count]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        return ['dry_run' => $dryRun, 'categories' => $result];
    }

    private function query(string $category, CarbonImmutable $cutoff)
    {
        return match ($category) {
            'request_traces' => DB::table('amc_request_traces')->where('created_at', '<', $cutoff),
            'resolved_incidents' => DB::table('amc_incidents')->whereNotNull('resolved_at')
                ->where('resolved_at', '<', $cutoff)->where('last_seen_at', '<', $cutoff),
            'failed_jobs' => DB::table('failed_jobs')->where('failed_at', '<', $cutoff),
            'read_notifications' => DB::table('amc_user_notifications')->whereNotNull('read_at')
                ->where('read_at', '<', $cutoff),
            default => throw new RuntimeException('Unsupported retention category.'),
        };
    }

    private function eligibleCount(string $category, CarbonImmutable $cutoff): int
    {
        return $category === 'rotated_logs'
            ? count($this->safeRotatedLogs($cutoff))
            : $this->query($category, $cutoff)->count();
    }

    private function purgeCategory(string $category, CarbonImmutable $cutoff, int $size, int $batches): int
    {
        $total = 0;
        if ($category === 'rotated_logs') {
            // Delete only whitelisted rotated technical logs inside storage/logs, never active or symlinked files.
            foreach (array_slice($this->safeRotatedLogs($cutoff), 0, $size * $batches) as $path) {
                if (is_file($path) && ! is_link($path) && @unlink($path)) $total++;
            }
            return $total;
        }
        for ($i = 0; $i < $batches; $i++) {
            $ids = $this->query($category, $cutoff)->orderBy('id')->limit($size)->pluck('id')->all();
            if ($ids === []) break;
            // Re-apply all eligibility predicates to prevent deleting an event updated since selection.
            $total += $this->query($category, $cutoff)->whereIn('id', $ids)->delete();
            if (count($ids) < $size) break;
        }
        return $total;
    }

    private function safeRotatedLogs(CarbonImmutable $cutoff): array
    {
        $directory = storage_path('logs');
        if (! is_dir($directory) || is_link($directory)) return [];
        $safe = [];
        $prefix = '/^amc-(?:application|security|jobs|mail)-(\d{4}-\d{2}-\d{2})\.log$/D';
        foreach (new \DirectoryIterator($directory) as $file) {
            if (! $file->isFile() || $file->isLink() || ! preg_match($prefix, $file->getFilename(), $matches)) continue;
            try { $date = CarbonImmutable::createFromFormat('!Y-m-d', $matches[1], 'UTC'); }
            catch (\Throwable) { continue; }
            if (! $date || $date->format('Y-m-d') !== $matches[1] || $date->greaterThanOrEqualTo($cutoff->startOfDay())) continue;
            $safe[] = $file->getPathname();
        }
        sort($safe, SORT_STRING);
        return $safe;
    }
}
