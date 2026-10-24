<?php

namespace Tests\Feature;

use App\Jobs\SendAccountEmail;
use App\Models\{AmcMailProvider, User};
use App\Services\Auth\MfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\{ArraySessionHandler, Store};
use Illuminate\Support\Facades\{Crypt, DB, Hash, Http};
use Tests\TestCase;

final class QueuedAccountEmailTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(): array
    {
        config(['amc.account_mail_queue_connection' => 'database']);
        Http::fake(['https://api.resend.com/*' => Http::response(['id' => 'accepted'])]);
        DB::table('amc_security_settings')->updateOrInsert(['key' => 'mfa_email_enabled'], ['enabled' => true]);
        $provider = AmcMailProvider::create(['name' => 'Business', 'driver' => 'resend', 'from_email' => 'sender@example.test',
            'from_name' => 'Support', 'credentials' => ['api_key' => 'private-provider-key'], 'is_active' => true]);
        DB::table('amc_mail_settings')->update(['default_provider_id' => $provider->id]);
        $user = User::factory()->create(['role' => 'admin', 'status' => 'active', 'email_verified_at' => now()]);
        $request = Request::create('/api/v2/auth/mfa/email/send');
        $session = new Store('queued-otp-test', new ArraySessionHandler(120));
        $session->start(); $request->setLaravelSession($session);
        return [$user, $request];
    }

    private function queued(int $offset = 0): SendAccountEmail
    {
        $payload = json_decode(DB::table('jobs')->orderBy('id')->offset($offset)->value('payload'), true);
        return unserialize(Crypt::decrypt($payload['data']['command']));
    }

    public function test_otp_is_encrypted_and_smtp_is_not_called_during_request_and_verification_stays_synchronous(): void
    {
        [$user, $request] = $this->prepare();
        $mfa = app(MfaService::class);
        $mfa->sendEmail($request, $user, 'login');
        Http::assertNothingSent(); $this->assertGuest();
        $job = $this->queued();
        $this->assertTrue(Hash::check($job->code, $request->session()->get('amc_mfa_otp_hash')));
        $payload = DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString($job->code, $payload);
        $this->assertStringNotContainsString($user->email, $payload);
        $this->assertStringNotContainsString('private-provider-key', $payload);
        $this->assertDatabaseHas('jobs', ['queue' => 'security']);
        $job->handle(); $job->handle();
        Http::assertSentCount(1);
        Http::assertSent(fn ($outbound) => $outbound['to'] === [$user->email]);
        $this->assertGuest();
        $this->assertTrue($mfa->consumeEmail($request, $job->code, 'login'));
        $this->assertFalse($mfa->consumeEmail($request, $job->code, 'login'));
    }

    public function test_resend_cancels_old_delivery_and_expired_codes_are_not_emailed(): void
    {
        [$user, $request] = $this->prepare();
        $mfa = app(MfaService::class);
        $mfa->sendEmail($request, $user, 'login');
        $old = $this->queued();
        $request->session()->put('amc_mfa_otp_sent_at', time() - 61);
        $mfa->sendEmail($request, $user, 'login');
        $latest = $this->queued(1);
        $old->handle(); Http::assertNothingSent();
        $this->travel(301)->seconds();
        $latest->handle(); Http::assertNothingSent();
    }

    public function test_changed_account_or_disabled_email_policy_prevents_delayed_delivery(): void
    {
        [$user, $request] = $this->prepare();
        app(MfaService::class)->sendEmail($request, $user, 'login');
        $job = $this->queued();
        $user->update(['email' => 'changed@example.test']);
        $job->handle(); Http::assertNothingSent();
        $user->update(['email' => $job->email]);
        DB::table('amc_security_settings')->where('key', 'mfa_email_enabled')->update(['enabled' => false]);
        $job->handle(); Http::assertNothingSent();
    }
}
