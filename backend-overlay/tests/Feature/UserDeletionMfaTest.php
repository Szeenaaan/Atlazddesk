<?php

namespace Tests\Feature;

use App\Jobs\SendAccountEmail;
use App\Models\{AmcMailProvider, User};
use App\Services\Auth\{MfaSettings, TotpService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, Crypt, DB, Hash};
use Tests\TestCase;

final class UserDeletionMfaTest extends TestCase
{
    use RefreshDatabase;

    private function flags(bool $totp, bool $email): void
    {
        foreach (['mfa_totp_enabled' => $totp, 'mfa_email_enabled' => $email] as $key => $enabled) {
            DB::table('amc_security_settings')->updateOrInsert(['key' => $key], ['enabled' => $enabled]);
        }
        app(MfaSettings::class)->invalidate();
    }

    private function actor(bool $totp = false, bool $email = false): User
    {
        $user = User::factory()->create(['role' => 'admin', 'status' => 'active',
            'email_verified_at' => $email ? now() : null, 'mfa_email_enabled' => $email]);
        if ($totp) $user->forceFill(['mfa_totp_secret' => app(TotpService::class)->generateSecret(),
            'mfa_totp_confirmed_at' => now(), 'mfa_last_totp_step' => null])->save();
        $this->flags($totp, $email);
        config()->set('sanctum.stateful', ['localhost', '127.0.0.1']);
        $this->withHeader('Referer', 'http://localhost/')->actingAs($user)->withSession([
            Auth::guard('web')->getName() => $user->id,
            'amc_mfa_verified_user_id' => $user->id,
            'amc_mfa_verified_security_version' => (int) $user->mfa_security_version,
            'amc_mfa_verified_methods' => array_values(array_filter([$totp ? 'totp' : null, $email ? 'email' : null])),
        ]);
        return $user;
    }

    private function code(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $character) $bits .= str_pad(decbin(strpos($alphabet, $character)), 5, '0', STR_PAD_LEFT);
        $bytes = '';
        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $octet) $bytes .= chr(bindec($octet));
        $step = intdiv(time(), 30);
        $digest = hash_hmac('sha1', pack('N2', 0, $step), $bytes, true);
        $offset = ord($digest[19]) & 0x0f;
        $binary = ((ord($digest[$offset]) & 0x7f) << 24) | ((ord($digest[$offset + 1]) & 0xff) << 16)
            | ((ord($digest[$offset + 2]) & 0xff) << 8) | (ord($digest[$offset + 3]) & 0xff);
        return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function mail(): void
    {
        config(['amc.account_mail_queue_connection' => 'database']);
        $provider = AmcMailProvider::create(['name' => 'Test provider', 'driver' => 'resend',
            'from_email' => 'sender@example.test', 'from_name' => 'Support',
            'credentials' => ['api_key' => 'test-key'], 'is_active' => true]);
        DB::table('amc_mail_settings')->update(['default_provider_id' => $provider->id]);
    }

    private function queuedCode(): string
    {
        $payload = json_decode(DB::table('jobs')->orderByDesc('id')->value('payload'), true);
        /** @var SendAccountEmail $job */
        $job = unserialize(Crypt::decrypt($payload['data']['command']));
        return $job->code;
    }

    public function test_no_enabled_factor_allows_direct_delete(): void
    {
        $this->actor();
        $target = User::factory()->create(['role' => 'customer_user']);
        $this->deleteJson('/api/v2/users/'.$target->id)->assertNoContent();
        $this->assertSoftDeleted('users', ['id' => $target->id]);
    }

    public function test_authenticator_code_is_required_and_cannot_be_replayed(): void
    {
        $actor = $this->actor(totp: true);
        $first = User::factory()->create(['role' => 'technician']);
        $second = User::factory()->create(['role' => 'technician']);
        $this->deleteJson('/api/v2/users/'.$first->id)->assertUnprocessable()->assertJsonValidationErrors('totp_code');
        $this->deleteJson('/api/v2/users/'.$first->id, ['totp_code' => '000000'])->assertUnprocessable();
        $code = $this->code($actor->mfa_totp_secret);
        $this->deleteJson('/api/v2/users/'.$first->id, ['totp_code' => $code])->assertNoContent();
        $this->deleteJson('/api/v2/users/'.$second->id, ['totp_code' => $code])->assertUnprocessable();
        $this->assertNotSoftDeleted('users', ['id' => $second->id]);
    }

    public function test_email_code_is_required_and_bound_to_selected_user(): void
    {
        $this->mail();
        $this->actor(email: true);
        $first = User::factory()->create(['role' => 'customer_user']);
        $second = User::factory()->create(['role' => 'customer_user']);
        $this->postJson('/api/v2/users/'.$first->id.'/deletion/otp')->assertOk();
        $code = $this->queuedCode();
        $this->deleteJson('/api/v2/users/'.$second->id, ['email_code' => $code])->assertUnprocessable();
        $this->deleteJson('/api/v2/users/'.$first->id, ['email_code' => $code])->assertNoContent();
        $this->assertNotSoftDeleted('users', ['id' => $second->id]);
    }

    public function test_both_enabled_factors_are_required_for_one_delete(): void
    {
        $this->mail();
        $actor = $this->actor(totp: true, email: true);
        $target = User::factory()->create(['role' => 'customer_user']);
        $this->postJson('/api/v2/users/'.$target->id.'/deletion/otp')->assertOk();
        $emailCode = $this->queuedCode();
        $totpCode = $this->code($actor->mfa_totp_secret);
        $this->deleteJson('/api/v2/users/'.$target->id, ['totp_code' => $totpCode])->assertUnprocessable()->assertJsonValidationErrors('email_code');
        $this->deleteJson('/api/v2/users/'.$target->id, ['email_code' => $emailCode])->assertUnprocessable()->assertJsonValidationErrors('totp_code');
        $this->deleteJson('/api/v2/users/'.$target->id, ['totp_code' => $totpCode, 'email_code' => $emailCode])->assertNoContent();
    }
}
