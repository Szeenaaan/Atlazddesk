<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMfaCompleted;
use App\Jobs\SendAccountEmail;
use App\Models\{AmcMailProvider, TechnicalMailSetting, User};
use App\Services\Mail\SmtpCredentialVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Queue};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SmtpConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        $this->withoutMiddleware(EnsureMfaCompleted::class);
        Queue::fake();
        $user = User::factory()->create(['role' => $role, 'status' => 'active', 'password' => 'TestPassword8!']);
        $this->actingAs($user);
        return $user;
    }

    private function credentials(): array
    {
        return ['host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'account', 'password' => 'saved-secret'];
    }

    private function provider(bool $active = true): AmcMailProvider
    {
        return AmcMailProvider::create(['name' => 'Business', 'driver' => 'smtp', 'from_email' => 'support@example.test',
            'from_name' => 'Support', 'is_active' => $active, 'credentials' => $this->credentials()]);
    }

    private function reject(): void
    {
        $this->mock(SmtpCredentialVerifier::class)->shouldReceive('verify')->once()->andThrow(
            ValidationException::withMessages(['credentials' => ['The email provider rejected the SMTP username or password. Check your credentials or app password and try again.']])
        );
    }

    public function test_failed_authentication_does_not_save_a_provider(): void
    {
        $this->actor(); $this->reject();
        $this->postJson('/api/v2/admin/mail/providers', [
            'name' => 'Business', 'driver' => 'smtp', 'from_email' => 'support@example.test', 'from_name' => 'Support',
            'credentials' => $this->credentials(), 'current_password' => 'TestPassword8!',
        ])->assertUnprocessable()->assertJsonValidationErrors('credentials')->assertDontSee('saved-secret');
        $this->assertDatabaseCount('amc_mail_providers', 0);
    }

    public function test_failed_update_preserves_the_existing_secret_and_connection(): void
    {
        $this->actor(); $provider = $this->provider(); $this->reject();
        $this->patchJson('/api/v2/admin/mail/providers/'.$provider->id, [
            'credentials' => ['username' => 'wrong-account', 'password' => 'wrong-secret'], 'current_password' => 'TestPassword8!',
        ])->assertUnprocessable()->assertDontSee('wrong-secret');
        $this->assertSame($this->credentials(), $provider->fresh()->credentials);
    }

    public function test_success_verifies_merged_credentials_and_preserves_a_blank_secret(): void
    {
        $this->actor(); $provider = $this->provider();
        $this->mock(SmtpCredentialVerifier::class)->shouldReceive('verify')->once()
            ->with(\Mockery::on(fn ($credentials) => $credentials['username'] === 'new-account' && $credentials['password'] === 'saved-secret'))->andReturnNull();
        $this->patchJson('/api/v2/admin/mail/providers/'.$provider->id, [
            'credentials' => ['username' => 'new-account', 'password' => ''], 'current_password' => 'TestPassword8!',
        ])->assertOk()->assertDontSee('saved-secret');
        $this->assertSame('new-account', $provider->fresh()->credentials['username']);
    }

    public function test_failed_verification_cannot_enable_an_inactive_provider(): void
    {
        $this->actor(); $provider = $this->provider(false); $this->reject();
        $this->patchJson('/api/v2/admin/mail/providers/'.$provider->id, [
            'is_active' => true, 'current_password' => 'TestPassword8!',
        ])->assertUnprocessable();
        $this->assertFalse($provider->fresh()->is_active);
    }

    public function test_failed_verification_cannot_select_the_default_or_queue_a_test(): void
    {
        $this->actor(); $provider = $this->provider();
        $this->mock(SmtpCredentialVerifier::class)->shouldReceive('verify')->twice()->andThrow(
            ValidationException::withMessages(['credentials' => ['SMTP verification failed.']])
        );
        $this->putJson('/api/v2/admin/mail/default', ['default_provider_id' => $provider->id,
            'notifications_enabled' => false, 'current_password' => 'TestPassword8!'])->assertUnprocessable();
        $this->assertNull(DB::table('amc_mail_settings')->where('id', 1)->value('default_provider_id'));
        $this->postJson('/api/v2/admin/mail/providers/'.$provider->id.'/test', ['current_password' => 'TestPassword8!'])->assertUnprocessable();
        Queue::assertNotPushed(SendAccountEmail::class);
    }

    public function test_technical_configuration_also_preserves_saved_settings_on_failure(): void
    {
        $this->actor('super_admin');
        TechnicalMailSetting::create(['id' => 1, 'enabled' => true, 'driver' => 'smtp',
            'from_name' => 'Technical', 'from_email' => 'technical@example.test', 'credentials' => $this->credentials()]);
        $this->reject();
        $this->putJson('/api/v2/system/security/mail', ['enabled' => true, 'driver' => 'smtp',
            'from_name' => 'Technical', 'from_email' => 'technical@example.test',
            'credentials' => array_merge($this->credentials(), ['password' => 'wrong-secret']), 'current_password' => 'TestPassword8!',
        ])->assertUnprocessable()->assertDontSee('wrong-secret');
        $this->assertSame('saved-secret', TechnicalMailSetting::findOrFail(1)->credentials['password']);
    }

    public function test_failed_technical_smtp_verification_does_not_queue_a_test_message(): void
    {
        $this->actor('super_admin');
        TechnicalMailSetting::create(['id' => 1, 'enabled' => true, 'driver' => 'smtp',
            'from_name' => 'Technical', 'from_email' => 'technical@example.test', 'credentials' => $this->credentials()]);
        $this->reject();
        $this->postJson('/api/v2/system/security/mail/test', ['current_password' => 'TestPassword8!'])->assertUnprocessable();
        Queue::assertNotPushed(SendAccountEmail::class);
    }

    public function test_technical_edit_returns_connection_fields_and_reverifies_the_preserved_secret(): void
    {
        $this->actor('super_admin');
        TechnicalMailSetting::create(['id' => 1, 'enabled' => true, 'driver' => 'smtp',
            'from_name' => 'Technical', 'from_email' => 'technical@example.test', 'credentials' => $this->credentials()]);
        $this->getJson('/api/v2/system/security')->assertOk()->assertJsonPath('data.mail.smtp.host', 'smtp.example.test')
            ->assertDontSee('saved-secret');
        $this->mock(SmtpCredentialVerifier::class)->shouldReceive('verify')->once()
            ->with(\Mockery::on(fn ($credentials) => $credentials['username'] === 'new-account' && $credentials['password'] === 'saved-secret'))->andReturnNull();
        $this->putJson('/api/v2/system/security/mail', ['enabled' => true, 'driver' => 'smtp',
            'from_name' => 'Technical', 'from_email' => 'technical@example.test',
            'credentials' => ['username' => 'new-account', 'password' => ''], 'current_password' => 'TestPassword8!',
        ])->assertOk()->assertDontSee('saved-secret');
        $this->assertSame('new-account', TechnicalMailSetting::findOrFail(1)->credentials['username']);
    }
}
