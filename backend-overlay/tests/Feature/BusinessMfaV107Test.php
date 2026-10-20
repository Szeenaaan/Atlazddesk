<?php

namespace Tests\Feature;

use App\Models\{User, Customer};
use App\Services\Auth\{MfaService, MfaSettings, TotpService, RecoveryService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash};
use Tests\TestCase;

/** Destructive integration tests: MUST use only a disposable *_test database. */
final class BusinessMfaV107Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $database = getenv('DB_DATABASE') ?: ($_SERVER['DB_DATABASE'] ?? '');
        if (! str_ends_with((string) $database, '_test')) {
            throw new \RuntimeException('Refusing destructive tests without a *_test database.');
        }
        parent::setUp();
        config()->set('sanctum.stateful', ['localhost', 'localhost:5173']);
        $this->withHeader('Referer', 'http://localhost/');
    }

    private function setFlags(bool $totp, bool $email): void
    {
        foreach (['mfa_totp_enabled' => $totp, 'mfa_email_enabled' => $email] as $key => $flag) {
            DB::table('amc_security_settings')->updateOrInsert(['key' => $key], [
                'enabled' => $flag, 'updated_at' => now(),
            ]);
        }
        app(MfaSettings::class)->invalidate();
    }

    private function account(string $role = 'admin', bool $enrolled = false, ?int $customerId = null): User
    {
        if (in_array($role, ['customer_admin', 'customer_user'], true) && ! $customerId) {
            $customerId = Customer::create([
                'company_name' => 'MFA test client '.$role,
                'customer_code' => 'BMFA-'.strtoupper($role),
                'status' => 'active',
            ])->id;
        }
        $user = User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'customer_id' => $customerId,
            'password' => Hash::make('StrongPassword#2026'),
            'mfa_email_enabled' => false,
            'email_verified_at' => null,
        ]);
        if ($enrolled) {
            $user->forceFill([
                'mfa_totp_secret' => app(TotpService::class)->generateSecret(),
                'mfa_totp_confirmed_at' => now(),
            ])->save();
        }
        return $user;
    }

    private function login(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v2/auth/login', [
            'email' => $user->email, 'password' => 'StrongPassword#2026',
        ]);
    }

    private function codes(User $user): array
    {
        return app(MfaService::class)->requiredMethods($user->fresh());
    }

    /** Each role gets a new test session; don't silently reuse another account's guard. */
    private function assertOptionalGoogleLoginWorks(string $role): void
    {
        $this->setFlags(true, false);
        $user = $this->account($role);
        $this->assertSame([], $this->codes($user));
        $this->login($user)->assertOk()->assertJsonPath('status', 'authenticated');
        $response = $this->getJson('/api/v2/auth/me');
        $this->assertSame(200, $response->status(),
            'A password-authenticated '.$role.' account must have a valid session. Body: '.$response->getContent());
        $response->assertJsonPath('user.id', $user->id);
    }

    public function test_optional_google_for_contractor_admin(): void
    {
        $this->assertOptionalGoogleLoginWorks('admin');
    }

    public function test_optional_google_for_technician(): void
    {
        $this->assertOptionalGoogleLoginWorks('technician');
    }

    public function test_optional_google_for_customer_admin(): void
    {
        $this->assertOptionalGoogleLoginWorks('customer_admin');
    }

    public function test_optional_google_for_customer_user(): void
    {
        $this->assertOptionalGoogleLoginWorks('customer_user');
    }

    public function test_email_enabled_is_mandatory_even_when_google_not_enrolled(): void
    {
        $this->setFlags(true, true);
        $user = $this->account('technician');
        $this->assertSame(['email'], $this->codes($user));
        $this->login($user)->assertStatus(202)
            ->assertJsonPath('required_methods.0', 'email')
            ->assertJsonPath('setup_methods.0', 'email');
        $this->getJson('/api/v2/auth/me')->assertUnauthorized();
        // Never allow a pending login to set up OPTIONAL Google Authenticator.
        $this->postJson('/api/v2/auth/mfa/totp/setup')->assertForbidden();
    }

    public function test_enrolled_google_and_enabled_email_require_both_for_business_account(): void
    {
        $this->setFlags(true, true);
        $user = $this->account('admin', true);
        $this->assertSame(['totp', 'email'], $this->codes($user));
        $this->login($user)->assertStatus(202)
            ->assertJsonPath('required_methods.0', 'totp')
            ->assertJsonPath('required_methods.1', 'email');
        $this->getJson('/api/v2/auth/me')->assertUnauthorized();
    }

    public function test_google_disabled_policy_does_not_require_enrolled_google(): void
    {
        $this->setFlags(false, false);
        $user = $this->account('admin', true);
        $this->assertSame([], $this->codes($user));
        $this->login($user)->assertOk();
        $this->getJson('/api/v2/auth/me')->assertOk();
    }

    public function test_admin_required_email_cannot_be_disabled_by_the_user(): void
    {
        $this->setFlags(true, true);
        $user = $this->account('admin', true);
        $user->forceFill(['mfa_email_enabled' => true, 'email_verified_at' => now()])->save();
        $this->actingAs($user, 'web');
        $this->withSession([
            'amc_mfa_verified_user_id' => $user->id,
            'amc_mfa_verified_methods' => ['totp', 'email'],
            'amc_mfa_verified_security_version' => (int) $user->mfa_security_version,
        ]);
        $this->postJson('/api/v2/auth/mfa/disable', [
            'method' => 'email', 'password' => 'StrongPassword#2026', 'code' => '000000',
        ])->assertStatus(422);
        $this->assertTrue((bool) $user->fresh()->mfa_email_enabled);
    }

    public function test_global_disable_overrides_explicit_customer_policy(): void
    {
        $this->setFlags(false, false);
        $customer = Customer::create([
            'company_name' => 'MFA Customer', 'customer_code' => 'MFA-107', 'status' => 'active',
        ]);
        $actor = $this->account('admin');
        DB::table('amc_client_mfa_policies')->updateOrInsert(['customer_id' => $customer->id], [
            'totp_enabled' => true, 'email_enabled' => true, 'require_mfa' => true,
            'updated_by' => $actor->id, 'updated_at' => now(),
        ]);
        $user = $this->account('customer_user', true, $customer->id);
        $this->assertSame([], $this->codes($user));
        $this->assertFalse(app(MfaSettings::class)->totp($user));
        $this->assertFalse(app(MfaSettings::class)->email($user));
    }

    public function test_recovery_codes_cannot_replace_mandatory_email_when_google_policy_disabled(): void
    {
        $this->setFlags(false, true);
        $user = $this->account('admin', true);
        $user->forceFill(['mfa_email_enabled' => true, 'email_verified_at' => now()])->save();
        $codes = app(MfaService::class)->recoveryCodes($user);
        $this->login($user)->assertStatus(202)->assertJsonPath('required_methods.0', 'email');
        $this->postJson('/api/v2/auth/mfa/verify', [
            'method' => 'recovery', 'code' => $codes[0],
        ])->assertForbidden();
        $this->assertCount(8, $user->fresh()->mfa_recovery_codes);
    }

    public function test_admin_assisted_reset_keeps_email_requirement_and_makes_google_optional_again(): void
    {
        $this->setFlags(true, true);
        $user = $this->account('technician', true);
        $user->forceFill(['mfa_email_enabled' => true, 'email_verified_at' => now()])->save();
        app(RecoveryService::class)->resetAuthenticator($user);
        $fresh = $user->fresh();
        $this->assertNull($fresh->mfa_totp_confirmed_at);
        $this->assertTrue((bool) $fresh->mfa_email_enabled);
        $this->assertSame(['email'], $this->codes($fresh));
        $this->login($fresh)->assertStatus(202)->assertJsonPath('required_methods.0', 'email');
    }

    public function test_super_admin_still_requires_technical_totp_even_if_unenrolled(): void
    {
        $user = $this->account('super_admin');
        DB::table('amc_installation_settings')->updateOrInsert(['key' => 'technical_totp_available'], [
            'value' => '1', 'updated_at' => now(),
        ]);
        $this->assertSame(['totp'], $this->codes($user));
        $this->login($user)->assertStatus(202)->assertJsonPath('setup_methods.0', 'totp');
    }
}
