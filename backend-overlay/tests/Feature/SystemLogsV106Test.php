<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMfaCompleted;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Only run against DB_DATABASE ending in _test. */
final class SystemLogsV106Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $database = getenv('DB_DATABASE') ?: ($_SERVER['DB_DATABASE'] ?? '');
        if (!str_ends_with((string) $database, '_test')) {
            throw new \RuntimeException('Refusing destructive tests: use DB_DATABASE ending in _test.');
        }
        parent::setUp();
        // Other suites exercise MFA; here we isolate technical authorization.
        $this->withoutMiddleware(EnsureMfaCompleted::class);
    }

    private function account(string $role): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active', 'customer_id' => null]);
    }

    public function test_technical_logs_are_super_admin_only(): void
    {
        $this->getJson('/api/v2/system/support/logs')->assertUnauthorized();
        foreach (['admin', 'technician', 'customer_admin', 'customer_user'] as $role) {
            $this->actingAs($this->account($role));
            $this->getJson('/api/v2/system/support/logs')->assertForbidden();
        }
        $this->actingAs($this->account('super_admin'));

        $response = $this->getJson('/api/v2/system/support/logs')
            ->assertOk()
            ->assertJsonStructure(['data', 'window_days', 'limit']);

        $cacheControl = $response->headers->get('Cache-Control', '');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    public function test_sensitive_incident_and_job_contents_are_never_returned(): void
    {
        $this->actingAs($this->account('super_admin'));
        DB::table('amc_incidents')->insert([
            'fingerprint' => str_repeat('a', 64),
            'kind' => 'exception',
            'severity' => 'error',
            'exception_class' => 'RuntimeException',
            'message' => 'SECRET_PASSWORD_OR_SQL_PAYLOAD',
            'request_id' => 'safe-request-id',
            'occurrences' => 3,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'connection' => 'database',
            'queue' => 'private-business-queue',
            'payload' => 'SECRET_JOB_PAYLOAD',
            'exception' => 'SECRET_STACK_TRACE',
            'failed_at' => now(),
        ]);
        $this->getJson('/api/v2/system/support/logs')
            ->assertOk()->assertSee('RuntimeException')
            ->assertSee('Background job failed')
            ->assertDontSee('SECRET_PASSWORD_OR_SQL_PAYLOAD')
            ->assertDontSee('SECRET_JOB_PAYLOAD')
            ->assertDontSee('SECRET_STACK_TRACE')
            ->assertDontSee('private-business-queue');
        $this->getJson('/api/v2/system/support/logs?category=jobs')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.level', 'error');
    }

    public function test_only_technical_audit_events_appear_and_filters_validate(): void
    {
        $this->actingAs($this->account('super_admin'));
        DB::table('activity_logs')->insert([
            ['user_id' => null, 'customer_id' => null, 'event' => 'system.support_settings.updated', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => null, 'customer_id' => null, 'event' => 'tickets.private_customer_operation', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->getJson('/api/v2/system/support/logs?category=audit&level=info')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message', 'system.support_settings.updated')
            ->assertDontSee('tickets.private_customer_operation');
        $this->getJson('/api/v2/system/support/logs?category=invalid')->assertUnprocessable();
    }
}
