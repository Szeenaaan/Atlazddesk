<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\{MfaService, MfaSettings, TotpService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash};
use Tests\TestCase;

/** DESTRUCTIVE: run exclusively with a disposable *_test database. */
final class TechnicalMfaDisableDeadlockV108Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $database = getenv('DB_DATABASE') ?: ($_SERVER['DB_DATABASE'] ?? '');
        if (! str_ends_with((string) $database, '_test')) {
            throw new \RuntimeException('Refusing destructive tests outside a *_test database.');
        }
        parent::setUp();
        config()->set('sanctum.stateful', ['localhost', 'localhost:5173', '127.0.0.1']);
        config()->set('amc_mfa.required_roles', []);
        $this->withHeader('Referer', 'http://localhost/');
    }

    private function enrolledSuperAdmin(): User
    {
        $user = User::factory()->create([
            'role' => 'super_admin', 'status' => 'active',
            'password' => Hash::make('StrongPassword#2026'),
        ]);
        $user->forceFill([
            'mfa_totp_secret' => app(TotpService::class)->generateSecret(),
            'mfa_totp_confirmed_at' => now(),
            'mfa_last_totp_step' => null,
            'mfa_recovery_codes' => [],
        ])->save();
        DB::table('amc_installation_settings')->updateOrInsert(
            ['key' => 'technical_totp_available'],
            ['value' => '1', 'updated_by' => $user->id, 'updated_at' => now()]
        );
        DB::table('amc_installation_settings')->updateOrInsert(
            ['key' => 'technical_email_available'],
            ['value' => '0', 'updated_by' => $user->id, 'updated_at' => now()]
        );
        return $user;
    }

    private function verified(User $user): void
    {
        $this->actingAs($user, 'web');
        $this->withSession([
            'amc_mfa_verified_user_id' => $user->id,
            'amc_mfa_verified_methods' => ['totp'],
            'amc_mfa_verified_security_version' => (int) $user->mfa_security_version,
        ]);
    }

    public function test_policy_can_be_disabled_without_deleting_personal_authenticator(): void
    {
        $user = $this->enrolledSuperAdmin();
        $originalSecret = $user->mfa_totp_secret;
        $this->verified($user);
        $this->putJson('/api/v2/system/security/mfa', [
            'totp_enabled' => false, 'email_enabled' => false,
            'current_password' => 'StrongPassword#2026',
        ])->assertOk()->assertJsonPath('data.totp_enabled', false);
        $this->assertSame($originalSecret, $user->fresh()->mfa_totp_secret);
        $this->assertFalse(app(MfaSettings::class)->totp($user->fresh()));
        $this->getJson('/api/v2/auth/mfa/status')->assertOk()
            ->assertJsonPath('available.totp_enabled', false)
            ->assertJsonPath('totp_enabled', true);
    }

    public function test_unverified_session_cannot_downgrade_enrolled_technical_policy(): void
    {
        $user = $this->enrolledSuperAdmin();
        $this->actingAs($user, 'web');
        $this->putJson('/api/v2/system/security/mfa', [
            'totp_enabled' => false, 'current_password' => 'StrongPassword#2026',
        ])->assertForbidden();
        $this->assertTrue(app(MfaSettings::class)->totp($user->fresh()));
    }

    public function test_wrong_password_cannot_downgrade_policy_even_when_mfa_session_is_verified(): void
    {
        $user = $this->enrolledSuperAdmin();
        $this->verified($user);
        $this->putJson('/api/v2/system/security/mfa', [
            'totp_enabled' => false, 'current_password' => 'incorrect',
        ])->assertUnprocessable();
        $this->assertTrue(app(MfaSettings::class)->totp($user->fresh()));
    }

    public function test_enrolled_authenticator_can_be_removed_only_after_policy_is_disabled(): void
    {
        $user = $this->enrolledSuperAdmin();
        $codes = app(MfaService::class)->recoveryCodes($user);
        $this->verified($user);
        $this->postJson('/api/v2/auth/mfa/disable', [
            'method' => 'totp', 'password' => 'StrongPassword#2026', 'code' => $codes[0],
        ])->assertUnprocessable();
        $this->putJson('/api/v2/system/security/mfa', [
            'totp_enabled' => false, 'current_password' => 'StrongPassword#2026',
        ])->assertOk();
        $this->postJson('/api/v2/auth/mfa/disable', [
            'method' => 'totp', 'password' => 'StrongPassword#2026', 'code' => $codes[0],
        ])->assertOk();
        $this->assertNull($user->fresh()->mfa_totp_secret);
        $this->assertNull($user->fresh()->mfa_totp_confirmed_at);
    }
}
