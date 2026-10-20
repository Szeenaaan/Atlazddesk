<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMfaCompleted;
use App\Models\User;
use App\Services\System\RetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class RetentionV1011Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $database = getenv('DB_DATABASE') ?: ($_SERVER['DB_DATABASE'] ?? '');
        if (! str_ends_with((string) $database, '_test')) {
            throw new \RuntimeException('Refusing destructive tests: use a *_test database.');
        }
        parent::setUp();
        $this->withoutMiddleware(EnsureMfaCompleted::class);
    }

    private function account(string $role): User
    {
        return User::factory()->create([
            'role' => $role, 'status' => 'active', 'customer_id' => null,
            'password' => Hash::make('RetentionTestPassword#2026'),
        ]);
    }

    private function policies(string $scope): array
    {
        return collect(app(RetentionService::class)->policies($scope))->map(fn ($row) => [
            'category' => $row['category'], 'enabled' => $row['enabled'],
            'retention_days' => $row['retention_days'], 'run_every_days' => $row['run_every_days'],
            'batch_size' => $row['batch_size'], 'max_batches' => $row['max_batches'],
        ])->all();
    }

    private function trace(int $daysAgo): void
    {
        DB::table('amc_request_traces')->insert([
            'request_id' => (string) \Illuminate\Support\Str::uuid(),
            'trace_id' => str_repeat('a', 32), 'span_id' => str_repeat('b', 16),
            'route' => '/api/v2/auth/me', 'method' => 'GET', 'status_code' => 200,
            'duration_ms' => 8, 'db_duration_ms' => 1, 'db_query_count' => 2,
            'peak_memory_mb' => 5, 'created_at' => now()->subDays($daysAgo),
        ]);
    }

    public function test_policies_default_disabled_and_only_authorized_roles_may_read(): void
    {
        $this->getJson('/api/v2/system/support/retention')->assertUnauthorized();
        $this->actingAs($this->account('admin'));
        $this->getJson('/api/v2/system/support/retention')->assertForbidden();
        $this->getJson('/api/v2/admin/retention')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.enabled', false);
        $this->actingAs($this->account('super_admin'));
        $this->getJson('/api/v2/admin/retention')->assertForbidden();
        $this->getJson('/api/v2/system/support/retention')->assertOk()->assertJsonCount(4, 'data');
    }

    public function test_technician_and_customer_cannot_change_policies(): void
    {
        foreach (['technician', 'customer_admin', 'customer_user'] as $role) {
            $this->actingAs($this->account($role));
            $this->getJson('/api/v2/admin/retention')->assertForbidden();
        }
    }

    public function test_update_requires_password_and_scope_cannot_cross(): void
    {
        $this->actingAs($this->account('admin'));
        $payload = ['current_password' => 'wrong', 'policies' => $this->policies('business')];
        $this->putJson('/api/v2/admin/retention', $payload)->assertUnprocessable();
        $payload['current_password'] = 'RetentionTestPassword#2026';
        $payload['policies'][0]['category'] = 'failed_jobs';
        $this->putJson('/api/v2/admin/retention', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('amc_retention_policies', 0);
    }

    public function test_cleanup_disabled_does_not_delete_old_records(): void
    {
        $this->trace(120);
        app(RetentionService::class)->run();
        $this->assertDatabaseCount('amc_request_traces', 1);
    }

    public function test_technical_cleanup_enforces_cutoff_and_batch_ceiling(): void
    {
        $this->actingAs($this->account('super_admin'));
        $rows = $this->policies('technical');
        foreach ($rows as &$row) {
            if ($row['category'] === 'request_traces') {
                $row['enabled'] = true; $row['retention_days'] = 30;
                $row['batch_size'] = 25; $row['max_batches'] = 1;
            }
        }
        unset($row);
        $this->putJson('/api/v2/system/support/retention', [
            'current_password' => 'RetentionTestPassword#2026', 'policies' => $rows,
        ])->assertOk();
        for ($i=0; $i<28; $i++) $this->trace(90);
        $this->trace(2);
        $preview = app(RetentionService::class)->run(true);
        $this->assertSame(28, $preview['categories']['request_traces']);
        $this->assertDatabaseCount('amc_request_traces', 29);
        $result = app(RetentionService::class)->run();
        $this->assertSame(25, $result['categories']['request_traces']);
        $this->assertDatabaseCount('amc_request_traces', 4);
        $this->assertDatabaseHas('activity_logs', ['event' => 'system.retention.completed']);
        $this->assertSame([], app(RetentionService::class)->run()['categories']);
    }

    public function test_business_cleanup_only_removes_read_notifications(): void
    {
        $this->actingAs($this->account('admin'));
        $rows = $this->policies('business');
        $rows[0]['enabled'] = true;
        $rows[0]['retention_days'] = 30;
        $this->putJson('/api/v2/admin/retention', [
            'current_password' => 'RetentionTestPassword#2026', 'policies' => $rows,
        ])->assertOk();
        $user = $this->account('customer_user');
        DB::table('amc_user_notifications')->insert([
            ['user_id'=>$user->id, 'event'=>'ticket.updated', 'title'=>'Old read', 'message'=>'Never return this', 'deduplication_key'=>'retention-old', 'read_at'=>now()->subDays(60), 'created_at'=>now()->subDays(70), 'updated_at'=>now()->subDays(70)],
            ['user_id'=>$user->id, 'event'=>'ticket.updated', 'title'=>'Unread', 'message'=>'Keep unread', 'deduplication_key'=>'retention-unread', 'read_at'=>null, 'created_at'=>now()->subDays(70), 'updated_at'=>now()->subDays(70)],
        ]);
        app(RetentionService::class)->run();
        $this->assertDatabaseMissing('amc_user_notifications', ['deduplication_key'=>'retention-old']);
        $this->assertDatabaseHas('amc_user_notifications', ['deduplication_key'=>'retention-unread']);
        $this->assertDatabaseHas('activity_logs', ['event'=>'contractor.retention.completed']);
    }

    public function test_cannot_set_retention_below_category_minimum(): void
    {
        $this->actingAs($this->account('super_admin'));
        $rows = $this->policies('technical');
        foreach ($rows as &$row) {
            if ($row['category'] === 'failed_jobs') $row['retention_days'] = 7;
        }
        unset($row);
        $this->putJson('/api/v2/system/support/retention', [
            'current_password' => 'RetentionTestPassword#2026', 'policies' => $rows,
        ])->assertUnprocessable();
    }
    public function test_rotated_log_cleanup_only_touches_allowlisted_old_files(): void
    {
        $this->actingAs($this->account('super_admin'));
        $rows = $this->policies('technical');
        foreach ($rows as &$row) {
            if ($row['category'] === 'rotated_logs') {
                $row['enabled'] = true; $row['retention_days'] = 30;
                $row['batch_size'] = 25; $row['max_batches'] = 1;
            }
        }
        unset($row);
        $this->putJson('/api/v2/system/support/retention', [
            'current_password' => 'RetentionTestPassword#2026', 'policies' => $rows,
        ])->assertOk();

        $original = storage_path();
        $temporary = sys_get_temp_dir().'/amc-retention-test-'.bin2hex(random_bytes(8));
        mkdir($temporary.'/logs', 0700, true);
        try {
            app()->useStoragePath($temporary);
            file_put_contents($temporary.'/logs/amc-jobs-2020-01-01.log', 'old safe technical event');
            file_put_contents($temporary.'/logs/amc-jobs-'.now('UTC')->format('Y-m-d').'.log', 'active event');
            file_put_contents($temporary.'/logs/laravel-2020-01-01.log', 'unmanaged log');
            file_put_contents($temporary.'/logs/amc-jobs-2020-01-01.log.backup', 'not allowlisted');
            $result = app(RetentionService::class)->run();
            $this->assertSame(1, $result['categories']['rotated_logs']);
            $this->assertFileDoesNotExist($temporary.'/logs/amc-jobs-2020-01-01.log');
            $this->assertFileExists($temporary.'/logs/amc-jobs-'.now('UTC')->format('Y-m-d').'.log');
            $this->assertFileExists($temporary.'/logs/laravel-2020-01-01.log');
            $this->assertFileExists($temporary.'/logs/amc-jobs-2020-01-01.log.backup');
        } finally {
            app()->useStoragePath($original);
            foreach (glob($temporary.'/logs/*') ?: [] as $file) @unlink($file);
            @rmdir($temporary.'/logs');
            @rmdir($temporary);
        }
    }

}
