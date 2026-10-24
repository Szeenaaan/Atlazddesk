<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\{AccountSession, MfaService, MfaSettings};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\{ArraySessionHandler, Store};
use Illuminate\Support\Facades\{Auth, Cache, DB};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class AccountSessionTest extends TestCase
{
    use RefreshDatabase;

    private function browser(): Request
    {
        $request = Request::create('/api/v2/auth/me');
        $session = new Store('account-session-test', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);
        return $request;
    }

    private function account(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function expectHttp(int $status, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected session access to be rejected.');
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    public function test_other_browser_is_blocked_owner_survives_rotation_and_logout_releases(): void
    {
        $service = app(AccountSession::class);
        $user = $this->account();
        $first = $this->browser();
        $second = $this->browser();
        $service->claim($first, $user);
        $this->expectHttp(409, fn () => $service->claim($second, $user));
        $first->session()->regenerate();
        $service->validateAndTouch($first, $user);
        // An unauthenticated/non-owner logout must not free another browser's claim.
        $service->release($second, $user);
        $this->expectHttp(409, fn () => $service->claim($second, $user));
        $service->release($first, $user);
        $service->claim($second, $user);
        $service->validateAndTouch($second, $user);
        $this->assertTrue($second->session()->has('amc_account_session_token'));
    }

    public function test_idle_expiry_allows_new_login_but_old_browser_cannot_return(): void
    {
        $service = app(AccountSession::class);
        $user = $this->account();
        $first = $this->browser();
        $second = $this->browser();
        $service->claim($first, $user);
        $this->travel(181)->seconds();
        $service->claim($second, $user);
        $this->expectHttp(401, fn () => $service->validateAndTouch($first, $user));
        $service->validateAndTouch($second, $user);
    }

    public function test_request_activity_extends_idle_expiry(): void
    {
        $service = app(AccountSession::class);
        $user = $this->account();
        $first = $this->browser();
        $service->claim($first, $user);
        $this->travel(100)->seconds();
        $service->validateAndTouch($first, $user);
        $this->travel(100)->seconds();
        $this->expectHttp(409, fn () => $service->claim($this->browser(), $user));
    }

    public function test_missing_claim_fails_closed_and_security_reset_releases_account(): void
    {
        $service = app(AccountSession::class);
        $user = $this->account();
        $first = $this->browser();
        $service->claim($first, $user);
        Cache::forget('amc:account-session:'.$user->id.':0');
        $this->expectHttp(401, fn () => $service->validateAndTouch($first, $user));
        $second = $this->browser();
        $service->claim($second, $user);
        $user->forceFill(['mfa_security_version' => 1])->save();
        $service->claim($this->browser(), $user);
        $this->expectHttp(401, fn () => $service->validateAndTouch($second, $user));
    }

    public function test_disabled_account_cannot_claim(): void
    {
        $user = $this->account();
        $user->update(['status' => 'inactive']);
        $this->expectHttp(401, fn () => app(AccountSession::class)->claim($this->browser(), $user));
    }

    public function test_login_endpoint_rejects_second_browser_and_keeps_response_private(): void
    {
        foreach (['mfa_totp_enabled', 'mfa_email_enabled'] as $key) {
            DB::table('amc_security_settings')->updateOrInsert(['key' => $key], ['enabled' => false, 'updated_at' => now()]);
        }
        app(MfaSettings::class)->invalidate();
        $user = $this->account();
        $user->password = 'StrongPassword#2026';
        $user->save();
        app(AccountSession::class)->claim($this->browser(), $user);
        $this->withHeader('Referer', 'http://localhost/')->postJson('/api/v2/auth/login', [
            'email' => $user->email, 'password' => 'StrongPassword#2026',
        ])->assertStatus(409)->assertJsonPath('message',
            'Someone is already signed in to your account. Sign out on the other device or wait for the session to expire.');
        $this->assertGuest('web');
    }

    public function test_final_mfa_completion_cannot_replace_an_existing_claim(): void
    {
        foreach (['mfa_totp_enabled', 'mfa_email_enabled'] as $key) {
            DB::table('amc_security_settings')->updateOrInsert(['key' => $key], ['enabled' => $key === 'mfa_email_enabled', 'updated_at' => now()]);
        }
        app(MfaSettings::class)->invalidate();
        $user = $this->account();
        $service = app(AccountSession::class);
        $first = $this->browser();
        $service->claim($first, $user);
        $second = $this->browser();
        $second->session()->put('amc_mfa_pending_user_id', $user->id);
        $second->session()->put('amc_mfa_verified_factors', ['email']);
        $this->expectHttp(409, fn () => app(MfaService::class)->finish($second, $user, true));
        $this->assertGuest('web');
        $service->validateAndTouch($first, $user);
    }

    public function test_account_deactivation_is_checked_before_business_route(): void
    {
        $user = $this->account();
        $this->actingAs($user);
        User::whereKey($user->id)->update(['status' => 'inactive']);
        $this->getJson('/api/v2/bootstrap')->assertForbidden();
    }

    public function test_authenticated_responses_cannot_be_cached(): void
    {
        $this->actingAs($this->account())->getJson('/api/v2/auth/me')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
    }

    public function test_authenticated_cookie_session_with_wrong_claim_is_rejected_by_middleware(): void
    {
        $user = $this->account();
        app(AccountSession::class)->claim($this->browser(), $user);
        $this->withHeader('Referer', 'http://localhost/')->actingAs($user)->withSession([
            Auth::guard('web')->getName() => $user->id,
            'amc_account_session_token' => 'another-browser-token',
        ])->getJson('/api/v2/auth/me')->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_owning_cookie_session_can_access_and_logout_releases_claim(): void
    {
        $user = $this->account();
        $browser = $this->browser();
        $service = app(AccountSession::class);
        $service->claim($browser, $user);
        $this->withHeader('Referer', 'http://localhost/')->actingAs($user)->withSession([
            Auth::guard('web')->getName() => $user->id,
            'amc_account_session_token' => $browser->session()->get('amc_account_session_token'),
        ])->getJson('/api/v2/auth/me')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->postJson('/api/v2/auth/logout')->assertOk();
        $this->assertGuest('web');
        $this->assertNull(Cache::get('amc:account-session:'.$user->id.':0'));
        $service->claim($this->browser(), $user);
    }

    public function test_pending_mfa_does_not_reserve_account_before_verification(): void
    {
        foreach (['mfa_totp_enabled', 'mfa_email_enabled'] as $key) {
            DB::table('amc_security_settings')->updateOrInsert(['key' => $key], ['enabled' => $key === 'mfa_email_enabled', 'updated_at' => now()]);
        }
        app(MfaSettings::class)->invalidate();
        $user = $this->account();
        $user->password = 'StrongPassword#2026';
        $user->save();
        $this->withHeader('Referer', 'http://localhost/')->postJson('/api/v2/auth/login', [
            'email' => $user->email, 'password' => 'StrongPassword#2026',
        ])->assertStatus(202);
        $this->assertGuest('web');
        $this->assertNull(Cache::get('amc:account-session:'.$user->id.':0'));
    }

    public function test_claim_cache_write_failure_cannot_authenticate_user(): void
    {
        foreach (['mfa_totp_enabled', 'mfa_email_enabled'] as $key) {
            DB::table('amc_security_settings')->updateOrInsert(['key' => $key], ['enabled' => false, 'updated_at' => now()]);
        }
        app(MfaSettings::class)->invalidate();
        $user = $this->account();
        $key = 'amc:account-session:'.$user->id.':0';
        Cache::shouldReceive('get')->once()->with($key)->andReturn(null);
        Cache::shouldReceive('put')->once()->andReturn(false);
        $this->expectHttp(503, fn () => app(MfaService::class)->finish($this->browser(), $user));
        $this->assertGuest('web');
    }

    public function test_role_idle_limits_expire_without_extending_on_background_requests(): void
    {
        $service = app(AccountSession::class);
        foreach (['super_admin' => 60, 'admin' => 180, 'customer_admin' => 600, 'customer_user' => 600, 'technician' => 600] as $role => $seconds) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            $browser = $this->browser();
            $service->claim($browser, $user);
            $this->travel($seconds - 1)->seconds();
            $service->validateAndTouch($browser, $user, false);
            $this->travel(1)->seconds();
            $this->expectHttp(401, fn () => $service->validateAndTouch($browser, $user, false));
            // Another browser can log in after expiry; the previous one stays invalid.
            $service->claim($this->browser(), $user);
            $this->expectHttp(401, fn () => $service->validateAndTouch($browser, $user, false));
        }
    }

    public function test_activity_endpoint_renews_but_polling_does_not_and_expiry_logs_out(): void
    {
        $user = $this->account();
        $browser = $this->browser();
        app(AccountSession::class)->claim($browser, $user);
        $this->withHeader('Referer', 'http://localhost/')->actingAs($user)->withSession([
            Auth::guard('web')->getName() => $user->id,
            'amc_account_session_token' => $browser->session()->get('amc_account_session_token'),
        ]);
        $this->travel(100)->seconds();
        $this->postJson('/api/v2/auth/activity')->assertNoContent();
        $this->travel(100)->seconds();
        $this->getJson('/api/v2/auth/me')->assertOk();
        $this->travel(80)->seconds();
        $this->getJson('/api/v2/auth/me')->assertUnauthorized();
        $this->assertGuest('web');
        $this->postJson('/api/v2/auth/activity')->assertUnauthorized();
    }
}
