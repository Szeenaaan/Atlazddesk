<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMfaCompleted;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** These tests reset tables. Use the dedicated amc_platform_test database only. */
final class SystemSupportV9Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $database = getenv('DB_DATABASE') ?: ($_SERVER['DB_DATABASE'] ?? '');
        if (!str_ends_with((string) $database, '_test')) {
            throw new \RuntimeException('Refusing destructive tests: export DB_DATABASE ending with _test first.');
        }
        parent::setUp();
        $this->withoutMiddleware(EnsureMfaCompleted::class);
    }

    private function account(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'customer_id' => null,
            'password' => Hash::make('SecurePassword#2026'),
        ]);
    }

    public function test_super_admin_gets_sanitized_diagnostics_without_customer_workspace(): void
    {
        $this->actingAs($this->account('super_admin'));
        $this->getJson('/api/v2/system/support/overview')
            ->assertOk()->assertJsonPath('data.checks.database.status', 'healthy')
            ->assertJsonStructure(['data' => ['runtime', 'checks', 'limitations']])
            ->assertDontSee('DB_PASSWORD')->assertDontSee('MAIL_PASSWORD');
        $response = $this->getJson('/api/v2/system/support/report')
            ->assertOk()
            ->assertDontSee('password');

        $this->assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control', '')
        );
        $this->getJson('/api/v2/tickets')->assertForbidden();
    }

    public function test_all_other_roles_are_forbidden_from_technical_support(): void
    {
        foreach (['admin', 'technician', 'customer_admin', 'customer_user'] as $role) {
            $this->actingAs($this->account($role));
            $this->getJson('/api/v2/system/support/overview')->assertForbidden();
            $this->getJson('/api/v2/system/support/report')->assertForbidden();
            $this->getJson('/api/v2/system/support/failed-jobs')->assertForbidden();
            $this->getJson('/api/v2/system/support/settings')->assertForbidden();
            $this->putJson('/api/v2/system/support/settings', [])->assertForbidden();
            $this->postJson('/api/v2/system/support/test-email', [])->assertForbidden();
        }
    }

    public function test_settings_require_password_and_are_audited_without_secrets(): void
    {
        $admin = $this->account('super_admin');
        $this->actingAs($admin);
        $settings = [
            'installation_label' => 'Contractor Alpha',
            'support_email' => 'support@example.com',
            'queue_warning_depth' => 20,
            'disk_warning_percent' => 12
        ];
        $this->putJson('/api/v2/system/support/settings', $settings + ['current_password' => 'bad'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('amc_installation_settings', 0);
        $this->putJson('/api/v2/system/support/settings', $settings + ['current_password' => 'SecurePassword#2026'])
            ->assertOk()->assertJsonPath('data.queue_warning_depth', 20)
            ->assertJsonPath('data.installation_label', 'Contractor Alpha');
        $this->assertDatabaseHas('amc_installation_settings', ['key' => 'support_email', 'value' => 'support@example.com']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'system.support_settings.updated', 'user_id' => $admin->id]);
        $this->getJson('/api/v2/system/support/audit?event=system.support_settings.updated')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v2/system/support/settings')->assertJsonMissing(['current_password' => 'SecurePassword#2026']);
    }

    public function test_failed_jobs_list_never_contains_serialized_payload_or_exception(): void
    {
        $this->actingAs($this->account('super_admin'));
        \Illuminate\Support\Facades\DB::table('failed_jobs')->insert([
            'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'connection' => 'database',
            'queue' => 'notifications',
            'payload' => 'SECRET_PAYLOAD_DO_NOT_RETURN',
            'exception' => 'SECRET_EXCEPTION_DO_NOT_RETURN',
            'failed_at' => now(),
        ]);
        $this->getJson('/api/v2/system/support/failed-jobs')
            ->assertOk()->assertJsonPath('data.0.queue', 'notifications')
            ->assertDontSee('SECRET_PAYLOAD_DO_NOT_RETURN')
            ->assertDontSee('SECRET_EXCEPTION_DO_NOT_RETURN');
    }

    public function test_test_email_refuses_log_mailer_and_wrong_password(): void
    {
        $this->actingAs($this->account('super_admin'));
        config(['mail.default' => 'log']);
        $this->postJson('/api/v2/system/support/test-email', ['current_password' => 'incorrect'])
            ->assertUnprocessable();
        $this->postJson('/api/v2/system/support/test-email', ['current_password' => 'SecurePassword#2026'])
            ->assertUnprocessable();
    }
}
