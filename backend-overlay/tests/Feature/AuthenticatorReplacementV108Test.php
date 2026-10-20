<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\{MfaService, MfaSettings, TotpService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash};
use Tests\TestCase;

/** DESTRUCTIVE: run only against the separate *_test database. */
final class AuthenticatorReplacementV108Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $name = getenv('DB_DATABASE') ?: ($_SERVER['DB_DATABASE'] ?? '');
        if (! str_ends_with((string) $name, '_test')) {
            throw new \RuntimeException('Replacement tests refuse databases without a *_test suffix.');
        }
        parent::setUp();
        config()->set('sanctum.stateful', ['localhost', 'localhost:5173']);
        $this->withHeader('Referer', 'http://localhost/');
        DB::table('amc_security_settings')->updateOrInsert(['key' => 'mfa_totp_enabled'], [
            'enabled' => true, 'updated_at' => now(),
        ]);
        DB::table('amc_security_settings')->updateOrInsert(['key' => 'mfa_email_enabled'], [
            'enabled' => false, 'updated_at' => now(),
        ]);
        app(MfaSettings::class)->invalidate();
    }

    private function user(string $role = 'admin'): User
    {
        $user = User::factory()->create([
            'role' => $role, 'status' => 'active',
            'password' => Hash::make('StrongPassword#2026'),
        ]);
        $user->forceFill([
            'mfa_totp_secret' => app(TotpService::class)->generateSecret(),
            'mfa_totp_confirmed_at' => now(),
            'mfa_last_totp_step' => null,
            'mfa_recovery_codes' => [],
        ])->save();
        return $user;
    }

    private function sessionFor(User $user, array $methods = ['totp']): void
    {
        $this->actingAs($user, 'web');
        $this->withSession([
            'amc_mfa_verified_user_id' => $user->id,
            'amc_mfa_verified_methods' => $methods,
            'amc_mfa_verified_security_version' => (int) $user->mfa_security_version,
        ]);
    }

    private function totp(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $letter) {
            $bits .= str_pad(decbin(strpos($alphabet, $letter)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $octet) {
            $key .= chr(bindec($octet));
        }
        $step = intdiv(time(), 30);
        $digest = hash_hmac('sha1', pack('N2', ($step >> 32) & 0xffffffff, $step & 0xffffffff), $key, true);
        $offset = ord($digest[19]) & 15;
        $binary = ((ord($digest[$offset]) & 0x7f) << 24)
            | (ord($digest[$offset + 1]) << 16)
            | (ord($digest[$offset + 2]) << 8)
            | ord($digest[$offset + 3]);
        return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function test_wrong_password_or_old_code_cannot_begin_replacement(): void
    {
        $user = $this->user();
        $this->sessionFor($user);
        $secret = $user->mfa_totp_secret;
        $this->postJson('/api/v2/auth/mfa/totp/replace/start', [
            'password' => 'wrong-password', 'current_code' => '000000',
        ])->assertStatus(422);
        $this->postJson('/api/v2/auth/mfa/totp/replace/start', [
            'password' => 'StrongPassword#2026', 'current_code' => 'not-a-code',
        ])->assertStatus(422);
        $this->assertSame($secret, $user->fresh()->mfa_totp_secret);
        $this->assertNull($user->fresh()->mfa_totp_pending_secret);
    }

    public function test_current_authenticator_code_can_start_replacement(): void
    {
        $user = $this->user();
        $oldSecret = $user->mfa_totp_secret;
        $this->sessionFor($user);
        $this->postJson('/api/v2/auth/mfa/totp/replace/start', [
            'password' => 'StrongPassword#2026', 'current_code' => $this->totp($oldSecret),
        ])->assertOk();
        $this->assertSame($oldSecret, $user->fresh()->mfa_totp_secret);
    }

    public function test_email_requirement_is_not_removed_during_replacement(): void
    {
        DB::table('amc_security_settings')->updateOrInsert(['key' => 'mfa_email_enabled'], [
            'enabled' => true, 'updated_at' => now(),
        ]);
        app(MfaSettings::class)->invalidate();
        $user = $this->user();
        $user->forceFill(['mfa_email_enabled' => true, 'email_verified_at' => now()])->save();
        $codes = app(MfaService::class)->recoveryCodes($user);
        $this->sessionFor($user, ['totp', 'email']);
        $newSecret = $this->postJson('/api/v2/auth/mfa/totp/replace/start', [
            'password' => 'StrongPassword#2026', 'current_code' => $codes[0],
        ])->assertOk()->json('secret');
        $this->postJson('/api/v2/auth/mfa/totp/replace/confirm', [
            'code' => $this->totp($newSecret),
        ])->assertOk();
        $fresh = $user->fresh();
        $this->assertTrue((bool) $fresh->mfa_email_enabled);
        $this->assertSame(['totp', 'email'], app(MfaService::class)->requiredMethods($fresh));
        $this->getJson('/api/v2/auth/me')->assertOk();
    }

    public function test_start_and_cancel_preserve_existing_device_and_codes(): void
    {
        $user = $this->user();
        $recovery = app(MfaService::class)->recoveryCodes($user);
        $this->sessionFor($user);
        $oldSecret = $user->fresh()->mfa_totp_secret;
        $response = $this->postJson('/api/v2/auth/mfa/totp/replace/start', [
            'password' => 'StrongPassword#2026', 'current_code' => $recovery[0],
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertNotSame($oldSecret, $response->json('secret'));
        $this->assertSame($oldSecret, $user->fresh()->mfa_totp_secret);
        $this->assertNotNull($user->fresh()->mfa_totp_pending_secret);
        $this->postJson('/api/v2/auth/mfa/totp/replace/cancel')->assertOk();
        $this->assertSame($oldSecret, $user->fresh()->mfa_totp_secret);
        $this->assertNull($user->fresh()->mfa_totp_pending_secret);
        $this->postJson('/api/v2/auth/mfa/totp/replace/confirm', [
            'code' => $this->totp($response->json('secret')),
        ])->assertStatus(409);
    }

    public function test_new_code_must_be_verified_before_switching_and_codes_rotate(): void
    {
        $user = $this->user();
        $oldCodes = app(MfaService::class)->recoveryCodes($user);
        $oldSecret = $user->fresh()->mfa_totp_secret;
        $this->sessionFor($user);
        $start = $this->postJson('/api/v2/auth/mfa/totp/replace/start', [
            'password' => 'StrongPassword#2026', 'current_code' => $oldCodes[0],
        ])->assertOk();
        $newSecret = $start->json('secret');
        $this->postJson('/api/v2/auth/mfa/totp/replace/confirm', ['code' => 'not-valid'])
            ->assertStatus(422);
        $this->assertSame($oldSecret, $user->fresh()->mfa_totp_secret);
        $confirmed = $this->postJson('/api/v2/auth/mfa/totp/replace/confirm', [
            'code' => $this->totp($newSecret),
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertCount(8, $confirmed->json('recovery_codes'));
        $this->assertSame($newSecret, $user->fresh()->mfa_totp_secret);
        $this->assertNull($user->fresh()->mfa_totp_pending_secret);
        $this->assertSame(1, (int) $user->fresh()->mfa_security_version);
        $this->assertFalse(app(MfaService::class)->consumeRecovery($user, $oldCodes[1]));
        $this->assertTrue(app(MfaService::class)->consumeRecovery($user, $confirmed->json('recovery_codes.0')));
        $this->getJson('/api/v2/auth/me')->assertOk();
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'security.authenticator_replaced', 'subject_id' => $user->id,
        ]);
    }

    public function test_old_session_version_is_rejected_after_successful_replacement(): void
    {
        $user = $this->user();
        $recovery = app(MfaService::class)->recoveryCodes($user);
        $this->sessionFor($user);
        $newSecret = $this->postJson('/api/v2/auth/mfa/totp/replace/start', [
            'password' => 'StrongPassword#2026', 'current_code' => $recovery[0],
        ])->assertOk()->json('secret');
        $this->postJson('/api/v2/auth/mfa/totp/replace/confirm', [
            'code' => $this->totp($newSecret),
        ])->assertOk();
        // Simulate another browser that still carries the old security version.
        $this->withSession(['amc_mfa_verified_security_version' => 0]);
        $this->getJson('/api/v2/auth/me')->assertStatus(403)->assertJsonPath('code', 'MFA_REQUIRED');
    }

    public function test_super_admin_replaces_key_while_policy_remains_mandatory(): void
    {
        DB::table('amc_installation_settings')->updateOrInsert(['key' => 'technical_totp_available'], [
            'value' => '1', 'updated_at' => now(),
        ]);
        $user = $this->user('super_admin');
        $recovery = app(MfaService::class)->recoveryCodes($user);
        $this->sessionFor($user);
        $new = $this->postJson('/api/v2/auth/mfa/totp/replace/start', [
            'password' => 'StrongPassword#2026', 'current_code' => $recovery[0],
        ])->assertOk()->json('secret');
        $this->postJson('/api/v2/auth/mfa/totp/replace/confirm', ['code' => $this->totp($new)])
            ->assertOk();
        $this->assertContains('totp', app(MfaService::class)->requiredMethods($user->fresh()));
    }

    public function test_replacement_not_available_without_enrollment_or_verified_session(): void
    {
        $user = $this->user();
        $this->sessionFor($user);
        $user->forceFill(['mfa_totp_secret' => null, 'mfa_totp_confirmed_at' => null])->save();
        $this->postJson('/api/v2/auth/mfa/totp/replace/start', [
            'password' => 'StrongPassword#2026', 'current_code' => '000000',
        ])->assertStatus(409);
        $user->forceFill([
            'mfa_totp_secret' => app(TotpService::class)->generateSecret(),
            'mfa_totp_confirmed_at' => now(),
        ])->save();
        $this->withSession(['amc_mfa_verified_user_id' => 0]);
        $this->postJson('/api/v2/auth/mfa/totp/replace/start', [
            'password' => 'StrongPassword#2026', 'current_code' => '000000',
        ])->assertStatus(403);
    }
}
