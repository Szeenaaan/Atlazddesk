<?php

namespace Tests\Feature;

use App\Models\{ActivityLog, User};
use App\Services\Auth\{MfaService, RecoveryService, TotpService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash};
use Tests\TestCase;

/** DESTRUCTIVE: only run on the dedicated *_test database. */
final class RecoveryV105Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $name = getenv('DB_DATABASE') ?: ($_SERVER['DB_DATABASE'] ?? '');
        if (! str_ends_with((string) $name, '_test')) {
            throw new \RuntimeException('Recovery tests refuse to run without a *_test database.');
        }
        parent::setUp();
        config()->set('sanctum.stateful', ['localhost', 'localhost:5173']);
        $this->withHeader('Referer', 'http://localhost/');
    }

    private function user(string $role, ?int $customerId = null): User
    {
        $u = User::factory()->create([
            'role' => $role, 'status' => 'active', 'customer_id' => $customerId,
            'password' => Hash::make('StrongPassword#2026'),
        ]);
        $u->forceFill([
            'mfa_totp_secret' => app(TotpService::class)->generateSecret(),
            'mfa_totp_confirmed_at' => now(),
            'mfa_email_enabled' => true,
            'email_verified_at' => now(),
        ])->save();
        return $u;
    }

    private function browserSession(User $user, ?int $version = null): void
    {
        $this->actingAs($user, 'web');
        $this->withSession([
            'amc_mfa_verified_user_id' => $user->id,
            'amc_mfa_verified_methods' => ['totp', 'email'],
            'amc_mfa_verified_security_version' => $version ?? (int) $user->mfa_security_version,
        ]);
    }

    public function test_recovery_codes_can_be_replaced_after_all_codes_are_used(): void
    {
        $user = $this->user('admin');
        $user->forceFill(['mfa_recovery_codes' => []])->save();
        $this->browserSession($user);

        $response = $this->postJson('/api/v2/auth/mfa/recovery/regenerate', [
            'password' => 'StrongPassword#2026',
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $codes = $response->json('recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));
        $this->assertCount(8, $user->fresh()->mfa_recovery_codes);
        $this->assertNotEquals($codes, $user->fresh()->mfa_recovery_codes);
        $this->assertEquals(1, $user->fresh()->mfa_security_version);
        $this->getJson('/api/v2/auth/me')->assertOk();
        $this->assertTrue(app(MfaService::class)->consumeRecovery($user, $codes[0]));
        $this->assertFalse(app(MfaService::class)->consumeRecovery($user, $codes[0]));
        $this->assertDatabaseHas('activity_logs', ['event' => 'security.recovery_codes_regenerated', 'subject_id' => $user->id]);
    }

    public function test_recovery_regeneration_rejects_wrong_password(): void
    {
        $user = $this->user('admin');
        $this->browserSession($user);
        $this->postJson('/api/v2/auth/mfa/recovery/regenerate', ['password' => 'bad'])
            ->assertStatus(422);
        $this->assertEquals(0, $user->fresh()->mfa_security_version);
    }

    public function test_stateless_token_cannot_regenerate_recovery_codes(): void
    {
        $user = $this->user('admin');
        $this->actingAs($user, 'sanctum');
        $this->postJson('/api/v2/auth/mfa/recovery/regenerate', [
            'password' => 'StrongPassword#2026',
        ])->assertStatus(403);
    }

    public function test_contractor_admin_resets_workforce_authenticator_and_preserves_email(): void
    {
        $admin = $this->user('admin');
        $worker = $this->user('technician');
        $worker->forceFill(['mfa_recovery_codes' => [Hash::make('A')]])->save();
        $this->browserSession($admin);
        $this->postJson('/api/v2/users/'.$worker->id.'/mfa/authenticator/reset', [
            'current_password' => 'StrongPassword#2026',
            'reason' => 'Verified employee identity through support procedure',
        ])->assertOk();
        $fresh = $worker->fresh();
        $this->assertNull($fresh->mfa_totp_secret);
        $this->assertNull($fresh->mfa_totp_confirmed_at);
        $this->assertEmpty($fresh->mfa_recovery_codes);
        $this->assertTrue($fresh->mfa_email_enabled);
        $this->assertEquals(1, $fresh->mfa_security_version);
        $this->assertDatabaseHas('activity_logs', ['event' => 'security.authenticator_reset_by_admin', 'subject_id' => $worker->id]);
    }

    public function test_contractor_admin_cannot_reset_another_admin_or_super_admin(): void
    {
        $admin = $this->user('admin');
        $other = $this->user('admin');
        $super = $this->user('super_admin');
        $this->browserSession($admin);
        foreach ([$other, $super] as $target) {
            $this->postJson('/api/v2/users/'.$target->id.'/mfa/authenticator/reset', [
                'current_password' => 'StrongPassword#2026',
                'reason' => 'Verified identity for support recovery',
            ])->assertNotFound();
            $this->assertNotNull($target->fresh()->mfa_totp_secret);
        }
    }

    public function test_web_reset_does_not_allow_admin_to_reset_own_authenticator(): void
    {
        $admin = $this->user('admin');
        $this->browserSession($admin);
        $this->postJson('/api/v2/users/'.$admin->id.'/mfa/authenticator/reset', [
            'current_password' => 'StrongPassword#2026',
            'reason' => 'Attempt to reset account without separate review',
        ])->assertForbidden();
        $this->assertNotNull($admin->fresh()->mfa_totp_secret);
    }

    public function test_customer_admin_cannot_reset_user_from_another_customer(): void
    {
        $first = \App\Models\Customer::create([
            'company_name' => 'First Client', 'customer_code' => 'RCV-101', 'status' => 'active',
        ]);
        $second = \App\Models\Customer::create([
            'company_name' => 'Second Client', 'customer_code' => 'RCV-102', 'status' => 'active',
        ]);
        $admin = $this->user('customer_admin', $first->id);
        $otherUser = $this->user('customer_user', $second->id);
        $this->browserSession($admin);
        $this->postJson('/api/v2/users/'.$otherUser->id.'/mfa/authenticator/reset', [
            'current_password' => 'StrongPassword#2026',
            'reason' => 'Support verified the user through identity checks',
        ])->assertNotFound();
    }

    public function test_emergency_command_requires_exact_interactive_confirmation(): void
    {
        $super = $this->user('super_admin');
        $incident = 'INC-TEST-0201';
        $question = 'Type RESET '.$super->email.' to authorize the reset';

        $this->artisan('amc:security:recover-admin', [
            'email' => $super->email, '--incident' => $incident,
        ])->expectsQuestion($question, 'wrong confirmation')->assertExitCode(1);
        $this->assertNotNull($super->fresh()->mfa_totp_secret);

        $this->artisan('amc:security:recover-admin', [
            'email' => $super->email, '--incident' => $incident,
        ])->expectsQuestion($question, 'RESET '.$super->email)->assertExitCode(0);
        $this->assertNull($super->fresh()->mfa_totp_secret);
        $this->assertTrue($super->fresh()->mfa_email_enabled);
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'security.super_admin_emergency_recovery',
            'subject_id' => $super->id,
        ]);
    }

    public function test_session_security_version_revokes_session_after_admin_reset(): void
    {
        $user = $this->user('admin');
        $this->browserSession($user, 0);
        $this->getJson('/api/v2/auth/me')->assertOk();
        app(RecoveryService::class)->resetAuthenticator($user);
        $this->getJson('/api/v2/auth/me')->assertStatus(403)->assertJsonPath('code', 'MFA_REQUIRED');
    }
}
