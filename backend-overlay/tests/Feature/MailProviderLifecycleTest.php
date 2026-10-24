<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMfaCompleted;
use App\Models\{AmcMailProvider, Customer, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, DB};
use Tests\TestCase;

final class MailProviderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): AmcMailProvider
    {
        return AmcMailProvider::create(['name' => 'Business provider', 'driver' => 'resend',
            'from_name' => 'Support', 'from_email' => 'support@example.test', 'is_active' => true,
            'credentials' => ['api_key' => 'isolated-secret']]);
    }

    private function actor(string $role = 'admin'): User
    {
        $this->withoutMiddleware(EnsureMfaCompleted::class);
        Auth::forgetGuards();
        $actor = User::factory()->create(['role' => $role, 'status' => 'active', 'password' => 'TestPassword8!']);
        $this->actingAs($actor);
        return $actor;
    }

    public function test_unused_provider_can_be_disabled_and_enabled_with_secrets_preserved_and_actions_audited(): void
    {
        $actor = $this->actor(); $provider = $this->provider();
        foreach ([false, true] as $active) {
            $this->patchJson('/api/v2/admin/mail/providers/'.$provider->id, ['is_active' => $active,
                'current_password' => 'TestPassword8!'])->assertOk()->assertJsonPath('data.is_active', $active)
                ->assertDontSee('isolated-secret');
            $this->assertSame('isolated-secret', $provider->fresh()->credentials['api_key']);
        }
        $this->assertDatabaseHas('activity_logs', ['event' => 'mail.provider.updated', 'user_id' => $actor->id]);
    }

    public function test_delete_requires_password_and_removes_saved_provider_and_credentials(): void
    {
        $actor = $this->actor(); $provider = $this->provider();
        $this->deleteJson('/api/v2/admin/mail/providers/'.$provider->id, ['current_password' => 'wrong'])->assertUnprocessable();
        $this->assertDatabaseHas('amc_mail_providers', ['id' => $provider->id]);
        $this->deleteJson('/api/v2/admin/mail/providers/'.$provider->id, ['current_password' => 'TestPassword8!'])
            ->assertOk()->assertDontSee('isolated-secret');
        $this->assertDatabaseMissing('amc_mail_providers', ['id' => $provider->id]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'mail.provider.deleted', 'user_id' => $actor->id]);
    }

    public function test_default_or_customer_assigned_provider_cannot_be_disabled_or_deleted(): void
    {
        $this->actor(); $provider = $this->provider();
        $customer = Customer::create(['company_name' => 'Mail client', 'customer_code' => 'MAIL-LIFECYCLE']);
        foreach (['default', 'customer'] as $assignment) {
            DB::table('amc_mail_settings')->where('id', 1)->update(['default_provider_id' => $assignment === 'default' ? $provider->id : null]);
            if ($assignment === 'customer') DB::table('amc_client_mail_settings')->insert(['customer_id' => $customer->id, 'provider_id' => $provider->id]);
            $this->patchJson('/api/v2/admin/mail/providers/'.$provider->id, ['is_active' => false,
                'current_password' => 'TestPassword8!'])->assertUnprocessable();
            $this->deleteJson('/api/v2/admin/mail/providers/'.$provider->id, ['current_password' => 'TestPassword8!'])->assertUnprocessable();
            $this->assertTrue($provider->fresh()->is_active);
        }
    }

    public function test_other_roles_cannot_delete_or_change_business_provider_status(): void
    {
        $provider = $this->provider();
        foreach (['super_admin', 'technician', 'customer_admin', 'customer_user'] as $role) {
            $this->actor($role);
            $this->deleteJson('/api/v2/admin/mail/providers/'.$provider->id, ['current_password' => 'TestPassword8!'])->assertForbidden();
            $this->patchJson('/api/v2/admin/mail/providers/'.$provider->id, ['is_active' => false,
                'current_password' => 'TestPassword8!'])->assertForbidden();
        }
        $this->assertTrue($provider->fresh()->is_active);
    }
}
