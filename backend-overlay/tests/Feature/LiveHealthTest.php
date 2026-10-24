<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LiveHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Isolate the dependency probe from middleware's own cache usage.
        $this->withoutMiddleware();
    }

    public function test_redis_string_round_trip_reports_ready_and_cleans_up(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([(object) ['1' => 1]]);
        $key = 'amc:health:'.getmypid();
        Cache::shouldReceive('put')->once()->with($key, '1', 10)->andReturn(true);
        Cache::shouldReceive('get')->once()->with($key)->andReturn('1');
        Cache::shouldReceive('forget')->once()->with($key)->andReturn(true);

        $this->getJson('/api/v2/health/ready')->assertOk()->assertExactJson([
            'status' => 'ready', 'checks' => ['database' => true, 'cache' => true],
        ]);
    }

    public function test_cache_read_failure_reports_degraded_and_still_cleans_up(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([]);
        $key = 'amc:health:'.getmypid();
        Cache::shouldReceive('put')->once()->with($key, '1', 10)->andReturn(true);
        Cache::shouldReceive('get')->once()->with($key)
            ->andThrow(new \RuntimeException('Private connection details must not be exposed'));
        Cache::shouldReceive('forget')->once()->with($key)->andReturn(true);

        $this->assertDegraded(true);
    }

    public function test_cache_write_failure_still_attempts_cleanup(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([]);
        $key = 'amc:health:'.getmypid();
        Cache::shouldReceive('put')->once()->with($key, '1', 10)
            ->andThrow(new \RuntimeException('Cache write failed'));
        Cache::shouldReceive('get')->never();
        Cache::shouldReceive('forget')->once()->with($key)->andReturn(true);

        $this->assertDegraded(true);
    }

    public function test_cache_cleanup_failure_reports_degraded_without_exposing_exception(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([]);
        $key = 'amc:health:'.getmypid();
        Cache::shouldReceive('put')->once()->with($key, '1', 10)->andReturn(true);
        Cache::shouldReceive('get')->once()->with($key)->andReturn('1');
        Cache::shouldReceive('forget')->once()->with($key)
            ->andThrow(new \RuntimeException('Cache cleanup failed'));

        $this->assertDegraded(true);
    }

    public function test_missing_cache_value_reports_degraded(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([]);
        $key = 'amc:health:'.getmypid();
        Cache::shouldReceive('put')->once()->with($key, '1', 10)->andReturn(false);
        Cache::shouldReceive('get')->once()->with($key)->andReturn(null);
        Cache::shouldReceive('forget')->once()->with($key)->andReturn(false);

        $this->assertDegraded(true);
    }

    public function test_database_failure_reports_degraded_with_healthy_cache(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')
            ->andThrow(new \RuntimeException('Database connection details must not be exposed'));
        $key = 'amc:health:'.getmypid();
        Cache::shouldReceive('put')->once()->with($key, '1', 10)->andReturn(true);
        Cache::shouldReceive('get')->once()->with($key)->andReturn('1');
        Cache::shouldReceive('forget')->once()->with($key)->andReturn(true);

        $this->getJson('/api/v2/health/ready')->assertStatus(503)->assertExactJson([
            'status' => 'degraded', 'checks' => ['database' => false, 'cache' => true],
        ]);
    }

    private function assertDegraded(bool $database): void
    {
        $this->getJson('/api/v2/health/ready')->assertStatus(503)->assertExactJson([
            'status' => 'degraded', 'checks' => ['database' => $database, 'cache' => false],
        ]);
    }
}
