<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMfaCompleted;
use App\Models\{TechnicalMailSetting, User};
use App\Services\Auth\MfaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, DB, Hash, Http};
use Tests\TestCase;

/** This test resets tables: never run it on a non-test database. */
final class TechnicalSettingsV103Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $database = getenv('DB_DATABASE') ?: ($_SERVER['DB_DATABASE'] ?? '');
        if (!str_ends_with((string) $database, '_test')) {
            throw new \RuntimeException('Refusing destructive tests: select the dedicated *_test database.');
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
            'password' => Hash::make('TestPassword#2026'),
        ]);
    }

    public function test_only_super_admin_configures_technical_mail_and_personal_mfa(): void
    {
        foreach (['admin', 'customer_admin', 'technician', 'customer_user'] as $role) {
            Auth::forgetGuards();
            $this->actingAs($this->account($role), 'web');
            $this->getJson('/api/v2/system/security')->assertForbidden();
            $this->putJson('/api/v2/system/security/mfa', [
                'totp_enabled' => true,
                'current_password' => 'TestPassword#2026',
            ])->assertForbidden();
            $this->getJson('/api/v2/system/support/timezones?country=IN')->assertForbidden();
        }
        Auth::forgetGuards();
        $super = $this->account('super_admin');
        $this->actingAs($super, 'web');
        $this->getJson('/api/v2/system/security')->assertOk()
            ->assertJsonPath('data.mfa.totp_enabled', false);
        $this->putJson('/api/v2/system/security/mfa', [
            'totp_enabled' => true,
            'current_password' => 'TestPassword#2026',
        ])->assertOk()->assertJsonPath('data.totp_enabled', true);
        $this->assertTrue(app(MfaSettings::class)->totp($super));
        $this->assertFalse(app(MfaSettings::class)->totp($this->account('admin')));
    }

    public function test_technical_mail_credentials_are_encrypted_and_never_exposed(): void
    {
        $super = $this->account('super_admin');
        $this->actingAs($super, 'web');
        $this->putJson('/api/v2/system/security/mail', [
            'enabled' => true,
            'driver' => 'resend',
            'from_email' => 'support@example.test',
            'from_name' => 'Technical Support',
            'credentials' => ['api_key' => 'private-v103-test-token'],
            'current_password' => 'TestPassword#2026',
        ])->assertOk()->assertJsonPath('data.credential_configured', true)
            ->assertDontSee('private-v103-test-token');
        $this->assertSame('private-v103-test-token', TechnicalMailSetting::firstOrFail()->credentials['api_key']);
        $this->assertStringNotContainsString(
            'private-v103-test-token',
            DB::table('amc_technical_mail_settings')->first()->credentials
        );
        $this->getJson('/api/v2/system/security')->assertOk()->assertDontSee('private-v103-test-token');

        Http::fake(['https://api.resend.com/*' => Http::response(['id' => 'accepted'], 200)]);
        $this->postJson('/api/v2/system/security/mail/test', [
            'current_password' => 'TestPassword#2026',
        ])->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn($request) => $request->hasHeader('Authorization', 'Bearer private-v103-test-token'));
    }

    public function test_technical_email_otp_requires_a_provider_and_cannot_be_silently_disabled(): void
    {
        $super = $this->account('super_admin');
        $this->actingAs($super, 'web');
        $this->putJson('/api/v2/system/security/mfa', [
            'email_enabled' => true,
            'current_password' => 'TestPassword#2026',
        ])->assertUnprocessable();
        $this->putJson('/api/v2/system/security/mail', [
            'enabled' => true,
            'driver' => 'resend',
            'from_email' => 'support@example.test',
            'from_name' => 'Technical Support',
            'credentials' => ['api_key' => 'private-test-token'],
            'current_password' => 'TestPassword#2026',
        ])->assertOk();
        $this->putJson('/api/v2/system/security/mfa', [
            'email_enabled' => true,
            'current_password' => 'TestPassword#2026',
        ])->assertOk();
        $this->putJson('/api/v2/system/security/mail', [
            'enabled' => false,
            'driver' => 'resend',
            'from_email' => 'support@example.test',
            'from_name' => 'Technical Support',
            'current_password' => 'TestPassword#2026',
        ])->assertUnprocessable();
    }

    public function test_world_timezone_is_validated_and_available_to_all_authenticated_roles(): void
    {
        $super = $this->account('super_admin');
        $this->actingAs($super, 'web');
        $response = $this->getJson('/api/v2/system/support/timezones?country=IN')
            ->assertOk();

        $this->assertContains(
            'Asia/Kolkata',
            $response->json('data')
        );
        $payload = [
            'installation_label' => 'Contractor A',
            'support_email' => '',
            'queue_warning_depth' => 20,
            'disk_warning_percent' => 15,
            'country_code' => 'IN',
            'timezone' => 'Europe/London',
            'current_password' => 'TestPassword#2026',
        ];
        $this->putJson('/api/v2/system/support/settings', $payload)->assertUnprocessable();
        $this->putJson('/api/v2/system/support/settings', [
            ...$payload,
            'timezone' => 'Asia/Kolkata',
        ])->assertOk()->assertJsonPath('data.timezone', 'Asia/Kolkata');
        $this->assertDatabaseHas('amc_installation_settings', [
            'key' => 'display_timezone',
            'value' => 'Asia/Kolkata',
        ]);
        $this->actingAs($this->account('admin'), 'web')
            ->getJson('/api/v2/installation/timezone')->assertOk()
            ->assertJsonPath('data.timezone', 'Asia/Kolkata');
    }
}
