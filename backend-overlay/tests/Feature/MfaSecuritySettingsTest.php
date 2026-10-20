<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class MfaSecuritySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $database = getenv('DB_DATABASE') ?: ($_SERVER['DB_DATABASE'] ?? '');
        if (! str_ends_with((string) $database, '_test')) {
            throw new \RuntimeException('Refusing destructive tests: export DB_DATABASE ending with _test first.');
        }
        parent::setUp();
    }


    private function user(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'password' => Hash::make('TestStrongPassword#2026'),
        ]);
    }

    public function test_only_contractor_admin_can_change_global_mfa_methods(): void
    {
        $user = $this->user('technician');
        $this->actingAs($user)->getJson('/api/v2/admin/security/mfa')->assertForbidden();
        $superAdmin = $this->user('super_admin');
        $this->actingAs($superAdmin)->getJson('/api/v2/admin/security/mfa')->assertForbidden();
        $this->actingAs($superAdmin)->patchJson('/api/v2/admin/security/mfa', [
            'totp_enabled' => true, 'current_password' => 'TestStrongPassword#2026',
        ])->assertForbidden();
        $this->actingAs($user)->patchJson('/api/v2/admin/security/mfa', [
            'totp_enabled' => true, 'current_password' => 'TestStrongPassword#2026',
        ])->assertForbidden();
        $admin = $this->user('admin');
        $this->actingAs($admin)->getJson('/api/v2/admin/security/mfa')
            ->assertOk()->assertJsonPath('data.totp_enabled', false);
        $this->actingAs($admin)->patchJson('/api/v2/admin/security/mfa', [
            'totp_enabled' => true, 'current_password' => 'bad-password',
        ])->assertStatus(422);
        $this->actingAs($admin)->patchJson('/api/v2/admin/security/mfa', [
            'totp_enabled' => true, 'current_password' => 'TestStrongPassword#2026',
        ])->assertOk()->assertJsonPath('data.totp_enabled', true);
        $this->assertDatabaseHas('amc_security_settings', [
            'key' => 'mfa_totp_enabled', 'enabled' => 1, 'updated_by' => $admin->id,
        ]);
    }

    public function test_email_otp_requires_a_configured_default_provider(): void
    {

        $admin = $this->user('admin');
        $this->actingAs($admin)->patchJson('/api/v2/admin/security/mfa', [
            'email_enabled' => true, 'current_password' => 'TestStrongPassword#2026',
        ])->assertStatus(422);
        $this->assertDatabaseHas('amc_security_settings', ['key' => 'mfa_email_enabled', 'enabled' => 0]);
    }
}
