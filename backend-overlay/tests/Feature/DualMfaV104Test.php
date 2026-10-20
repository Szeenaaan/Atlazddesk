<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMfaCompleted;
use App\Models\{AmcMailProvider, User};
use App\Services\Auth\{MfaService, TotpService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash, Http};
use Tests\TestCase;

/** Integration tests are DESTRUCTIVE. Run only against the disposable *_test DB. */
final class DualMfaV104Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $database = getenv('DB_DATABASE') ?: ($_SERVER['DB_DATABASE'] ?? '');
        if (! str_ends_with((string) $database, '_test')) {
            throw new \RuntimeException('Refusing destructive MFA tests without a *_test database.');
        }
        parent::setUp();

        // A React SPA sends an Origin/Referer header recognized by Sanctum.
        // Without it, /api/v2/auth/me is a stateless API request, has no web
        // session, and must be denied when MFA is required. Simulate the real
        // browser session rather than weakening the production MFA middleware.
        config()->set('sanctum.stateful', ['localhost', 'localhost:5173', '127.0.0.1']);
        $this->withHeader('Referer', 'http://localhost/');
    }

    private function account(string $role = 'admin', bool $enrolled = true): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'password' => Hash::make('StrongPassword#2026'),
            'email_verified_at' => $enrolled ? now() : null,
            'mfa_email_enabled' => $enrolled,
        ]);
        if ($enrolled) {
            $user->forceFill([
                'mfa_totp_secret' => app(TotpService::class)->generateSecret(),
                'mfa_totp_confirmed_at' => now(),
                'mfa_last_totp_step' => null,
            ])->save();
        }
        return $user;
    }

    private function enableMethods(bool $totp, bool $email): void
    {
        foreach (['mfa_totp_enabled' => $totp, 'mfa_email_enabled' => $email] as $key => $enabled) {
            DB::table('amc_security_settings')->updateOrInsert(['key' => $key], [
                'enabled' => $enabled, 'updated_at' => now(),
            ]);
        }
        app(\App\Services\Auth\MfaSettings::class)->invalidate();
    }

    private function configureEmail(): void
    {
        $provider = AmcMailProvider::create([
            'name' => 'Test provider',
            'driver' => 'resend',
            'from_email' => 'no-reply@example.test',
            'from_name' => 'AMC Test',
            'credentials' => ['api_key' => 'fake-api-key-not-real'],
            'is_active' => true,
        ]);
        DB::table('amc_mail_settings')->updateOrInsert(['id' => 1], [
            'default_provider_id' => $provider->id,
            'notifications_enabled' => true,
            'updated_at' => now(),
        ]);
    }

    private function login(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v2/auth/login', [
            'email' => $user->email,
            'password' => 'StrongPassword#2026',
        ]);
    }

    /** Computes only for tests; production OTP generation stays in the authenticator app. */
    private function totpCode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $character) {
            $bits .= str_pad(decbin(strpos($alphabet, $character)), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $octet) {
            $bytes .= chr(bindec($octet));
        }
        $counter = intdiv(time(), 30);
        $digest = hash_hmac('sha1', pack('N2', 0, $counter), $bytes, true);
        $offset = ord($digest[19]) & 15;
        $binary = ((ord($digest[$offset]) & 127) << 24)
            | (ord($digest[$offset + 1]) << 16)
            | (ord($digest[$offset + 2]) << 8)
            | ord($digest[$offset + 3]);
        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private function interceptEmail(): \Closure
    {
        $code = null;
        Http::fake(function ($request) use (&$code) {
            if (preg_match('/code is: (\d{6})/', (string) $request['text'], $matches)) {
                $code = $matches[1];
            }
            return Http::response(['id' => 'accepted'], 200);
        });
        return function () use (&$code): string {
            $this->assertNotNull($code, 'Expected the fake email transport to receive a security code.');
            return $code;
        };
    }

    public function test_no_methods_enabled_allows_password_login(): void
    {
        $this->enableMethods(false, false);
        $user = $this->account('admin', false);
        $this->login($user)->assertOk()->assertJsonPath('status', 'authenticated');
        $this->getJson('/api/v2/auth/me')->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_totp_only_stays_unauthenticated_until_totp_succeeds(): void
    {
        $this->enableMethods(true, false);
        $user = $this->account();
        $this->login($user)->assertStatus(202)->assertJsonPath('remaining_methods.0', 'totp');
        $this->getJson('/api/v2/auth/me')->assertUnauthorized();
        $this->postJson('/api/v2/auth/mfa/verify', [
            'method' => 'totp', 'code' => $this->totpCode($user->mfa_totp_secret),
        ])->assertOk()->assertJsonPath('status', 'authenticated');
        $this->getJson('/api/v2/auth/me')->assertOk();
    }

    public function test_email_only_requires_emailed_code_before_login(): void
    {
        $this->enableMethods(false, true);
        $this->configureEmail();
        $user = $this->account();
        $readCode = $this->interceptEmail();
        $this->login($user)->assertStatus(202)->assertJsonPath('remaining_methods.0', 'email');
        $this->getJson('/api/v2/auth/me')->assertUnauthorized();
        $this->postJson('/api/v2/auth/mfa/email/send')->assertOk();
        $this->postJson('/api/v2/auth/mfa/verify', [
            'method' => 'email', 'code' => '000000',
        ])->assertUnprocessable();
        $this->getJson('/api/v2/auth/me')->assertUnauthorized();
        $this->postJson('/api/v2/auth/mfa/verify', [
            'method' => 'email', 'code' => $readCode(),
        ])->assertOk()->assertJsonPath('status', 'authenticated');
        $this->getJson('/api/v2/auth/me')->assertOk();
    }

    public function test_both_methods_require_two_independent_verifications(): void
    {
        $this->enableMethods(true, true);
        $this->configureEmail();
        $user = $this->account();
        $readCode = $this->interceptEmail();

        $this->login($user)->assertStatus(202)
            ->assertJsonPath('required_methods.0', 'totp')
            ->assertJsonPath('required_methods.1', 'email');
        $this->getJson('/api/v2/auth/me')->assertUnauthorized();

        $this->postJson('/api/v2/auth/mfa/verify', [
            'method' => 'totp', 'code' => $this->totpCode($user->mfa_totp_secret),
        ])->assertOk()->assertJsonPath('remaining_methods.0', 'email')
            ->assertJsonPath('verified_methods.0', 'totp');
        $this->getJson('/api/v2/auth/me')->assertUnauthorized();

        $this->postJson('/api/v2/auth/mfa/email/send')->assertOk();
        $this->postJson('/api/v2/auth/mfa/verify', [
            'method' => 'email', 'code' => $readCode(),
        ])->assertOk()->assertJsonPath('status', 'authenticated');
        $this->getJson('/api/v2/auth/me')->assertOk()->assertJsonPath('user.id', $user->id);
        Http::assertSentCount(1);
    }

    public function test_unenrolled_business_user_verifies_email_without_forced_google_enrollment(): void
    {
        $this->enableMethods(true, true);
        $this->configureEmail();
        $user = $this->account('admin', false);
        $readCode = $this->interceptEmail();

        $this->login($user)->assertStatus(202)
            ->assertJsonPath('required_methods.0', 'email')
            ->assertJsonPath('setup_methods.0', 'email');
        $this->getJson('/api/v2/auth/me')->assertUnauthorized();
        $this->postJson('/api/v2/auth/mfa/totp/setup')->assertForbidden();
        $this->postJson('/api/v2/auth/mfa/email/send')->assertOk();
        $this->postJson('/api/v2/auth/mfa/verify', [
            'method' => 'email', 'code' => $readCode(),
        ])->assertOk()->assertJsonPath('status', 'authenticated');
        $this->assertNull($user->fresh()->mfa_totp_confirmed_at);
        $this->assertTrue((bool) $user->fresh()->mfa_email_enabled);
        $this->getJson('/api/v2/auth/me')->assertOk();
    }

    public function test_recovery_code_cannot_replace_email_when_both_are_enabled(): void
    {
        $this->enableMethods(true, true);
        $this->configureEmail();
        $user = $this->account();
        $codes = app(MfaService::class)->recoveryCodes($user);
        $this->login($user)->assertStatus(202);
        $this->postJson('/api/v2/auth/mfa/verify', [
            'method' => 'recovery', 'code' => $codes[0],
        ])->assertOk()->assertJsonPath('remaining_methods.0', 'email');
        $this->getJson('/api/v2/auth/me')->assertUnauthorized();
        $this->postJson('/api/v2/auth/mfa/verify', [
            'method' => 'recovery', 'code' => $codes[1],
        ])->assertForbidden();
    }

    public function test_policy_change_requires_reauthentication_of_new_method(): void
    {
        $this->enableMethods(false, false);
        $user = $this->account();
        $this->login($user)->assertOk();
        $this->getJson('/api/v2/auth/me')->assertOk();
        $this->enableMethods(true, true);
        $this->getJson('/api/v2/auth/me')->assertForbidden()
            ->assertJsonPath('code', 'MFA_REQUIRED');
    }

    public function test_super_admin_technical_flags_are_independent_and_require_both(): void
    {
        $user = $this->account('super_admin');
        DB::table('amc_installation_settings')->updateOrInsert(['key' => 'technical_totp_available'], [
            'value' => '1', 'updated_at' => now(),
        ]);
        DB::table('amc_installation_settings')->updateOrInsert(['key' => 'technical_email_available'], [
            'value' => '1', 'updated_at' => now(),
        ]);
        $this->assertSame(['totp', 'email'], app(MfaService::class)->requiredMethods($user));
        $this->login($user)->assertStatus(202)->assertJsonPath('required_methods.1', 'email');
    }
}
