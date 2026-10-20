<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMfaCompleted;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash};
use Tests\TestCase;

final class SystemTelemetryV102Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $database = getenv('DB_DATABASE') ?: ($_SERVER['DB_DATABASE'] ?? '');
        if (! str_ends_with((string) $database, '_test')) {
            throw new \RuntimeException('Refusing destructive tests: export DB_DATABASE ending with _test first.');
        }

        parent::setUp();
        $this->withoutMiddleware(EnsureMfaCompleted::class);
    }

    private function account(string $role): User
    {
        return User::factory()->create([
            'role' => $role, 'status' => 'active',
            'password' => Hash::make('SecurePassword#2026'),
        ]);
    }

    public function test_contractor_admin_cannot_read_or_modify_system_telemetry(): void
    {
        $this->actingAs($this->account('admin'));
        $this->getJson('/api/v2/system/support/telemetry')->assertForbidden();
        $this->putJson('/api/v2/system/support/telemetry/settings', [])->assertForbidden();
        $this->getJson('/api/v2/system/support/telemetry/traces/'.str_repeat('a', 32))->assertForbidden();
    }

    public function test_customer_admin_cannot_read_system_telemetry(): void
    {
        $this->actingAs($this->account('customer_admin'));
        $this->getJson('/api/v2/system/support/telemetry')->assertForbidden();
    }

    public function test_super_admin_can_manage_technical_collection_with_password_and_audit(): void
    {
        $admin = $this->account('super_admin');
        $this->actingAs($admin);
        $settings = [
            'enabled' => true, 'sample_percent' => 100,
            'retention_days' => 3, 'slow_request_ms' => 1000,
        ];

        $this->putJson('/api/v2/system/support/telemetry/settings', $settings + ['current_password' => 'wrong'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('amc_telemetry_settings', 0);
        $this->putJson('/api/v2/system/support/telemetry/settings', $settings + ['current_password' => 'SecurePassword#2026'])
            ->assertOk()->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.sample_percent', 100)
            ->assertDontSee('SecurePassword#2026');
        $this->assertDatabaseHas('activity_logs', ['user_id' => $admin->id, 'event' => 'system.telemetry.settings_updated']);
        $this->getJson('/api/v2/system/support/telemetry')
            ->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('statuscode', 200)
            ->assertJsonStructure(['data' => ['sampled_requests', 'routes', 'recent_traces', 'settings']]);
    }


    public function test_telemetry_disabled_policy_keeps_business_requests_operational_without_persisted_traces(): void
    {
        $this->actingAs($this->account('super_admin'));
        DB::table('amc_telemetry_settings')->insert([
            'id' => 1, 'enabled' => 0, 'sample_percent' => 100,
            'retention_days' => 7, 'slow_request_ms' => 1000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(\App\Services\System\RequestTelemetryService::class)->clearSettingsCache();

        $this->getJson('/api/v2/auth/me')->assertOk();
        $this->assertDatabaseCount('amc_request_traces', 0);
    }

    public function test_request_trace_contains_safe_route_template_without_query_string(): void
    {
        $this->actingAs($this->account('super_admin'));
        DB::table('amc_telemetry_settings')->insert([
            'id' => 1, 'enabled' => 1, 'sample_percent' => 100,
            'retention_days' => 7, 'slow_request_ms' => 1000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(\App\Services\System\RequestTelemetryService::class)->clearSettingsCache();

        $response = $this->getJson('/api/v2/auth/me?password=DO_NOT_LOG_MY_PASSWORD');
        $response->assertOk()->assertHeader('X-Trace-ID');

        $trace = DB::table('amc_request_traces')->orderByDesc('id')->first();
        $this->assertNotNull($trace);
        $this->assertSame('api/v2/auth/me', $trace->route);
        $this->assertSame(32, strlen($trace->trace_id));
        $this->assertStringNotContainsString('DO_NOT_LOG_MY_PASSWORD', json_encode($trace));
    }
}
