<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMfaCompleted;
use App\Models\{AmcMailProvider, Customer, User};
use App\Services\Mail\ContractorMailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, DB, Hash, Http};
use Tests\TestCase;

/** Run ONLY against a disposable *_test database; RefreshDatabase is destructive. */
final class ContractorMailMfaV101Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $database = getenv('DB_DATABASE') ?: ($_SERVER['DB_DATABASE'] ?? '');
        if (! str_ends_with((string) $database, '_test')) {
            throw new \RuntimeException('Refusing destructive tests without a *_test database.');
        }
        parent::setUp();
        $this->withoutMiddleware(EnsureMfaCompleted::class);
    }

    private function account(string $role, ?Customer $customer = null): User
    {
        return User::factory()->create([
            'role' => $role, 'customer_id' => $customer?->id, 'status' => 'active',
            'password' => Hash::make('SecurePassword#2026'),
        ]);
    }

    private function providerPayload(string $name, string $key): array
    {
        return [
            'name' => $name, 'driver' => 'resend',
            'from_email' => 'notices@example.test', 'from_name' => 'AMC Contractor',
            'credentials' => ['api_key' => $key],
            'current_password' => 'SecurePassword#2026',
        ];
    }

    public function test_only_contractor_admin_can_manage_email_providers_and_secrets_are_encrypted(): void
    {
        foreach (['super_admin', 'customer_admin', 'customer_user', 'technician'] as $role) {
            $this->actingAs($this->account($role))
                ->getJson('/api/v2/admin/mail/providers')->assertForbidden();
            $this->postJson('/api/v2/admin/mail/providers', $this->providerPayload('Denied', 'secret-denied-1234'))
                ->assertForbidden();
        }
        $admin = $this->account('admin');
        $this->actingAs($admin);
        $this->postJson('/api/v2/admin/mail/providers', $this->providerPayload('Main', 'secret-main-1234'))
            ->assertCreated()->assertJsonPath('success', true)
            ->assertJsonMissing(['api_key' => 'secret-main-1234']);
        $provider = AmcMailProvider::firstOrFail();
        $this->assertSame('secret-main-1234', $provider->credentials['api_key']);
        $this->assertStringNotContainsString('secret-main-1234', DB::table('amc_mail_providers')->first()->credentials);
        $this->getJson('/api/v2/admin/mail/providers')->assertOk()->assertDontSee('secret-main-1234');
        $this->assertDatabaseHas('activity_logs', ['event' => 'mail.provider.created', 'user_id' => $admin->id]);
    }

    public function test_default_and_client_provider_are_routed_independently(): void
    {
        Http::fake(['https://api.resend.com/*' => Http::response(['id' => 'mail-accepted'], 200)]);
        $admin = $this->account('admin');
        $customerA = Customer::create(['company_name' => 'Client A', 'customer_code' => 'MAIL-A', 'status' => 'active']);
        $customerB = Customer::create(['company_name' => 'Client B', 'customer_code' => 'MAIL-B', 'status' => 'active']);
        $this->actingAs($admin);
        $first = $this->postJson('/api/v2/admin/mail/providers', $this->providerPayload('Default', 'secret-default-1234'))
            ->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v2/admin/mail/providers', $this->providerPayload('Client A', 'secret-client-1234'))
            ->assertCreated()->json('data.id');
        $this->putJson('/api/v2/admin/mail/default', [
            'default_provider_id' => $first, 'notifications_enabled' => true,
            'notification_email' => 'shared@example.test',
            'current_password' => 'SecurePassword#2026',
        ])->assertOk();
        $this->putJson('/api/v2/admin/mail/clients/'.$customerA->id, [
            'provider_id' => $second, 'notifications_enabled' => true,
            'current_password' => 'SecurePassword#2026',
        ])->assertOk();
        $mail = app(ContractorMailService::class);
        $this->assertSame($second, $mail->providerFor($customerA->id)->id);
        $this->assertSame($first, $mail->providerFor($customerB->id)->id);
        $mail->sendNotification($this->account('customer_user', $customerA), 'Assignment', 'Ticket update');
        $mail->sendNotification($this->account('customer_user', $customerB), 'Assignment', 'Ticket update');
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-client-1234'));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-default-1234'));
    }

    public function test_client_mfa_requires_delivery_and_users_create_own_unique_totp_secrets(): void
    {
        // Contractor Admin first enables the installation-wide MFA availability.
        foreach (['mfa_totp_enabled' => true, 'mfa_email_enabled' => true] as $key => $enabled) {
            \Illuminate\Support\Facades\DB::table('amc_security_settings')
                ->updateOrInsert(['key' => $key], ['enabled' => $enabled, 'updated_at' => now()]);
        }
        app(\App\Services\Auth\MfaSettings::class)->invalidate();
        $customer = Customer::create(['company_name' => 'Client C', 'customer_code' => 'MAIL-C', 'status' => 'active']);
        $admin = $this->account('admin');
        $customerAdmin = $this->account('customer_admin', $customer);
        $customerUser = $this->account('customer_user', $customer);
        $this->actingAs($admin)->putJson('/api/v2/admin/client-mfa/customers/'.$customer->id, [
            'totp_enabled' => true, 'email_enabled' => true, 'require_mfa' => false,
            'current_password' => 'SecurePassword#2026',
        ])->assertUnprocessable();
        $this->putJson('/api/v2/admin/client-mfa/customers/'.$customer->id, [
            'totp_enabled' => true, 'email_enabled' => false, 'require_mfa' => false,
            'current_password' => 'SecurePassword#2026',
        ])->assertOk();

        // TOTP enrollment is a web-session route. The preceding policy request uses
        // auth:sanctum, so explicitly select the web guard when switching accounts.
        $this->assertTrue(app(\App\Services\Auth\MfaSettings::class)->totp($customerAdmin));
        $this->assertTrue(app(\App\Services\Auth\MfaSettings::class)->totp($customerUser));

        $first = $this->actingAs($customerAdmin, 'web')->postJson('/api/v2/auth/mfa/totp/setup', [
            'password' => 'SecurePassword#2026',
        ])->assertOk()->json('secret');
        $second = $this->actingAs($customerUser, 'web')->postJson('/api/v2/auth/mfa/totp/setup', [
            'password' => 'SecurePassword#2026',
        ])->assertOk()->json('secret');
        $this->assertNotEmpty($first);
        $this->assertNotSame($first, $second);
        // Sanctum's request guard may cache the earlier Admin user in this test
        // process. Clear the test guard instances when changing principals, then
        // authenticate the Customer Admin through the normal web session guard.
        // Never relax the production controller's admin-only authorization.
        Auth::forgetGuards();
        $this->actingAs($customerAdmin, 'web')
            ->getJson('/api/v2/admin/mail/providers')
            ->assertForbidden();
    }

    public function test_customer_admin_cannot_access_email_provider_settings_in_own_session(): void
    {
        $customer = Customer::create([
            'company_name' => 'Client Restricted',
            'customer_code' => 'MAIL-RESTRICTED',
            'status' => 'active',
        ]);
        $customerAdmin = $this->account('customer_admin', $customer);
        $this->actingAs($customerAdmin, 'web')
            ->getJson('/api/v2/admin/mail/providers')
            ->assertForbidden();
    }

    public function test_enabled_global_authenticator_is_available_to_client_users_until_admin_overrides(): void
    {
        $customer = Customer::create([
            'company_name' => 'Client D', 'customer_code' => 'MAIL-D', 'status' => 'active',
        ]);
        $admin = $this->account('admin');
        $customerAdmin = $this->account('customer_admin', $customer);
        $customerUser = $this->account('customer_user', $customer);
        $this->actingAs($admin)->patchJson('/api/v2/admin/security/mfa', [
            'totp_enabled' => true, 'current_password' => 'SecurePassword#2026',
        ])->assertOk();
        $settings = app(\App\Services\Auth\MfaSettings::class);
        $this->assertTrue($settings->totp($admin));
        $this->assertTrue($settings->totp($customerAdmin));
        $this->assertTrue($settings->totp($customerUser));
        $this->actingAs($admin)->putJson('/api/v2/admin/client-mfa/customers/'.$customer->id, [
            'totp_enabled' => false, 'email_enabled' => false, 'require_mfa' => false,
            'current_password' => 'SecurePassword#2026',
        ])->assertOk();
        $this->assertFalse(app(\App\Services\Auth\MfaSettings::class)->totp($customerAdmin));
        $this->assertTrue(app(\App\Services\Auth\MfaSettings::class)->totp($admin));
    }

}
