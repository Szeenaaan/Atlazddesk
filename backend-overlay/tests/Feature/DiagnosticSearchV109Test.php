<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMfaCompleted;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Read-only diagnostic search; only execute on an isolated *_test database. */
final class DiagnosticSearchV109Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $database = getenv('DB_DATABASE') ?: ($_SERVER['DB_DATABASE'] ?? '');
        if (! str_ends_with((string) $database, '_test')) {
            throw new \RuntimeException('Refusing destructive tests: use DB_DATABASE ending in _test.');
        }
        parent::setUp();
        $this->withoutMiddleware(EnsureMfaCompleted::class);
    }

    private function login(string $role = 'super_admin'): void
    {
        $this->actingAs(User::factory()->create([
            'role' => $role, 'status' => 'active', 'customer_id' => null,
        ]));
    }

    private function incident(string $class, string $requestId, int $counter): void
    {
        DB::table('amc_incidents')->insert([
            'fingerprint' => hash('sha256', $class.$requestId.$counter),
            'kind' => 'exception', 'severity' => 'error',
            'exception_class' => $class,
            'message' => 'HIDDEN_INCIDENT_MESSAGE_'.$counter,
            'request_id' => $requestId,
            'occurrences' => 1,
            'first_seen_at' => now(), 'last_seen_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function failedJob(string $uuid, string $connection = 'database', string $queue = 'default'): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid, 'connection' => $connection, 'queue' => $queue,
            'payload' => 'SECRET_PAYLOAD_NOT_SEARCHABLE',
            'exception' => 'SECRET_EXCEPTION_NOT_SEARCHABLE',
            'failed_at' => now(),
        ]);
    }

    public function test_search_endpoints_are_super_admin_only(): void
    {
        foreach (['/api/v2/system/support/logs?q=queue', '/api/v2/system/support/failed-jobs?q=queue'] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        $this->login('technician');
        foreach (['/api/v2/system/support/logs?q=queue', '/api/v2/system/support/failed-jobs?q=queue'] as $path) {
            $this->getJson($path)->assertForbidden();
        }
    }

    public function test_system_logs_searches_only_safe_incident_metadata(): void
    {
        $this->login();
        $this->incident('SearchableRuntimeException', 'req-search-123', 1);
        $this->incident('DifferentException', 'req-other-456', 2);

        $this->getJson('/api/v2/system/support/logs?category=application&q=SearchableRuntime')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message', 'SearchableRuntimeException (1 occurrence(s))');
        $this->getJson('/api/v2/system/support/logs?category=application&q=req-search')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.request_id', 'req-search-123');
        $this->getJson('/api/v2/system/support/logs?q=HIDDEN_INCIDENT_MESSAGE')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_logs_searches_technical_audit_but_not_business_events(): void
    {
        $this->login();
        DB::table('activity_logs')->insert([
            ['user_id' => null, 'customer_id' => null, 'event' => 'system.support_settings.updated', 'request_id' => 'technical-123', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => null, 'customer_id' => null, 'event' => 'tickets.private_customer_operation', 'request_id' => 'technical-123', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->getJson('/api/v2/system/support/logs?category=audit&q=support_settings')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message', 'system.support_settings.updated');
        $this->getJson('/api/v2/system/support/logs?category=audit&q=private_customer_operation')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_failed_jobs_searches_uuid_connection_and_queue_without_payload(): void
    {
        $this->login();
        $this->failedJob('job-search-alpha', 'database', 'mail-notifications');
        $this->failedJob('job-search-beta', 'redis', 'maintenance');

        $this->getJson('/api/v2/system/support/failed-jobs?q=alpha')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', 'job-search-alpha');
        $this->getJson('/api/v2/system/support/failed-jobs?q=redis')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', 'job-search-beta');
        $this->getJson('/api/v2/system/support/failed-jobs?q=mail-notifications')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v2/system/support/failed-jobs?q=SECRET_PAYLOAD')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v2/system/support/logs?category=jobs&q=alpha')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.category', 'jobs')
            ->assertDontSee('SECRET_PAYLOAD_NOT_SEARCHABLE');
    }

    public function test_search_is_applied_before_result_limits(): void
    {
        $this->login();
        $this->failedJob('target-older-job');
        for ($i = 0; $i < 105; $i++) {
            $this->failedJob('recent-unrelated-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT));
        }
        $this->getJson('/api/v2/system/support/failed-jobs?q=target-older')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', 'target-older-job');
        $this->getJson('/api/v2/system/support/logs?category=jobs&q=target-older')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'job:target-older-job');
    }

    public function test_search_validates_input_and_treats_wildcards_literally(): void
    {
        $this->login();
        $this->failedJob('job-abc_def');
        $this->getJson('/api/v2/system/support/failed-jobs?q=abc_def')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v2/system/support/failed-jobs?q=abc%25def')
            ->assertOk()->assertJsonCount(0, 'data');
        foreach (['logs', 'failed-jobs'] as $endpoint) {
            $this->getJson('/api/v2/system/support/'.$endpoint.'?q=x')->assertUnprocessable();
            $this->getJson('/api/v2/system/support/'.$endpoint.'?q='.str_repeat('x', 101))->assertUnprocessable();
            $this->getJson('/api/v2/system/support/'.$endpoint.'?q[]=array')->assertUnprocessable();
        }
    }
}
